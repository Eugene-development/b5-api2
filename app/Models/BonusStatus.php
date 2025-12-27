<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель статуса бонуса.
 *
 * Статусы: pending (ожидание), paid (выплачено)
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
     * Получить ID статуса "Ожидание".
     */
    public static function pendingId(): int
    {
        return static::where('code', 'pending')->value('id');
    }

    /**
     * Получить ID статуса "Выплачено".
     */
    public static function paidId(): int
    {
        return static::where('code', 'paid')->value('id');
    }
}
