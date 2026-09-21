<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bonus;
use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentRequestBonus;
use App\Models\BonusStatus;
use Illuminate\Database\Eloquent\Collection;

class BonusPaymentService
{
    public function getAvailableBonuses(int $userId, ?string $recipientType = null, bool $lockForUpdate = false): Collection
    {
        $query = Bonus::where('user_id', $userId)->whereNull('paid_at')
            ->whereDoesntHave('paymentRequestLinks')
            ->whereDoesntHave('payments', fn ($q) => $q->whereHas('status', fn ($s) => $s->whereIn('code', ['pending', 'completed'])))
            ->where('commission_amount', '>', 0);
        if ($recipientType === 'agent') {
            $query->whereIn('recipient_type', ['agent', 'referrer']);
        } elseif ($recipientType !== null) {
            $query->where('recipient_type', $recipientType);
        }
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->with(['status', 'contract.status', 'contract.partnerPaymentStatus', 'contract.project.status', 'order.status', 'order.project.status'])
            ->orderBy('accrued_at')->orderBy('id')->get()
            ->filter(fn (Bonus $bonus) => $this->isBonusAvailableForPayment($bonus))->values();
    }

    /** Eligibility of the source; reservations are checked separately. */
    public function isBonusAvailableForPayment(Bonus $bonus): bool
    {
        if ($bonus->paid_at !== null || FinancialLedger::cents($bonus->commission_amount) <= 0 || $bonus->status?->code === 'cancelled') {
            return false;
        }
        $source = $bonus->contract_id ? $bonus->contract : $bonus->order;
        if (! $source || ! $source->is_active || ! $source->project?->is_active || $source->project->status?->slug === 'client-refused') {
            return false;
        }

        return $bonus->contract_id
            ? $source->status?->slug === 'completed' && $source->partnerPaymentStatus?->code === 'paid'
            : $source->status?->slug === 'delivered';
    }

    public function calculateAvailableBalance(int $userId, ?string $recipientType = null, bool $lockForUpdate = false): float
    {
        return $this->getAvailableBonuses($userId, $recipientType, $lockForUpdate)
            ->sum(fn ($bonus) => FinancialLedger::cents($bonus->commission_amount)) / 100;
    }

    /** Split before reserving: each reserved row represents its exact amount. */
    private function split(Bonus $bonus, int $covered): void
    {
        $remaining = FinancialLedger::cents($bonus->commission_amount) - $covered;
        if ($remaining <= 0) {
            return;
        }
        $remainder = $bonus->replicate();
        $remainder->commission_amount = $remaining / 100;
        $remainder->paid_at = null;
        $remainder->status_id = BonusStatus::pendingId();
        $remainder->save();
        $bonus->commission_amount = $covered / 100;
        $bonus->save();
    }

    public function linkBonusesToPaymentRequest(BonusPaymentRequest $request, int $userId, float $amount, ?string $recipientType = null): array
    {
        return FinancialLedger::transaction(function () use ($request, $userId, $amount, $recipientType) {
            $remaining = FinancialLedger::cents($amount);
            if ($remaining <= 0 || $request->linkedBonuses()->exists()) {
                throw new \App\Exceptions\FinancialException('Некорректная сумма или заявка уже зарезервирована.');
            }
            $linked = [];
            foreach ($this->getAvailableBonuses($userId, $recipientType, true) as $bonus) {
                if ($remaining === 0) {
                    break;
                }
                $covered = min(FinancialLedger::cents($bonus->commission_amount), $remaining);
                $this->split($bonus, $covered);
                BonusPaymentRequestBonus::create(['payment_request_id' => $request->id, 'bonus_id' => $bonus->id, 'covered_amount' => $covered / 100]);
                $linked[] = ['bonus' => $bonus, 'covered_amount' => $covered / 100, 'is_fully_covered' => true];
                $remaining -= $covered;
            }
            if ($remaining !== 0) {
                throw new \App\Exceptions\FinancialException('Недостаточно доступных бонусов для резервирования выплаты');
            }

            return $linked;
        });
    }

    public function settleBonuses(BonusPaymentRequest $request): void
    {
        FinancialLedger::transaction(function () use ($request) {
            $links = $request->linkedBonuses()->with('bonus')->get();
            if ($links->isEmpty() || $links->sum(fn ($l) => FinancialLedger::cents($l->covered_amount)) !== FinancialLedger::cents($request->amount)) {
                throw new \App\Exceptions\FinancialException('Сумма заявки не обеспечена бонусами.');
            }
            foreach ($links as $link) {
                $bonus = $link->bonus?->fresh();
                $covered = FinancialLedger::cents($link->covered_amount);
                $allowedTypes = $request->requester_type === 'curator' ? ['curator'] : ['agent', 'referrer'];
                if (! $bonus || (int) $bonus->user_id !== (int) $request->agent_id || ! in_array($bonus->recipient_type, $allowedTypes, true)
                    || ! $this->isBonusAvailableForPayment($bonus) || $covered <= 0 || $covered > FinancialLedger::cents($bonus->commission_amount)
                    || $bonus->payments()->whereHas('status', fn ($q) => $q->whereIn('code', ['pending', 'completed']))->exists()) {
                    throw new \App\Exceptions\FinancialException('Бонус больше не доступен или сумма его покрытия изменилась.');
                }
                // Supports existing partial reservations created before this release.
                $this->split($bonus, $covered);
                $bonus->update(['status_id' => BonusStatus::paidId(), 'paid_at' => now()]);
            }
        });
    }

    public function rollbackSettlement(BonusPaymentRequest $request): void
    {
        FinancialLedger::transaction(function () use ($request) {
            foreach ($request->linkedBonuses()->with('bonus')->get() as $link) {
                $bonus = $link->bonus;
                if (! $bonus || FinancialLedger::cents($bonus->commission_amount) !== FinancialLedger::cents($link->covered_amount)) {
                    throw new \App\Exceptions\FinancialException('Нельзя откатить выплату с повреждённым покрытием.');
                }
                // Never merge fragments: another request may own any other fragment.
                $bonus->update(['status_id' => BonusStatus::pendingId(), 'paid_at' => null]);
            }
        });
    }

    public function isSettled(BonusPaymentRequest $request): bool
    {
        $links = $request->linkedBonuses()->with('bonus')->get();

        return $links->isNotEmpty() && $links->every(fn ($l) => $l->bonus?->paid_at !== null);
    }
}
