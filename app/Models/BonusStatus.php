<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель статуса бонуса.
 *
 * Статусы: accrued (начислено), available_for_payment (доступно к выплате), paid (выплачено)
 */
class BonusStatus extends Model
{
    use HasFactory;

    protected $table = 'bonus_statuses';

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    /**
     * Получить все бонусы с данным статусом.
     */
    public function bonuses(): HasMany
    {
        return $this->hasMany(AgentBonus::class, 'status_id');
    }

    /**
     * Найти статус по коду.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Получить ID статуса "Начислено".
     */
    public static function accruedId(): int
    {
        return static::where('code', 'accrued')->value('id');
    }

    /**
     * Получить ID статуса "Доступно к выплате".
     */
    public static function availableForPaymentId(): int
    {
        return static::where('code', 'available_for_payment')->value('id');
    }

    /**
     * Получить ID статуса "Выплачено".
     */
    public static function paidId(): int
    {
        return static::where('code', 'paid')->value('id');
    }
}
