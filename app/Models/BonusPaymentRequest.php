<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель заявки на выплату бонуса.
 *
 * Хранит информацию о заявках агентов на выплату накопленных бонусов.
 */
class BonusPaymentRequest extends Model
{
    use HasFactory;

    protected $table = 'bonus_payment_requests';

    protected $fillable = [
        'agent_id',
        'amount',
        'payment_method',
        'card_number',
        'phone_number',
        'contact_info',
        'comment',
        'status_id',
        'payment_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Получить агента (пользователя), создавшего заявку.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Получить статус заявки.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(BonusPaymentStatus::class, 'status_id');
    }

    /**
     * Scope: Заявки конкретного агента.
     */
    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * Scope: Заявки с определённым статусом.
     */
    public function scopeWithStatus($query, string $statusCode)
    {
        return $query->whereHas('status', function ($q) use ($statusCode) {
            $q->where('code', $statusCode);
        });
    }

    /**
     * Scope: Заявки за период.
     */
    public function scopeInDateRange($query, $from, $to)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        return $query;
    }

    /**
     * Получить отформатированные реквизиты в зависимости от способа оплаты.
     */
    public function getPaymentDetailsAttribute(): ?string
    {
        return match ($this->payment_method) {
            'card' => $this->card_number,
            'sbp' => $this->phone_number,
            'other' => $this->contact_info,
            default => null,
        };
    }

    /**
     * Получить название способа оплаты на русском.
     */
    public function getPaymentMethodNameAttribute(): string
    {
        return match ($this->payment_method) {
            'card' => 'Карта',
            'sbp' => 'СБП',
            'other' => 'Другое',
            default => 'Не указан',
        };
    }
}
