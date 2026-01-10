<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель связи между заявкой на выплату и бонусом.
 *
 * Хранит информацию о том, какие бонусы покрываются заявкой на выплату
 * и какая сумма каждого бонуса покрыта.
 *
 * Feature: bonus-payments
 * Requirements: 8.1
 */
class BonusPaymentRequestBonus extends Model
{
    use HasFactory;

    protected $table = 'bonus_payment_request_bonuses';

    protected $fillable = [
        'payment_request_id',
        'bonus_id',
        'covered_amount',
    ];

    protected $casts = [
        'covered_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Получить заявку на выплату.
     */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(BonusPaymentRequest::class, 'payment_request_id');
    }

    /**
     * Получить бонус.
     */
    public function bonus(): BelongsTo
    {
        return $this->belongsTo(AgentBonus::class, 'bonus_id');
    }

    /**
     * Проверить, полностью ли покрыт бонус.
     */
    public function isFullyCovered(): bool
    {
        if (!$this->bonus) {
            return false;
        }
        return (float) $this->covered_amount >= (float) $this->bonus->commission_amount;
    }

    /**
     * Получить остаток непокрытой суммы бонуса.
     */
    public function getRemainingAmountAttribute(): float
    {
        if (!$this->bonus) {
            return 0.0;
        }
        return max(0, (float) $this->bonus->commission_amount - (float) $this->covered_amount);
    }
}
