<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentPayment;
use App\Models\Bonus;
use App\Models\BonusStatus;
use App\Models\PaymentStatus;

class PaymentService
{
    public function calculatePaymentTotal(array $bonuses): float
    {
        return array_sum(array_map(function ($bonus) {
            $bonus = $bonus instanceof Bonus ? $bonus : Bonus::find($bonus);

            return $bonus ? FinancialLedger::cents($bonus->commission_amount) : 0;
        }, $bonuses)) / 100;
    }

    public function createPayment(int $userId, array $bonusIds, int $methodId, ?string $referenceNumber = null): AgentPayment
    {
        return FinancialLedger::transaction(function () use ($userId, $bonusIds, $methodId, $referenceNumber) {
            $available = app(BonusPaymentService::class)->getAvailableBonuses($userId, 'agent', true)->whereIn('id', $bonusIds);
            if (empty($bonusIds) || $available->count() !== count($bonusIds)) {
                throw new \InvalidArgumentException('Некоторые бонусы недоступны, уже зарезервированы или выплачены.');
            }
            $payment = AgentPayment::create(['agent_id' => $userId, 'total_amount' => $this->calculatePaymentTotal($available->all()),
                'payment_date' => now(), 'reference_number' => $referenceNumber, 'status_id' => PaymentStatus::pendingId(), 'method_id' => $methodId]);
            $payment->bonuses()->attach($available->pluck('id'));

            return $payment;
        });
    }

    public function completePayment(AgentPayment $payment): AgentPayment
    {
        return FinancialLedger::transaction(function () use ($payment) {
            $payment = AgentPayment::with('status')->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status->code === 'completed') {
                return $payment;
            }
            if ($payment->status->code !== 'pending') {
                throw new \App\Exceptions\FinancialException('После отмены создайте новую выплату.');
            }
            $bonuses = $payment->bonuses()->get();
            if ($bonuses->isEmpty() || FinancialLedger::cents($this->calculatePaymentTotal($bonuses->all())) !== FinancialLedger::cents($payment->total_amount)) {
                throw new \App\Exceptions\FinancialException('Сумма выплаты не обеспечена бонусами.');
            }
            foreach ($bonuses as $bonus) {
                if ((int) $bonus->user_id !== (int) $payment->agent_id || ! app(BonusPaymentService::class)->isBonusAvailableForPayment($bonus)
                    || $bonus->paymentRequestLinks()->exists()
                    || $bonus->payments()->where('agent_payments.id', '!=', $payment->id)->whereHas('status', fn ($q) => $q->whereIn('code', ['pending', 'completed']))->exists()) {
                    throw new \App\Exceptions\FinancialException('Бонус больше не доступен для этой выплаты.');
                }
                $bonus->update(['status_id' => BonusStatus::paidId(), 'paid_at' => now()]);
            }
            $payment->update(['status_id' => PaymentStatus::completedId(), 'payment_date' => now()]);

            return $payment->fresh(['status', 'bonuses']);
        });
    }

    public function failPayment(AgentPayment $payment): AgentPayment
    {
        return FinancialLedger::transaction(function () use ($payment) {
            $payment = AgentPayment::with('status')->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status->code === 'failed') {
                return $payment;
            }
            if ($payment->status->code === 'completed') {
                foreach ($payment->bonuses()->get() as $bonus) {
                    if ($bonus->paymentRequestLinks()->exists() || $bonus->payments()->where('agent_payments.id', '!=', $payment->id)->whereHas('status', fn ($q) => $q->whereIn('code', ['pending', 'completed']))->exists()) {
                        throw new \App\Exceptions\FinancialException('Бонус связан с другой выплатой.');
                    }
                    $bonus->update(['status_id' => BonusStatus::pendingId(), 'paid_at' => null]);
                }
            }
            $payment->update(['status_id' => PaymentStatus::failedId()]);

            return $payment->fresh(['status', 'bonuses']);
        });
    }

    public function getAvailableBonusesForAgent(int $userId)
    {
        return app(BonusPaymentService::class)->getAvailableBonuses($userId, 'agent');
    }
}
