<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use Carbon\Carbon;

final readonly class UpdateBonusStatus
{
    /**
     * Update bonus status.
     *
     * При изменении статуса бонуса:
     * - Если статус меняется на 'paid', устанавливается paid_at
     * - Если статус меняется на 'available_for_payment', устанавливается available_at
     * - Если статус меняется НЕ на 'paid', paid_at очищается
     * - При возврате с 'paid' на другой статус, проверяются условия доступности
     *   и available_at восстанавливается если условия выполнены
     *
     * @param  null  $_
     * @param  array  $args
     * @return AgentBonus
     */
    public function __invoke(null $_, array $args): AgentBonus
    {
        $bonus = AgentBonus::with(['contract.status', 'contract.partnerPaymentStatus', 'order.status'])->findOrFail($args['bonus_id']);
        $status = BonusStatus::where('code', $args['status_code'])->firstOrFail();

        $bonus->status_id = $status->id;

        // Set paid_at date when status changes to paid
        if ($args['status_code'] === 'paid' && !$bonus->paid_at) {
            $bonus->paid_at = Carbon::now();
        }

        // Set available_at date when status changes to available_for_payment
        if ($args['status_code'] === 'available_for_payment' && !$bonus->available_at) {
            $bonus->available_at = Carbon::now();
        }

        // Clear paid_at if status is not paid
        if ($args['status_code'] !== 'paid') {
            $bonus->paid_at = null;
            
            // При возврате с 'paid' на другой статус, проверяем условия доступности
            // и восстанавливаем available_at если условия выполнены
            if ($bonus->available_at === null) {
                $shouldBeAvailable = $this->checkBonusAvailabilityConditions($bonus);
                if ($shouldBeAvailable) {
                    $bonus->available_at = Carbon::now();
                }
            }
        }

        $bonus->save();
        $bonus->load('status');

        return $bonus;
    }

    /**
     * Проверить условия доступности бонуса к выплате.
     *
     * Для договоров: статус договора = 'completed' И статус оплаты партнёром = 'paid' И договор активен
     * Для заказов: статус заказа = 'delivered' И заказ активен
     *
     * @param AgentBonus $bonus
     * @return bool
     */
    private function checkBonusAvailabilityConditions(AgentBonus $bonus): bool
    {
        // Для договоров
        if ($bonus->contract_id && $bonus->contract) {
            $contract = $bonus->contract;
            
            $isContractCompleted = $contract->status && $contract->status->slug === 'completed';
            $isPartnerPaid = $contract->partnerPaymentStatus && $contract->partnerPaymentStatus->code === 'paid';
            $isContractActive = $contract->is_active === true;
            
            return $isContractCompleted && $isPartnerPaid && $isContractActive;
        }
        
        // Для заказов
        if ($bonus->order_id && $bonus->order) {
            $order = $bonus->order;
            
            $isOrderDelivered = $order->status && $order->status->slug === 'delivered';
            $isOrderActive = $order->is_active === true;
            
            return $isOrderDelivered && $isOrderActive;
        }
        
        return false;
    }
}
