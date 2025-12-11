<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель способа выплаты.
 *
 * Способы: cash (наличные), e_wallet (электронный кошелёк),
 * card_transfer (перевод на карту), bank_transfer (перевод на расчётный счёт)
 */
class PaymentMethod extends Model
{
    use HasFactory;

    protected $table = 'payment_methods';

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    /**
     * Получить все выплаты с данным способом.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(AgentPayment::class, 'method_id');
    }

    /**
     * Найти способ по коду.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }
}
