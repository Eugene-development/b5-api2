<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Contract;
use App\Models\PartnerPaymentStatus;
use App\Services\BonusService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;

final readonly class UpdateContractPartnerPaymentStatus
{
    /**
     * Update partner payment status for a contract.
     *
     * При изменении статуса оплаты партнёром проверяется доступность бонуса.
     * Бонус становится доступным только если выполнены ОБА условия:
     * - is_contract_completed: статус договора = 'completed' (Выполнен)
     * - is_partner_paid: статус оплаты партнёром = 'paid' (Оплачено)
     *
     * @param  null  $_
     * @param  array  $args
     * @return Contract
     */
    public function __invoke(null $_, array $args): Contract
    {
        $contractId = $args['contract_id'];
        $statusCode = $args['status_code'];

        $status = PartnerPaymentStatus::findByCode($statusCode);
        if (!$status) {
            throw new Error("Неизвестный статус: {$statusCode}");
        }

        return DB::transaction(function () use ($contractId, $status, $statusCode) {
            $contract = Contract::with(['status', 'partnerPaymentStatus', 'agentBonus'])
                ->findOrFail($contractId);
            $contract->partner_payment_status_id = $status->id;

            // Устанавливаем дату оплаты при смене статуса на "paid"
            if ($statusCode === 'paid') {
                $contract->partner_payment_date = now()->toDateString();
            } elseif ($statusCode === 'pending') {
                // Сбрасываем дату при возврате в статус "ожидание"
                $contract->partner_payment_date = null;
            }

            $contract->save();

            // Обновляем статус бонуса на основе двух условий:
            // is_contract_completed И is_partner_paid
            $bonusService = app(BonusService::class);
            $bonusService->handleContractPartnerPaymentStatusChange($contract, $statusCode);

            return $contract->load(['project', 'company', 'partnerPaymentStatus']);
        });
    }
}
