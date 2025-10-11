<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPosition extends Model
{
    use HasFactory, HasUlids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'order_positions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'value',
        'article',
        'price',
        'count',
        'supplier',
        'expected_delivery_date',
        'actual_delivery_date',
        'is_active',
        'is_urgent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'count' => 'integer',
        'is_active' => 'boolean',
        'is_urgent' => 'boolean',
        'expected_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['total_price'];

    /**
     * Get the order that owns the position.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the total price for the position.
     *
     * @return float
     */
    public function getTotalPriceAttribute(): float
    {
        return $this->price * $this->count;
    }
}
