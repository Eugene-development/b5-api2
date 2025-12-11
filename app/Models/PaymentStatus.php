<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель статуса выплаты.
 *
 * Статусы: pending (ожидает), completed (завершена), failed (ошибка)
 */
class PaymentStatus extends Model
{
    use HasFactory;

    protected $table = 'payment_statuses';

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    /**
     * Получить все выплаты с данным статусом.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(AgentPayment::class, 'status_id');
    }

    /**
     * Найти статус по коду.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Получить ID статуса "Ожидает".
     */
    public static function pendingId(): int
    {
        return static::where('code', 'pending')->value('id');
    }

    /**
     * Получить ID статуса "Завершена".
     */
    public static function completedId(): int
    {
        return static::where('code', 'completed')->value('id');
    }

    /**
     * Получить ID статуса "Ошибка".
     */
    public static function failedId(): int
    {
        return static::where('code', 'failed')->value('id');
    }
}
