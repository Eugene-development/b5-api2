<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Единая модель бонуса.
 *
 * Хранит информацию о начисленных бонусах агентам, кураторам и рефереров.
 * Жизненный цикл: accrued → available_for_payment → paid
 */
class Bonus extends Model
{
    use HasFactory;

    // Типы получателей
    const RECIPIENT_AGENT = 'agent';
    const RECIPIENT_CURATOR = 'curator';
    const RECIPIENT_REFERRER = 'referrer';

    // Типы бонусов (legacy, для совместимости)
    const BONUS_TYPE_AGENT = 'agent';
    const BONUS_TYPE_REFERRAL = 'referral';

    protected $table = 'bonuses';

    protected $fillable = [
        'user_id',
        'contract_id',
        'order_id',
        'commission_amount',
        'percentage',
        'status_id',
        'recipient_type',
        'bonus_type',
        'referral_user_id',
        'accrued_at',
        'available_at',
        'paid_at',
    ];

    protected $casts = [
        'commission_amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'accrued_at' => 'datetime',
        'available_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Атрибуты, которые должны быть добавлены к массиву модели.
     */
    protected $appends = [
        'source_type',
        'source_amount',
        'project_name',
        'contract_number',
        'order_number',
        'is_contract_completed',
        'is_partner_paid',
        'bonus_type_label',
        'recipient_type_label',
    ];

    // ==================== Relationships ====================

    /**
     * Получить пользователя, которому начислен бонус.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Alias для backward compatibility.
     * @deprecated Use user() instead
     */
    public function agent(): BelongsTo
    {
        return $this->user();
    }

    /**
     * Получить договор, за который начислен бонус.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /**
     * Получить закупку, за которую начислен бонус.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Получить статус бонуса.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(BonusStatus::class, 'status_id');
    }

    /**
     * Получить реферала, за сделку которого начислен бонус.
     * Только для реферальных бонусов (bonus_type = 'referral').
     */
    public function referralUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referral_user_id');
    }

    /**
     * Получить выплаты, в которые включён этот бонус.
     */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentPayment::class,
            'payment_bonuses',
            'bonus_id',
            'payment_id'
        );
    }

    // ==================== Scopes ====================

    /**
     * Scope: Бонусы конкретного пользователя.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Агентские бонусы конкретного пользователя.
     */
    public function scopeForAgent($query, int $userId)
    {
        return $query->where('user_id', $userId)
                     ->where('recipient_type', self::RECIPIENT_AGENT);
    }

    /**
     * Scope: Кураторские бонусы конкретного пользователя.
     */
    public function scopeForCurator($query, int $userId)
    {
        return $query->where('user_id', $userId)
                     ->where('recipient_type', self::RECIPIENT_CURATOR);
    }

    /**
     * Scope: Реферальные бонусы конкретного пользователя.
     */
    public function scopeForReferrer($query, int $userId)
    {
        return $query->where('user_id', $userId)
                     ->where('recipient_type', self::RECIPIENT_REFERRER);
    }

    /**
     * Scope: Все агентские бонусы.
     */
    public function scopeAgentBonuses($query)
    {
        return $query->where('recipient_type', self::RECIPIENT_AGENT);
    }

    /**
     * Scope: Все кураторские бонусы.
     */
    public function scopeCuratorBonuses($query)
    {
        return $query->where('recipient_type', self::RECIPIENT_CURATOR);
    }

    /**
     * Scope: Все реферальные бонусы.
     */
    public function scopeReferralBonuses($query)
    {
        return $query->where('recipient_type', self::RECIPIENT_REFERRER);
    }

    /**
     * Scope: Бонусы с определённым статусом.
     */
    public function scopeWithStatus($query, string $statusCode)
    {
        return $query->whereHas('status', function ($q) use ($statusCode) {
            $q->where('code', $statusCode);
        });
    }

    /**
     * Scope: Бонусы от договоров.
     */
    public function scopeFromContracts($query)
    {
        return $query->whereNotNull('contract_id');
    }

    /**
     * Scope: Бонусы от закупок.
     */
    public function scopeFromOrders($query)
    {
        return $query->whereNotNull('order_id');
    }

    // ==================== Accessors ====================

    /**
     * Accessor: Тип источника бонуса ('contract' или 'order').
     */
    public function getSourceTypeAttribute(): string
    {
        return $this->contract_id ? 'contract' : 'order';
    }

    /**
     * Accessor: Сумма источника (сумма договора или закупки).
     */
    public function getSourceAmountAttribute(): float
    {
        if ($this->contract_id && $this->contract) {
            return (float) ($this->contract->contract_amount ?? 0);
        }
        if ($this->order_id && $this->order) {
            return (float) ($this->order->order_amount ?? 0);
        }
        return 0.0;
    }

    /**
     * Accessor: Название проекта.
     */
    public function getProjectNameAttribute(): ?string
    {
        if ($this->contract_id && $this->contract && $this->contract->project) {
            return $this->contract->project->value;
        }
        if ($this->order_id && $this->order && $this->order->project) {
            return $this->order->project->value;
        }
        return null;
    }

    /**
     * Accessor: Номер договора.
     */
    public function getContractNumberAttribute(): ?string
    {
        if ($this->contract_id && $this->contract) {
            return $this->contract->contract_number;
        }
        return null;
    }

    /**
     * Accessor: Номер заказа.
     */
    public function getOrderNumberAttribute(): ?string
    {
        if ($this->order_id && $this->order) {
            return $this->order->order_number;
        }
        return null;
    }

    /**
     * Accessor: Человекочитаемый тип бонуса.
     */
    public function getBonusTypeLabelAttribute(): string
    {
        return match ($this->bonus_type) {
            'referral' => 'Реферальный',
            'agent' => 'Агентский',
            default => 'Агентский',
        };
    }

    /**
     * Accessor: Человекочитаемый тип получателя.
     */
    public function getRecipientTypeLabelAttribute(): string
    {
        return match ($this->recipient_type) {
            self::RECIPIENT_AGENT => 'Агент',
            self::RECIPIENT_CURATOR => 'Куратор',
            self::RECIPIENT_REFERRER => 'Реферер',
            default => 'Неизвестно',
        };
    }

    /**
     * Accessor: Выполнен ли договор (статус = 'completed').
     * Для бонусов от заказов всегда возвращает null.
     */
    public function getIsContractCompletedAttribute(): ?bool
    {
        if (!$this->contract_id) {
            return null;
        }

        if (!$this->relationLoaded('contract')) {
            $this->load('contract.status');
        }

        if (!$this->contract) {
            return false;
        }

        if (!$this->contract->relationLoaded('status')) {
            $this->contract->load('status');
        }

        return $this->contract->status && $this->contract->status->slug === 'completed';
    }

    /**
     * Accessor: Оплачен ли договор партнёром (partner_payment_status = 'paid').
     * Для бонусов от заказов всегда возвращает null.
     */
    public function getIsPartnerPaidAttribute(): ?bool
    {
        if (!$this->contract_id) {
            return null;
        }

        if (!$this->relationLoaded('contract')) {
            $this->load('contract.partnerPaymentStatus');
        }

        if (!$this->contract) {
            return false;
        }

        if (!$this->contract->relationLoaded('partnerPaymentStatus')) {
            $this->contract->load('partnerPaymentStatus');
        }

        return $this->contract->partnerPaymentStatus && $this->contract->partnerPaymentStatus->code === 'paid';
    }
}
