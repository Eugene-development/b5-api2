<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Модель бонуса агента.
 *
 * Хранит информацию о начисленных бонусах агентам за договоры и закупки.
 * Жизненный цикл: accrued → available_for_payment → paid
 */
class AgentBonus extends Model
{
    use HasFactory;

    protected $table = 'agent_bonuses';

    protected $fillable = [
        'agent_id',
        'contract_id',
        'order_id',
        'commission_amount',
        'status_id',
        'accrued_at',
        'available_at',
        'paid_at',
    ];

    protected $casts = [
        'commission_amount' => 'decimal:2',
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
    ];

    /**
     * Получить агента (пользователя), которому начислен бонус.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
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
     * Получить выплаты, в которые включён этот бонус.
     */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentPayment::class,
            'agent_payment_bonuses',
            'bonus_id',
            'payment_id'
        );
    }

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
     * Scope: Бонусы конкретного агента.
     */
    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
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
}
