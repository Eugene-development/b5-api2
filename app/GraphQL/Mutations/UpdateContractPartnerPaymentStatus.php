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
            $contract->save();

            // Обновляем статус бонуса
            $bonusService = app(BonusService::class);
            $bonusService->handleContractPartnerPaymentStatusChange($contract, $statusCode);

            return $contract->load(['project', 'company', 'partnerPaymentStatus']);
        });
    }
}
