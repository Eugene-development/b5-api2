<?php

namespace App\Services;

use App\Models\Bonus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для управления бонусами.
 *
 * Управляет жизненным циклом бонусов для агентов, кураторов и рефереров:
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
     * Создаёт бонусы для агента и куратора.
     *
     * @param Contract $contract
     * @return Bonus|null Возвращает агентский бонус
     */
    public function createBonusForContract(Contract $contract): ?Bonus
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

        // Создаём бонус агента
        $agentCommission = $this->calculateCommission(
            (float) $contract->contract_amount,
            (float) $contract->agent_percentage
        );

        $agentBonus = Bonus::create([
            'user_id' => $agentId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => $agentCommission,
            'percentage' => $contract->agent_percentage,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_AGENT,
            'bonus_type' => 'agent',
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => null,
        ]);

        // Создаём бонус куратора
        $this->createCuratorBonusForContract($contract);

        // Создаём реферальный бонус для реферера агента
        $this->referralBonusService->createReferralBonusForContract($contract, $agentId);

        return $agentBonus;
    }

    /**
     * Создать бонус куратора для договора.
     *
     * @param Contract $contract
     * @return Bonus|null
     */
    public function createCuratorBonusForContract(Contract $contract): ?Bonus
    {
        // Получаем curator_id из проекта
        $curatorId = $this->getCuratorIdFromProject($contract->project_id);
        if (!$curatorId) {
            return null;
        }

        $curatorCommission = $this->calculateCommission(
            (float) $contract->contract_amount,
            (float) $contract->curator_percentage
        );

        return Bonus::create([
            'user_id' => $curatorId,
            'contract_id' => $contract->id,
            'order_id' => null,
            'commission_amount' => $curatorCommission,
            'percentage' => $contract->curator_percentage,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_CURATOR,
            'bonus_type' => null,
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => null,
        ]);
    }

    /**
     * Создать бонус для закупки.
     * Создаёт бонусы для агента и куратора.
     *
     * @param Order $order
     * @return Bonus|null Возвращает агентский бонус
     */
    public function createBonusForOrder(Order $order): ?Bonus
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

        $agentCommission = $this->calculateCommission(
            (float) $order->order_amount,
            (float) $order->agent_percentage
        );

        $agentBonus = Bonus::create([
            'user_id' => $agentId,
            'contract_id' => null,
            'order_id' => $order->id,
            'commission_amount' => $agentCommission,
            'percentage' => $order->agent_percentage,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_AGENT,
            'bonus_type' => 'agent',
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => null,
        ]);

        // Создаём бонус куратора
        $this->createCuratorBonusForOrder($order);

        // Создаём реферальный бонус для реферера агента
        $this->referralBonusService->createReferralBonusForOrder($order, $agentId);

        return $agentBonus;
    }

    /**
     * Создать бонус куратора для закупки.
     *
     * @param Order $order
     * @return Bonus|null
     */
    public function createCuratorBonusForOrder(Order $order): ?Bonus
    {
        // Получаем curator_id из проекта
        $curatorId = $this->getCuratorIdFromProject($order->project_id);
        if (!$curatorId) {
            return null;
        }

        $curatorCommission = $this->calculateCommission(
            (float) $order->order_amount,
            (float) $order->curator_percentage
        );

        return Bonus::create([
            'user_id' => $curatorId,
            'contract_id' => null,
            'order_id' => $order->id,
            'commission_amount' => $curatorCommission,
            'percentage' => $order->curator_percentage,
            'status_id' => BonusStatus::pendingId(),
            'recipient_type' => Bonus::RECIPIENT_CURATOR,
            'bonus_type' => null,
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => null,
        ]);
    }

    /**
     * Обновить бонусы при изменении договора.
     *
     * @param Contract $contract
     * @return void
     */
    public function updateBonusesForContract(Contract $contract): void
    {
        // Обновляем агентский бонус
        $agentBonus = $contract->agentBonus;
        if ($agentBonus) {
            $this->recalculateBonus($agentBonus);
        }

        // Обновляем кураторский бонус
        $curatorBonus = $contract->curatorBonus;
        if ($curatorBonus) {
            $this->recalculateCuratorBonus($curatorBonus, $contract);
        }
    }

    /**
     * Обновить бонусы при изменении закупки.
     *
     * @param Order $order
     * @return void
     */
    public function updateBonusesForOrder(Order $order): void
    {
        // Обновляем агентский бонус
        $agentBonus = $order->agentBonus;
        if ($agentBonus) {
            $this->recalculateBonus($agentBonus);
        }

        // Обновляем кураторский бонус
        $curatorBonus = $order->curatorBonus;
        if ($curatorBonus) {
            $this->recalculateCuratorBonusForOrder($curatorBonus, $order);
        }
    }

    /**
     * Пересчитать бонус при изменении суммы или процента.
     *
     * @param Bonus $bonus
     * @return Bonus
     */
    public function recalculateBonus(Bonus $bonus): Bonus
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

        $bonus->percentage = $percentage;
        $bonus->save();
        return $bonus;
    }

    /**
     * Пересчитать бонус куратора для договора.
     *
     * @param Bonus $bonus
     * @param Contract $contract
     * @return Bonus
     */
    public function recalculateCuratorBonus(Bonus $bonus, Contract $contract): Bonus
    {
        if (!$contract->is_active) {
            $bonus->commission_amount = 0;
        } else {
            $bonus->commission_amount = $this->calculateCommission(
                (float) $contract->contract_amount,
                (float) $contract->curator_percentage
            );
        }

        $bonus->percentage = $contract->curator_percentage;
        $bonus->save();
        return $bonus;
    }

    /**
     * Пересчитать бонус куратора для закупки.
     *
     * @param Bonus $bonus
     * @param Order $order
     * @return Bonus
     */
    public function recalculateCuratorBonusForOrder(Bonus $bonus, Order $order): Bonus
    {
        if (!$order->is_active) {
            $bonus->commission_amount = 0;
        } else {
            $bonus->commission_amount = $this->calculateCommission(
                (float) $order->order_amount,
                (float) $order->curator_percentage
            );
        }

        $bonus->percentage = $order->curator_percentage;
        $bonus->save();
        return $bonus;
    }


    /**
     * Перевести бонус в статус "Доступно к выплате".
     *
     * @param Bonus $bonus
     * @return Bonus
     */
    public function markBonusAsAvailable(Bonus $bonus): Bonus
    {
        $bonus->status_id = BonusStatus::availableForPaymentId();
        $bonus->available_at = now();
        $bonus->save();
        return $bonus;
    }

    /**
     * Откатить бонус в статус "Начислено".
     *
     * @param Bonus $bonus
     * @return Bonus
     */
    public function revertBonusToAccrued(Bonus $bonus): Bonus
    {
        $bonus->status_id = BonusStatus::accruedId();
        $bonus->available_at = null;
        $bonus->save();
        return $bonus;
    }

    /**
     * Получить статистику бонусов пользователя (агентские + кураторские + реферальные).
     *
     * Учитывает все типы бонусов:
     * - agent: бонусы за собственные договора и заказы агента
     * - curator: бонусы за курирование проектов
     * - referral: бонусы за договора и заказы рефералов агента
     *
     * @param int $userId
     * @param array|null $filters
     * @return array
     */
    public function getAgentStats(int $userId, ?array $filters = null): array
    {
        $query = Bonus::where('user_id', $userId)
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
            // Фильтр по типу получателя (agent/curator/referrer)
            if (!empty($filters['recipient_type'])) {
                $query->where('recipient_type', $filters['recipient_type']);
            }
            // Фильтр по типу бонуса (agent/referral) - legacy
            if (!empty($filters['bonus_type'])) {
                if ($filters['bonus_type'] === 'agent') {
                    $query->where(function ($q) {
                        $q->where('bonus_type', 'agent')
                          ->orWhereNull('bonus_type');
                    });
                } elseif ($filters['bonus_type'] === 'referral') {
                    $query->where('bonus_type', 'referral');
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

        // Получаем сумму запрошенных к выплате заявок (статус = 'requested')
        $totalRequested = $this->getRequestedPaymentsAmount($userId);

        // Вычитаем запрошенную сумму из доступного баланса
        $adjustedAvailable = max(0, $totalAvailable - $totalRequested);

        return [
            'total_pending' => round($totalPending, 2),
            'total_available' => round($adjustedAvailable, 2),
            'total_requested' => round($totalRequested, 2),
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
     * Обновляет ВСЕ бонусы договора (агентский + кураторский + реферальный).
     *
     * @param Contract $contract
     * @param string $newStatusCode
     * @return void
     */
    public function handleContractPartnerPaymentStatusChange(Contract $contract, string $newStatusCode): void
    {
        // Получаем все бонусы договора
        $bonuses = $contract->bonuses;

        foreach ($bonuses as $bonus) {
            // Проверяем оба условия для доступности бонуса
            $this->checkAndUpdateContractBonusAvailability($contract, $bonus);
        }
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
     * Обновляет ВСЕ бонусы договора (агентский + кураторский + реферальный).
     *
     * @param Contract $contract
     * @param string $newStatusSlug
     * @return void
     */
    public function handleContractStatusChange(Contract $contract, string $newStatusSlug): void
    {
        // Получаем все бонусы договора
        $bonuses = $contract->bonuses;

        foreach ($bonuses as $bonus) {
            // Проверяем оба условия для доступности бонуса
            $this->checkAndUpdateContractBonusAvailability($contract, $bonus);
        }
    }

    /**
     * Проверить и обновить доступность бонуса для договора.
     *
     * Бонус становится доступным к выплате когда выполнены ОБА условия:
     * - is_contract_completed: Статус договора = 'completed' (Выполнен)
     * - is_partner_paid: Статус оплаты партнёром = 'paid' (Оплачено)
     *
     * @param Contract $contract
     * @param Bonus $bonus
     * @return void
     */
    private function checkAndUpdateContractBonusAvailability(Contract $contract, Bonus $bonus): void
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
     * Обновляет ВСЕ бонусы договора (агентский + кураторский + реферальный).
     *
     * @param Contract $contract
     * @return void
     */
    public function handleContractActiveChange(Contract $contract): void
    {
        // Получаем все бонусы договора
        $bonuses = $contract->bonuses;

        foreach ($bonuses as $bonus) {
            // Проверяем оба условия для доступности бонуса
            $this->checkAndUpdateContractBonusAvailability($contract, $bonus);
        }
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
     * Обновляет ВСЕ бонусы заказа (агентский + кураторский + реферальный).
     *
     * @param Order $order
     * @param string $newStatusSlug
     * @return void
     */
    public function handleOrderStatusChange(Order $order, string $newStatusSlug): void
    {
        // Получаем все бонусы заказа
        $bonuses = $order->bonuses;

        foreach ($bonuses as $bonus) {
            // Не трогаем уже оплаченные бонусы
            if ($bonus->paid_at !== null) {
                continue;
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
    }

    /**
     * Обработать изменение is_active для заказа.
     *
     * Для заказов НЕ требуется проверка оплаты партнёром.
     * Бонус доступен к выплате если заказ доставлен и активен.
     *
     * Обновляет ВСЕ бонусы заказа (агентский + кураторский + реферальный).
     *
     * @param Order $order
     * @return void
     */
    public function handleOrderActiveChange(Order $order): void
    {
        // Получаем все бонусы заказа
        $bonuses = $order->bonuses;

        foreach ($bonuses as $bonus) {
            // Не трогаем уже оплаченные бонусы
            if ($bonus->paid_at !== null) {
                continue;
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

    /**
     * Получить ID куратора из проекта.
     *
     * @param string $projectId
     * @return int|null
     */
    private function getCuratorIdFromProject(string $projectId): ?int
    {
        // Ищем куратора в таблице projects (поле curator_id)
        $project = DB::table('projects')
            ->where('id', $projectId)
            ->first();

        if ($project && isset($project->curator_id)) {
            return $project->curator_id;
        }

        return null;
    }

    /**
     * Получить сумму запрошенных к выплате заявок пользователя.
     *
     * Учитывает только заявки со статусом 'requested' или 'approved' (не выплаченные).
     *
     * @param int $userId
     * @param string|null $requesterType Тип запрашивающего (agent, curator)
     * @return float
     */
    public function getRequestedPaymentsAmount(int $userId, ?string $requesterType = null): float
    {
        $query = \App\Models\BonusPaymentRequest::where('agent_id', $userId)
            ->whereHas('status', function ($q) {
                $q->whereIn('code', ['requested', 'approved']);
            });

        if ($requesterType !== null) {
            $query->where('requester_type', $requesterType);
        }

        $requestedAmount = $query->sum('amount');

        return (float) $requestedAmount;
    }
}
