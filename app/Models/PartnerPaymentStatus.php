<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель статуса оплаты партнёром.
 *
 * Статусы: pending (ожидает оплаты), paid (оплачено)
 */
class PartnerPaymentStatus extends Model
{
    use HasFactory;

    protected $table = 'partner_payment_statuses';

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    /**
     * Получить все договоры с данным статусом.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'partner_payment_status_id');
    }

    /**
     * Получить все закупки с данным статусом.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'partner_payment_status_id');
    }

    /**
     * Найти статус по коду.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Получить ID статуса "Ожидает оплаты".
     */
    public static function pendingId(): int
    {
        return static::where('code', 'pending')->value('id');
    }

    /**
     * Получить ID статуса "Оплачено".
     */
    public static function paidId(): int
    {
        return static::where('code', 'paid')->value('id');
    }
}
