<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/** Reconciles the total entitlement while preserving paid and reserved fragments. */
final class BonusAccrualService
{
    public function sync(Contract|Order $source): void
    {
        FinancialLedger::transaction(function () use ($source) {
            $source = $source->fresh(['project.status', 'status']);
            $field = $source instanceof Contract ? 'contract_id' : 'order_id';
            $amount = (float) ($source->getAttributes()[$source instanceof Contract ? 'contract_amount' : 'order_amount'] ?? 0);
            $agent = $source->project?->user_id ?? DB::table('project_user')->where('project_id', $source->project_id)->where('role', 'agent')->value('user_id');
            $curator = DB::table('project_user')->where('project_id', $source->project_id)->where('role', 'curator')->value('user_id');
            $referrals = app(ReferralBonusService::class);
            $referrer = $agent ? $referrals->getReferrerId((int) $agent) : null;
            $targets = [];
            if ($agent) {
                $targets['agent:'.$agent] = [(int) $agent, 'agent', (float) $source->agent_percentage, null];
            }
            if ($curator) {
                $targets['curator:'.$curator] = [(int) $curator, 'curator', (float) $source->curator_percentage, null];
            }
            $existing = $source->bonuses()->orderBy('id')->get()->groupBy(fn ($b) => $b->recipient_type.':'.$b->user_id);
            if ($referrer && $referrer !== (int) $agent && ($referrals->isReferralProgramActive((int) $agent) || $existing->has('referrer:'.$referrer))) {
                $targets['referrer:'.$referrer] = [$referrer, 'referrer', ReferralBonusService::REFERRAL_COMMISSION_PERCENTAGE, (int) $agent];
            }
            // Keep historical beneficiaries; ownership mutations reject changing these sources.
            foreach ($existing as $key => $rows) {
                if (! isset($targets[$key])) {
                    $b = $rows->first();
                    $targets[$key] = [(int) $b->user_id, $b->recipient_type, (float) $b->percentage, $b->referral_user_id];
                }
            }
            $cancelled = $source->project?->status?->slug === 'client-refused'
                || in_array($source->status?->slug, ['rejected', 'terminated', 'returned'], true);
            foreach ($targets as $key => [$userId, $type, $percentage, $referralId]) {
                $rows = $existing->get($key, collect());
                $fixed = $rows->filter(fn ($b) => $b->paid_at !== null || FinancialLedger::reserved($b));
                $fixedAmount = $fixed->sum(fn ($b) => FinancialLedger::cents($b->commission_amount));
                // Deactivation affects eligibility, not the historical entitlement.
                $target = FinancialLedger::cents(app(BonusService::class)->calculateCommission($amount, $percentage));
                if ($target < $fixedAmount) {
                    throw new \App\Exceptions\FinancialException('Новая сумма бонуса меньше уже выплаченной или зарезервированной. Сначала отмените выплату.');
                }
                $free = $rows->reject(fn ($b) => $fixed->contains('id', $b->id));
                $remaining = $target - $fixedAmount;
                $freeBonus = $free->first();
                if (! $freeBonus && $remaining > 0) {
                    $freeBonus = new Bonus(['user_id' => $userId, $field => $source->id, 'recipient_type' => $type,
                        'bonus_type' => $type === 'referrer' ? 'referral' : $type, 'referral_user_id' => $referralId, 'accrued_at' => now()]);
                }
                if ($freeBonus) {
                    $freeBonus->fill(['commission_amount' => $remaining / 100, 'percentage' => $percentage,
                        'status_id' => $cancelled ? BonusStatus::cancelledId() : BonusStatus::pendingId()]);
                    $freeBonus->save();
                    foreach ($free->skip(1) as $extra) {
                        $extra->update(['commission_amount' => 0]);
                    }
                }
                foreach ($source->bonuses()->where('user_id', $userId)->where('recipient_type', $type)->whereNull('paid_at')->get() as $bonus) {
                    $bonus->status_id = $cancelled ? BonusStatus::cancelledId() : BonusStatus::pendingId();
                    $bonus->unsetRelation('status');
                    $eligible = app(BonusPaymentService::class)->isBonusAvailableForPayment($bonus);
                    $bonus->available_at = $eligible ? ($bonus->available_at ?? now()) : null;
                    $bonus->save();
                }
            }
        });
    }
}
