<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель статуса заявки на выплату бонуса.
 *
 * Статусы: requested (запрошено), approved (согласовано), paid (выплачено)
 */
class BonusPaymentStatus extends Model
{
    use HasFactory;

    protected $table = 'bonus_payment_statuses';

    protected $fillable = [
        'code',
        'name',
        'description',
        'color',
    ];

    /**
     * Получить все заявки с данным статусом.
     */
    public function requests(): HasMany
    {
        return $this->hasMany(BonusPaymentRequest::class, 'status_id');
    }

    /**
     * Найти статус по коду.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Получить ID статуса "Запрошено".
     */
    public static function requestedId(): int
    {
        return static::where('code', 'requested')->value('id');
    }

    /**
     * Получить ID статуса "Согласовано".
     */
    public static function approvedId(): int
    {
        return static::where('code', 'approved')->value('id');
    }

    /**
     * Получить ID статуса "Выплачено".
     */
    public static function paidId(): int
    {
        return static::where('code', 'paid')->value('id');
    }
}
