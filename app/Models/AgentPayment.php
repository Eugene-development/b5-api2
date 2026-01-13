<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Модель выплаты агенту.
 *
 * Хранит информацию о выплатах агентам.
 * Одна выплата может включать несколько бонусов.
 */
class AgentPayment extends Model
{
    use HasFactory;

    protected $table = 'agent_payments';

    protected $fillable = [
        'agent_id',
        'total_amount',
        'payment_date',
        'reference_number',
        'status_id',
        'method_id',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Получить агента (пользователя), которому выплачен бонус.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Получить статус выплаты.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(PaymentStatus::class, 'status_id');
    }

    /**
     * Получить способ выплаты.
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'method_id');
    }


    /**
     * Получить бонусы, включённые в эту выплату.
     */
    public function bonuses(): BelongsToMany
    {
        return $this->belongsToMany(
            Bonus::class,
            'payment_bonuses',
            'payment_id',
            'bonus_id'
        );
    }

    /**
     * Scope: Выплаты конкретного агента.
     */
    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * Scope: Выплаты с определённым статусом.
     */
    public function scopeWithStatus($query, string $statusCode)
    {
        return $query->whereHas('status', function ($q) use ($statusCode) {
            $q->where('code', $statusCode);
        });
    }

    /**
     * Scope: Выплаты за период.
     */
    public function scopeInDateRange($query, $from, $to)
    {
        if ($from) {
            $query->where('payment_date', '>=', $from);
        }
        if ($to) {
            $query->where('payment_date', '<=', $to);
        }
        return $query;
    }
}
