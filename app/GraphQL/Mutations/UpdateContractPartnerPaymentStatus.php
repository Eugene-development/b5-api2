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
            $contract = Contract::findOrFail($contractId);
            $contract->partner_payment_status_id = $status->id;

            // Устанавливаем дату оплаты при смене статуса на "paid"
            if ($statusCode === 'paid') {
                $contract->partner_payment_date = now()->toDateString();
            } elseif ($statusCode === 'pending') {
                // Сбрасываем дату при возврате в статус "ожидание"
                $contract->partner_payment_date = null;
            }

            $contract->save();

            // ПРИМЕЧАНИЕ: С упрощением статусов бонусов, статус оплаты партнёром
            // больше не влияет на статус бонуса. Бонус остаётся в статусе "Ожидание"
            // до момента выплаты агенту.

            return $contract->load(['project', 'company', 'partnerPaymentStatus']);
        });
    }
}
