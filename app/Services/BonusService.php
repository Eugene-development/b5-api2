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
        $this->referralBonusService = $referralBonusService ?? new ReferralBonusService;
    }

    /**
     * Рассчитать сумму комиссии.
     *
     * @param  float  $amount  Сумма договора/закупки
     * @param  float  $percentage  Процент агента (0-100)
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
     * @return Bonus|null Возвращает агентский бонус
     */
    public function createBonusForContract(Contract $contract): ?Bonus
    {
        app(BonusAccrualService::class)->sync($contract);

        return $contract->bonuses()->where('recipient_type', 'agent')->first();
    }

    /**
     * Создать бонус куратора для договора.
     */
    public function createCuratorBonusForContract(Contract $contract): ?Bonus
    {
        // Получаем curator_id из проекта
        $curatorId = $this->getCuratorIdFromProject($contract->project_id);
        if (! $curatorId) {
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
            'bonus_type' => 'curator',
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
     * @return Bonus|null Возвращает агентский бонус
     */
    public function createBonusForOrder(Order $order): ?Bonus
    {
        app(BonusAccrualService::class)->sync($order);

        return $order->bonuses()->where('recipient_type', 'agent')->first();
    }

    /**
     * Создать бонус куратора для закупки.
     */
    public function createCuratorBonusForOrder(Order $order): ?Bonus
    {
        // Получаем curator_id из проекта
        $curatorId = $this->getCuratorIdFromProject($order->project_id);
        if (! $curatorId) {
            return null;
        }

        // Получаем order_amount из атрибутов напрямую, минуя accessor
        $orderAmount = $order->getAttributes()['order_amount'] ?? $order->getRawOriginal('order_amount') ?? 0;

        $curatorCommission = $this->calculateCommission(
            (float) $orderAmount,
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
            'bonus_type' => 'curator',
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => null,
        ]);
    }

    /**
     * Обновить бонусы при изменении договора.
     */
    public function updateBonusesForContract(Contract $contract): void
    {
        app(BonusAccrualService::class)->sync($contract);
    }

    /**
     * Обновить бонусы при изменении закупки.
     */
    public function updateBonusesForOrder(Order $order): void
    {
        app(BonusAccrualService::class)->sync($order);
    }

    /**
     * Пересчитать бонус при изменении суммы или процента.
     */
    public function recalculateBonus(Bonus $bonus): Bonus
    {
        $source = $bonus->contract_id ? $bonus->contract : $bonus->order;
        app(BonusAccrualService::class)->sync($source);

        return $bonus->fresh() ?? $bonus;
    }

    /**
     * Пересчитать бонус куратора для договора.
     *
     * @param  Contract  $contract
     */
    public function recalculateCuratorBonus(Bonus $bonus, Contract $source): Bonus
    {
        app(BonusAccrualService::class)->sync($source);

        return $bonus->fresh() ?? $bonus;
    }

    /**
     * Пересчитать бонус куратора для закупки.
     *
     * @param  Order  $order
     */
    public function recalculateCuratorBonusForOrder(Bonus $bonus, Order $source): Bonus
    {
        app(BonusAccrualService::class)->sync($source);

        return $bonus->fresh() ?? $bonus;
    }

    /**
     * Перевести бонус в статус "Доступно к выплате".
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
     */
    public function getAgentStats(int $userId, ?array $filters = null): array
    {

        $filters = $filters ?? [];
        if (empty($filters['recipient_type'])) {
            $filters['requester_type'] = 'agent';
        }

        return app(BonusStatisticsService::class)->calculate(array_merge($filters, ['user_id' => $userId]));
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
     * @param  string  $newStatusCode
     */
    public function handleContractPartnerPaymentStatusChange(Contract $contract, string $status): void
    {
        app(BonusAccrualService::class)->sync($contract);
    }

    /**
     * Обработать изменение статуса оплаты партнёром для закупки.
     *
     * ПРИМЕЧАНИЕ: Для заказов статус оплаты партнёром не используется.
     * Бонус переходит в "Доступно" только при доставке заказа.
     * Этот метод оставлен для обратной совместимости, но не выполняет действий.
     *
     * @param  string  $newStatusCode
     *
     * @deprecated Для заказов используйте handleOrderStatusChange
     */
    public function handleOrderPartnerPaymentStatusChange(Order $order, string $status): void
    {
        app(BonusAccrualService::class)->sync($order);
    }

    /**
     * Обработать изменение статуса договора.
     *
     * Логика статусов бонуса относительно статуса договора:
     * - preparing (Обработка): бонусы НЕ отображаются (фильтруются в getAgentStats)
     * - signed (Заключён): бонус = pending (Ожидание)
     * - completed (Выполнен): бонус = pending, но если partner_paid=true → available
     * - claim (Рекламация): бонус = pending (Ожидание)
     * - rejected (Отказ): бонус = cancelled (Аннулирован)
     * - terminated (Расторгнут): бонус = cancelled (Аннулирован)
     *
     * При смене с отменяющего статуса на обычный — бонусы восстанавливаются.
     *
     * @param  string  $newStatusSlug
     */
    public function handleContractStatusChange(Contract $contract, string $status): void
    {
        app(BonusAccrualService::class)->sync($contract);
    }

    /**
     * Восстановить аннулированные бонусы и обновить их доступность.
     */
    private function restoreAndUpdateContractBonuses(Contract $contract): void
    {
        $bonuses = $contract->bonuses()->whereNull('paid_at')->get();
        $cancelledStatusId = BonusStatus::cancelledId();
        $pendingStatusId = BonusStatus::pendingId();

        foreach ($bonuses as $bonus) {
            // Если бонус был аннулирован — восстанавливаем в pending
            if ($cancelledStatusId && $bonus->status_id == $cancelledStatusId) {
                $bonus->status_id = $pendingStatusId;
                $bonus->available_at = null;
                $bonus->save();

                \Illuminate\Support\Facades\Log::info('BonusService: Restored cancelled bonus', [
                    'bonus_id' => $bonus->id,
                    'contract_id' => $contract->id,
                ]);
            }

            // Проверяем условия для доступности бонуса
            $this->checkAndUpdateContractBonusAvailability($contract, $bonus);
        }
    }

    /**
     * Аннулировать все бонусы договора.
     *
     * Аннулируются только невыплаченные бонусы (без paid_at).
     *
     * @return int Количество аннулированных бонусов
     */
    public function cancelBonusesForContract(Contract $contract): int
    {
        $cancelledCount = 0;
        $cancelledStatusId = BonusStatus::cancelledId();

        if (! $cancelledStatusId) {
            \Illuminate\Support\Facades\Log::error('BonusService: cancelled status not found');

            return 0;
        }

        $bonuses = $contract->bonuses()->whereNull('paid_at')->get();

        foreach ($bonuses as $bonus) {
            $bonus->status_id = $cancelledStatusId;
            $bonus->available_at = null; // Сбрасываем доступность
            $bonus->save();
            $cancelledCount++;
        }

        \Illuminate\Support\Facades\Log::info('BonusService: Cancelled bonuses for contract', [
            'contract_id' => $contract->id,
            'cancelled_count' => $cancelledCount,
        ]);

        return $cancelledCount;
    }

    /**
     * Проверить и обновить доступность бонуса для договора.
     *
     * Бонус становится доступным к выплате когда выполнены ОБА условия:
     * - is_contract_completed: Статус договора = 'completed' (Выполнен)
     * - is_partner_paid: Статус оплаты партнёром = 'paid' (Оплачено)
     */
    private function checkAndUpdateContractBonusAvailability(Contract $contract, Bonus $bonus): void
    {
        // Не трогаем уже оплаченные бонусы
        if ($bonus->paid_at !== null) {
            return;
        }

        // Загружаем связи если не загружены
        if (! $contract->relationLoaded('status')) {
            $contract->load('status');
        }
        if (! $contract->relationLoaded('partnerPaymentStatus')) {
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
     */
    public function handleContractActiveChange(Contract $contract): void
    {
        app(BonusAccrualService::class)->sync($contract);
    }

    /**
     * Обработать изменение статуса заказа.
     *
     * Бонус переходит в статус "Доступно к выплате" при:
     * - Статус заказа = 'delivered' (Доставлен)
     * - Заказ активен (is_active = true)
     *
     * При статусе 'returned' (Возврат) — бонусы аннулируются.
     *
     * Для заказов НЕ требуется проверка оплаты партнёром,
     * так как компания сама организует продажу заказов.
     *
     * Обновляет ВСЕ бонусы заказа (агентский + кураторский + реферальный).
     *
     * @param  string  $newStatusSlug
     */
    public function handleOrderStatusChange(Order $order, string $status): void
    {
        app(BonusAccrualService::class)->sync($order);
    }

    /**
     * Восстановить аннулированные бонусы заказа и обновить их доступность.
     */
    private function restoreAndUpdateOrderBonuses(Order $order, string $statusSlug): void
    {
        $bonuses = $order->bonuses()->whereNull('paid_at')->get();
        $cancelledStatusId = BonusStatus::cancelledId();
        $pendingStatusId = BonusStatus::pendingId();

        foreach ($bonuses as $bonus) {
            // Если бонус был аннулирован — восстанавливаем в pending
            if ($cancelledStatusId && $bonus->status_id == $cancelledStatusId) {
                $bonus->status_id = $pendingStatusId;
                $bonus->available_at = null;
                $bonus->save();

                \Illuminate\Support\Facades\Log::info('BonusService: Restored cancelled order bonus', [
                    'bonus_id' => $bonus->id,
                    'order_id' => $order->id,
                ]);
            }

            // Если заказ перешёл в статус "Доставлен" — делаем бонус доступным
            if ($statusSlug === 'delivered') {
                $isOrderActive = $order->is_active === true;

                if ($isOrderActive) {
                    $this->markBonusAsAvailable($bonus);
                }
            } else {
                // Для статуса "Сформирован" — бонус в ожидании
                if ($bonus->available_at !== null) {
                    $this->revertBonusToAccrued($bonus);
                }
            }
        }
    }

    /**
     * Аннулировать все бонусы заказа.
     *
     * Аннулируются только невыплаченные бонусы (без paid_at).
     *
     * @return int Количество аннулированных бонусов
     */
    public function cancelBonusesForOrder(Order $order): int
    {
        $cancelledCount = 0;
        $cancelledStatusId = BonusStatus::cancelledId();

        if (! $cancelledStatusId) {
            \Illuminate\Support\Facades\Log::error('BonusService: cancelled status not found');

            return 0;
        }

        $bonuses = $order->bonuses()->whereNull('paid_at')->get();

        foreach ($bonuses as $bonus) {
            $bonus->status_id = $cancelledStatusId;
            $bonus->available_at = null; // Сбрасываем доступность
            $bonus->save();
            $cancelledCount++;
        }

        \Illuminate\Support\Facades\Log::info('BonusService: Cancelled bonuses for order', [
            'order_id' => $order->id,
            'cancelled_count' => $cancelledCount,
        ]);

        return $cancelledCount;
    }

    /**
     * Обработать изменение is_active для заказа.
     *
     * Для заказов НЕ требуется проверка оплаты партнёром.
     * Бонус доступен к выплате если заказ доставлен и активен.
     *
     * Обновляет ВСЕ бонусы заказа (агентский + кураторский + реферальный).
     */
    public function handleOrderActiveChange(Order $order): void
    {
        app(BonusAccrualService::class)->sync($order);
    }

    /**
     * Получить ID агента из проекта.
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

        // Альтернативно: ищем в связи project_user с ролью 'agent'
        // ВАЖНО: фильтруем по роли, чтобы не вернуть куратора или другого пользователя
        $projectUser = DB::table('project_user')
            ->where('project_id', $projectId)
            ->where('role', 'agent')
            ->first();

        if ($projectUser) {
            return $projectUser->user_id;
        }

        return null;
    }

    /**
     * Получить ID куратора из проекта.
     */
    private function getCuratorIdFromProject(string $projectId): ?int
    {
        // Ищем куратора в таблице project_user с ролью 'curator'
        $curatorRelation = DB::table('project_user')
            ->where('project_id', $projectId)
            ->where('role', 'curator')
            ->first();

        if ($curatorRelation && isset($curatorRelation->user_id)) {
            return $curatorRelation->user_id;
        }

        return null;
    }

    /**
     * Получить сумму запрошенных к выплате заявок пользователя.
     *
     * Учитывает только заявки со статусом 'requested' или 'approved' (не выплаченные).
     *
     * @param  string|null  $requesterType  Тип запрашивающего (agent, curator)
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

    /**
     * Аннулировать все бонусы проекта при переходе в статус "Отказ".
     *
     * Аннулируются только невыплаченные бонусы (без paid_at).
     * Бонусы всех договоров и заказов проекта получают статус 'cancelled'.
     *
     * @param  string  $projectId  ID проекта
     * @return int Количество аннулированных бонусов
     */
    public function cancelBonusesForProject(string $projectId): int
    {
        $cancelledCount = 0;
        $cancelledStatusId = BonusStatus::cancelledId();

        if (! $cancelledStatusId) {
            \Illuminate\Support\Facades\Log::error('BonusService: cancelled status not found');

            return 0;
        }

        // Получаем все договоры проекта
        $contractIds = DB::table('contracts')
            ->where('project_id', $projectId)
            ->pluck('id')
            ->toArray();

        // Получаем все заказы проекта
        $orderIds = DB::table('orders')
            ->where('project_id', $projectId)
            ->pluck('id')
            ->toArray();

        // Аннулируем все бонусы договоров (которые ещё не выплачены)
        if (! empty($contractIds)) {
            $contractBonuses = Bonus::whereIn('contract_id', $contractIds)
                ->whereNull('paid_at')
                ->get();

            foreach ($contractBonuses as $bonus) {
                $bonus->status_id = $cancelledStatusId;
                $bonus->save();
                $cancelledCount++;
            }
        }

        // Аннулируем все бонусы заказов (которые ещё не выплачены)
        if (! empty($orderIds)) {
            $orderBonuses = Bonus::whereIn('order_id', $orderIds)
                ->whereNull('paid_at')
                ->get();

            foreach ($orderBonuses as $bonus) {
                $bonus->status_id = $cancelledStatusId;
                $bonus->save();
                $cancelledCount++;
            }
        }

        \Illuminate\Support\Facades\Log::info('BonusService: Cancelled bonuses for project', [
            'project_id' => $projectId,
            'cancelled_count' => $cancelledCount,
        ]);

        return $cancelledCount;
    }

    /**
     * Восстановить аннулированные бонусы проекта.
     *
     * Восстанавливает бонусы в статус pending и пересчитывает их доступность
     * на основе текущих статусов договоров и заказов.
     *
     * @param  string  $projectId  ID проекта
     * @return int Количество восстановленных бонусов
     */
    public function restoreBonusesForProject(string $projectId): int
    {
        $restoredCount = 0;
        $cancelledStatusId = BonusStatus::cancelledId();
        $pendingStatusId = BonusStatus::pendingId();

        if (! $cancelledStatusId || ! $pendingStatusId) {
            \Illuminate\Support\Facades\Log::error('BonusService: status IDs not found');

            return 0;
        }

        // Получаем все договоры проекта
        $contracts = Contract::where('project_id', $projectId)->get();

        // Получаем все заказы проекта
        $orders = Order::where('project_id', $projectId)->get();

        // Восстанавливаем бонусы договоров
        foreach ($contracts as $contract) {
            // Пропускаем договоры с отменяющими статусами
            $contractStatus = $contract->status;
            if ($contractStatus && in_array($contractStatus->slug, ['rejected', 'terminated'])) {
                continue;
            }

            $bonuses = $contract->bonuses()
                ->whereNull('paid_at')
                ->where('status_id', $cancelledStatusId)
                ->get();

            foreach ($bonuses as $bonus) {
                $bonus->status_id = $pendingStatusId;
                $bonus->available_at = null;
                $bonus->save();
                $restoredCount++;

                // Проверяем условия доступности
                $this->checkAndUpdateContractBonusAvailability($contract, $bonus);
            }
        }

        // Восстанавливаем бонусы заказов
        foreach ($orders as $order) {
            // Пропускаем заказы с отменяющими статусами
            $orderStatus = $order->status;
            if ($orderStatus && $orderStatus->slug === 'returned') {
                continue;
            }

            $bonuses = $order->bonuses()
                ->whereNull('paid_at')
                ->where('status_id', $cancelledStatusId)
                ->get();

            foreach ($bonuses as $bonus) {
                $bonus->status_id = $pendingStatusId;
                $bonus->available_at = null;
                $bonus->save();
                $restoredCount++;

                // Проверяем условия доступности для заказа
                if ($orderStatus && $orderStatus->slug === 'delivered' && $order->is_active) {
                    $this->markBonusAsAvailable($bonus);
                }
            }
        }

        \Illuminate\Support\Facades\Log::info('BonusService: Restored bonuses for project', [
            'project_id' => $projectId,
            'restored_count' => $restoredCount,
        ]);

        return $restoredCount;
    }

    /**
     * Удалить все бонусы куратора для проекта.
     *
     * Вызывается при переходе проекта из статуса "Принят куратором" в "Новый проект".
     * Удаляются только невыплаченные кураторские бонусы.
     *
     * @param  string  $projectId  ID проекта
     * @return int Количество удалённых бонусов
     */
    public function removeCuratorBonusesForProject(string $projectId): int
    {
        $removedCount = 0;
        $project = \App\Models\Project::findOrFail($projectId);
        FinancialLedger::assertUncommitted($project->financialBonuses()->where('recipient_type', Bonus::RECIPIENT_CURATOR));

        // Получаем все договоры проекта
        $contractIds = DB::table('contracts')
            ->where('project_id', $projectId)
            ->pluck('id')
            ->toArray();

        // Получаем все заказы проекта
        $orderIds = DB::table('orders')
            ->where('project_id', $projectId)
            ->pluck('id')
            ->toArray();

        // Удаляем кураторские бонусы договоров (которые ещё не выплачены)
        if (! empty($contractIds)) {
            $contractBonusesDeleted = Bonus::whereIn('contract_id', $contractIds)
                ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
                ->whereNull('paid_at')
                ->delete();
            $removedCount += $contractBonusesDeleted;
        }

        // Удаляем кураторские бонусы заказов (которые ещё не выплачены)
        if (! empty($orderIds)) {
            $orderBonusesDeleted = Bonus::whereIn('order_id', $orderIds)
                ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
                ->whereNull('paid_at')
                ->delete();
            $removedCount += $orderBonusesDeleted;
        }

        \Illuminate\Support\Facades\Log::info('BonusService: Removed curator bonuses for project', [
            'project_id' => $projectId,
            'removed_count' => $removedCount,
        ]);

        return $removedCount;
    }

    /**
     * Создать бонусы куратора для всех договоров и заказов проекта.
     *
     * Вызывается при назначении куратора на проект (смена статуса на "Принят куратором").
     * Создаёт кураторские бонусы для всех активных договоров и заказов,
     * у которых ещё нет кураторского бонуса.
     *
     * @param  string  $projectId  ID проекта
     * @param  int  $curatorId  ID куратора
     * @return int Количество созданных бонусов
     */
    public function createCuratorBonusesForProject(string $projectId, int $curatorId): int
    {
        $createdCount = 0;

        // Получаем все активные договоры проекта
        $contracts = Contract::where('project_id', $projectId)
            ->where('is_active', true)
            ->get();

        foreach ($contracts as $contract) {
            // Проверяем, нет ли уже бонуса куратора
            $existingBonus = Bonus::where('contract_id', $contract->id)
                ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
                ->first();

            if (! $existingBonus) {
                $bonus = $this->createCuratorBonusForContractWithCurator($contract, $curatorId);
                if ($bonus) {
                    $createdCount++;
                    \Illuminate\Support\Facades\Log::info('BonusService: Created curator bonus for contract', [
                        'bonus_id' => $bonus->id,
                        'contract_id' => $contract->id,
                        'curator_id' => $curatorId,
                        'amount' => $bonus->commission_amount,
                    ]);
                }
            }
        }

        // Получаем все активные заказы проекта
        $orders = Order::where('project_id', $projectId)
            ->where('is_active', true)
            ->get();

        foreach ($orders as $order) {
            // Проверяем, нет ли уже бонуса куратора
            $existingBonus = Bonus::where('order_id', $order->id)
                ->where('recipient_type', Bonus::RECIPIENT_CURATOR)
                ->first();

            if (! $existingBonus) {
                $bonus = $this->createCuratorBonusForOrderWithCurator($order, $curatorId);
                if ($bonus) {
                    $createdCount++;
                    \Illuminate\Support\Facades\Log::info('BonusService: Created curator bonus for order', [
                        'bonus_id' => $bonus->id,
                        'order_id' => $order->id,
                        'curator_id' => $curatorId,
                        'amount' => $bonus->commission_amount,
                    ]);
                }
            }
        }

        \Illuminate\Support\Facades\Log::info('BonusService: Created curator bonuses for project', [
            'project_id' => $projectId,
            'curator_id' => $curatorId,
            'created_count' => $createdCount,
        ]);

        return $createdCount;
    }

    /**
     * Создать бонус куратора для договора с указанным куратором.
     */
    public function createCuratorBonusForContractWithCurator(Contract $contract, int $curatorId): ?Bonus
    {
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
            'bonus_type' => 'curator',
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => null,
        ]);
    }

    /**
     * Создать бонус куратора для заказа с указанным куратором.
     */
    public function createCuratorBonusForOrderWithCurator(Order $order, int $curatorId): ?Bonus
    {
        // Получаем order_amount из атрибутов напрямую, минуя accessor
        $orderAmount = $order->getAttributes()['order_amount'] ?? $order->getRawOriginal('order_amount') ?? 0;

        $curatorCommission = $this->calculateCommission(
            (float) $orderAmount,
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
            'bonus_type' => 'curator',
            'accrued_at' => now(),
            'available_at' => null,
            'paid_at' => null,
            'referral_user_id' => null,
        ]);
    }
}
