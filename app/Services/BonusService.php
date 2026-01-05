<?php

namespace App\Services;

use App\Models\AgentBonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для управления бонусами агентов.
 *
 * Управляет жизненным циклом бонусов:
 * - Создание бонуса при создании договора/закупки
 * - Пересчёт при изменении суммы/процента
 * - Переход в статус "доступно к выплате":
 *   - Для договоров: при выполнении ОБОИХ условий:
 *     1. is_contract_completed: статус договора = 'completed' (Выполнен)
 *     2. is_partner_paid: статус оплаты партнёром = 'paid' (Оплачено)
 *   - Для заказов: при доставке + is_active (без проверки оплаты партнёром)
 * - Откат статуса при изменении условий
 */
class BonusService
{
    protected ReferralBonusService $referralBonusService;

    public function __construct(?ReferralBonusService $referralBonusService = null)
    {
        $this->referralBonusService = $referralBonusService ?? new ReferralBonusService();
    }
    /**
     * Рассчитать сумму комиссии.
     *
     * @param float $amount Сумма договора/закупки
     * @param float $percentage Процент агента (0-100)
     * @return float Сумма комиссии
     */
    public function calculateCommission(float $amount, float $percentage): float
    {
        if ($amount <= 0 || $percentage < 0 || $percentage > 100) {
            return 0.0;
        }
        return round($amount * $percentage / 100, 2);
    }

    /**
     * Создать бонус для договора.
     *
     * @param Contract $contract
     * @return AgentBonus|null
     */
    public function createBonusForContract(Contract $contract): ?AgentBonus
    {
        // Проверяем условия создания бонуса
        // Сумма 0 допустима - бонус будет создан с нулевой комиссией
        if (!$contract->is_active || $contract->contract_amount === null) {
            return null;
        }

        // Получаем agent_id из проекта
        $agentId = $this->getAgentIdFromProject($contract->project_id);
        if (!$agentId) {
            return null;
        }


        $commissionAmount = $this->calculateCommission(
            (float) $contract->contract_amount,
            (float) $contract->agent_percentage
        );

        $agentBonus = AgentBonus::create([
            'agent_id' => $agentId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => $commissionAmount,
            'status_id' => BonusStatus::pendingId(),
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'bonus_type' => 'agent',
            'referral_user_id' => null,
        ]);

        // Создаём реферальный бонус для реферера агента
        $this->referralBonusService->createReferralBonusForContract($contract, $agentId);

        return $agentBonus;
    }

    /**
     * Создать бонус для закупки.
     *
     * @param Order $order
     * @return AgentBonus|null
     */
    public function createBonusForOrder(Order $order): ?AgentBonus
    {
        // Проверяем условия создания бонуса
        if (!$order->is_active || !$order->order_amount || $order->order_amount <= 0) {
            return null;
        }

        // Получаем agent_id из проекта
        $agentId = $this->getAgentIdFromProject($order->project_id);
        if (!$agentId) {
            return null;
        }

        $commissionAmount = $this->calculateCommission(
            (float) $order->order_amount,
            (float) $order->agent_percentage
        );

        $agentBonus = AgentBonus::create([
            'agent_id' => $agentId,
            'contract_id' => null,
            'order_id' => $order->id,
            'commission_amount' => $commissionAmount,
            'status_id' => BonusStatus::pendingId(),
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'bonus_type' => 'agent',
            'referral_user_id' => null,
        ]);

        // Создаём реферальный бонус для реферера агента
        $this->referralBonusService->createReferralBonusForOrder($order, $agentId);

        return $agentBonus;
    }

    /**
     * Пересчитать бонус при изменении суммы или процента.
     *
     * @param AgentBonus $bonus
     * @return AgentBonus
     */
    public function recalculateBonus(AgentBonus $bonus): AgentBonus
    {
        $amount = 0.0;
        $percentage = 0.0;
        $isActive = true;

        if ($bonus->contract_id && $bonus->contract) {
            $amount = (float) $bonus->contract->contract_amount;
            $percentage = (float) $bonus->contract->agent_percentage;
            $isActive = $bonus->contract->is_active;
        } elseif ($bonus->order_id && $bonus->order) {
            $amount = (float) $bonus->order->order_amount;
            $percentage = (float) $bonus->order->agent_percentage;
            $isActive = $bonus->order->is_active;
        }

        // Если сущность деактивирована, обнуляем комиссию
        if (!$isActive) {
            $bonus->commission_amount = 0;
        } else {
            $bonus->commission_amount = $this->calculateCommission($amount, $percentage);
        }

        $bonus->save();
        return $bonus;
    }


    /**
     * Перевести бонус в статус "Доступно к выплате".
     *
     * @param AgentBonus $bonus
     * @return AgentBonus
     */
    public function markBonusAsAvailable(AgentBonus $bonus): AgentBonus
    {
        $bonus->status_id = BonusStatus::availableForPaymentId();
        $bonus->available_at = now();
        $bonus->save();
        return $bonus;
    }

    /**
     * Откатить бонус в статус "Начислено".
     *
     * @param AgentBonus $bonus
     * @return AgentBonus
     */
    public function revertBonusToAccrued(AgentBonus $bonus): AgentBonus
    {
        $bonus->status_id = BonusStatus::accruedId();
        $bonus->available_at = null;
        $bonus->save();
        return $bonus;
    }

    /**
     * Получить статистику бонусов агента.
     *
     * @param int $agentId
     * @param array|null $filters
     * @return array
     */
    public function getAgentStats(int $agentId, ?array $filters = null): array
    {
        $query = AgentBonus::where('agent_id', $agentId)
            ->with(['contract.status', 'contract.partnerPaymentStatus', 'order.status']);

        // Фильтруем бонусы: показываем только те, где договор в статусе "Заключён" или далее
        // (т.е. исключаем договоры в статусе "Обработка" / preparing)
        $query->where(function ($q) {
            $q->whereHas('contract', function ($contractQuery) {
                $contractQuery->whereHas('status', function ($statusQuery) {
                    // Исключаем статус "Обработка" (preparing)
                    $statusQuery->where('slug', '!=', 'preparing');
                });
            })
            // Или это бонус от заказа (не от договора)
            ->orWhereNotNull('order_id');
        });

        // Применяем фильтры если указаны
        if ($filters) {
            if (!empty($filters['date_from'])) {
                $query->where('accrued_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->where('accrued_at', '<=', $filters['date_to']);
            }
            if (!empty($filters['source_type'])) {
                if ($filters['source_type'] === 'contract') {
                    $query->whereNotNull('contract_id');
                } elseif ($filters['source_type'] === 'order') {
                    $query->whereNotNull('order_id');
                }
            }
        }

        $bonuses = $query->get();

        $totalPending = 0.0;
        $totalAvailable = 0.0;
        $totalPaid = 0.0;

        foreach ($bonuses as $bonus) {
            $amount = (float) $bonus->commission_amount;

            // Выплачено: бонусы, которые уже выплачены
            if ($bonus->paid_at !== null) {
                $totalPaid += $amount;
                continue;
            }

            // Определяем доступность бонуса к выплате
            $isAvailable = false;
            
            if ($bonus->contract_id && $bonus->contract) {
                // Для договоров: проверяем is_contract_completed И is_partner_paid
                $contract = $bonus->contract;
                $isContractCompleted = $contract->status && $contract->status->slug === 'completed';
                $isPartnerPaid = $contract->partnerPaymentStatus && $contract->partnerPaymentStatus->code === 'paid';
                $isContractActive = $contract->is_active === true;
                
                $isAvailable = $isContractCompleted && $isPartnerPaid && $isContractActive;
            } elseif ($bonus->order_id && $bonus->order) {
                // Для заказов: проверяем статус доставки
                $order = $bonus->order;
                $isOrderDelivered = $order->status && $order->status->slug === 'delivered';
                $isOrderActive = $order->is_active === true;
                
                $isAvailable = $isOrderDelivered && $isOrderActive;
            }

            if ($isAvailable) {
                $totalAvailable += $amount;
            } else {
                $totalPending += $amount;
            }
        }

        return [
            'total_pending' => round($totalPending, 2),
            'total_available' => round($totalAvailable, 2),
            'total_paid' => round($totalPaid, 2),
        ];
    }


    /**
     * Обработать изменение статуса оплаты партнёром для договора.
     *
     * Бонус становится доступным к выплате когда выполнены ОБА условия:
     * - Статус договора = 'completed' (Выполнен)
     * - Статус оплаты партнёром = 'paid' (Оплачено)
     *
     * @param Contract $contract
     * @param string $newStatusCode
     * @return void
     */
    public function handleContractPartnerPaymentStatusChange(Contract $contract, string $newStatusCode): void
    {
        $bonus = $contract->agentBonus;
        if (!$bonus) {
            return;
        }

        // Проверяем оба условия для доступности бонуса
        $this->checkAndUpdateContractBonusAvailability($contract, $bonus);
    }

    /**
     * Обработать изменение статуса оплаты партнёром для закупки.
     *
     * ПРИМЕЧАНИЕ: Для заказов статус оплаты партнёром не используется.
     * Бонус переходит в "Доступно" только при доставке заказа.
     * Этот метод оставлен для обратной совместимости, но не выполняет действий.
     *
     * @param Order $order
     * @param string $newStatusCode
     * @return void
     * @deprecated Для заказов используйте handleOrderStatusChange
     */
    public function handleOrderPartnerPaymentStatusChange(Order $order, string $newStatusCode): void
    {
        // Для заказов статус оплаты партнёром не влияет на бонусы.
        // Бонус переходит в "Доступно" только при доставке заказа (handleOrderStatusChange).
    }

    /**
     * Обработать изменение статуса договора.
     *
     * Бонус становится доступным к выплате когда выполнены ОБА условия:
     * - Статус договора = 'completed' (Выполнен)
     * - Статус оплаты партнёром = 'paid' (Оплачено)
     *
     * @param Contract $contract
     * @param string $newStatusSlug
     * @return void
     */
    public function handleContractStatusChange(Contract $contract, string $newStatusSlug): void
    {
        $bonus = $contract->agentBonus;
        if (!$bonus) {
            return;
        }

        // Проверяем оба условия для доступности бонуса
        $this->checkAndUpdateContractBonusAvailability($contract, $bonus);
    }

    /**
     * Проверить и обновить доступность бонуса для договора.
     *
     * Бонус становится доступным к выплате когда выполнены ОБА условия:
     * - is_contract_completed: Статус договора = 'completed' (Выполнен)
     * - is_partner_paid: Статус оплаты партнёром = 'paid' (Оплачено)
     *
     * @param Contract $contract
     * @param AgentBonus $bonus
     * @return void
     */
    private function checkAndUpdateContractBonusAvailability(Contract $contract, AgentBonus $bonus): void
    {
        // Не трогаем уже оплаченные бонусы
        if ($bonus->paid_at !== null) {
            return;
        }

        // Загружаем связи если не загружены
        if (!$contract->relationLoaded('status')) {
            $contract->load('status');
        }
        if (!$contract->relationLoaded('partnerPaymentStatus')) {
            $contract->load('partnerPaymentStatus');
        }

        // Генерируем два булевых значения
        $isContractCompleted = $contract->status && $contract->status->slug === 'completed';
        $isPartnerPaid = $contract->partnerPaymentStatus && $contract->partnerPaymentStatus->code === 'paid';
        $isContractActive = $contract->is_active === true;

        // Бонус доступен только если ОБА условия выполнены И договор активен
        if ($isContractCompleted && $isPartnerPaid && $isContractActive) {
            // Переводим бонус в "Доступно к выплате" (устанавливаем available_at)
            if ($bonus->available_at === null) {
                $this->markBonusAsAvailable($bonus);
            }
        } else {
            // Если хотя бы одно условие не выполнено - очищаем available_at
            if ($bonus->available_at !== null) {
                $this->revertBonusToAccrued($bonus);
            }
        }
    }

    /**
     * Обработать изменение is_active для договора.
     *
     * Бонус становится доступным к выплате когда выполнены ОБА условия:
     * - Статус договора = 'completed' (Выполнен)
     * - Статус оплаты партнёром = 'paid' (Оплачено)
     * - Договор активен (is_active = true)
     *
     * @param Contract $contract
     * @return void
     */
    public function handleContractActiveChange(Contract $contract): void
    {
        $bonus = $contract->agentBonus;
        if (!$bonus) {
            return;
        }

        // Проверяем оба условия для доступности бонуса
        $this->checkAndUpdateContractBonusAvailability($contract, $bonus);
    }

    /**
     * Обработать изменение статуса заказа.
     *
     * Бонус переходит в статус "Доступно к выплате" при:
     * - Статус заказа = 'delivered' (Доставлен)
     * - Заказ активен (is_active = true)
     *
     * Для заказов НЕ требуется проверка оплаты партнёром,
     * так как компания сама организует продажу заказов.
     *
     * @param Order $order
     * @param string $newStatusSlug
     * @return void
     */
    public function handleOrderStatusChange(Order $order, string $newStatusSlug): void
    {
        $bonus = $order->agentBonus;
        if (!$bonus) {
            return;
        }

        // Не трогаем уже оплаченные бонусы
        if ($bonus->paid_at !== null) {
            return;
        }

        // Если заказ перешёл в статус "Доставлен"
        if ($newStatusSlug === 'delivered') {
            $isOrderActive = $order->is_active === true;

            if ($isOrderActive) {
                $this->markBonusAsAvailable($bonus);
            }
        } else {
            // Если заказ перешёл из "Доставлен" в другой статус - очищаем available_at
            if ($bonus->available_at !== null) {
                $this->revertBonusToAccrued($bonus);
            }
        }
    }

    /**
     * Обработать изменение is_active для заказа.
     *
     * Для заказов НЕ требуется проверка оплаты партнёром.
     * Бонус доступен к выплате если заказ доставлен и активен.
     *
     * @param Order $order
     * @return void
     */
    public function handleOrderActiveChange(Order $order): void
    {
        $bonus = $order->agentBonus;
        if (!$bonus) {
            return;
        }

        // Не трогаем уже оплаченные бонусы
        if ($bonus->paid_at !== null) {
            return;
        }

        if ($order->is_active) {
            // Заказ стал активным - проверяем только статус доставки
            $orderStatus = $order->status;
            $isOrderDelivered = $orderStatus && $orderStatus->slug === 'delivered';

            if ($isOrderDelivered) {
                $this->markBonusAsAvailable($bonus);
            }
        } else {
            // Заказ стал неактивным - очищаем available_at
            if ($bonus->available_at !== null) {
                $this->revertBonusToAccrued($bonus);
            }
        }
    }

    /**
     * Получить ID агента из проекта.
     *
     * @param string $projectId
     * @return int|null
     */
    private function getAgentIdFromProject(string $projectId): ?int
    {
        // Ищем агента в таблице projects (поле user_id)
        $project = DB::table('projects')
            ->where('id', $projectId)
            ->first();

        if ($project && isset($project->user_id)) {
            return $project->user_id;
        }

        // Альтернативно: ищем в связи project_user (если используется)
        $projectUser = DB::table('project_user')
            ->where('project_id', $projectId)
            ->first();

        if ($projectUser) {
            return $projectUser->user_id;
        }

        return null;
    }
}
