<?php

namespace App\Models;

use App\Services\BonusCalculationService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory, HasUlids;

    /**
     * Boot the model and add event listeners
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            // Auto-generate order_number if not provided
            if (empty($order->order_number)) {
                $order->order_number = self::generateOrderNumber();
            }

            // Set default percentages for orders (5% each)
            // Применяем дефолт если не указан или равен 0
            if (!isset($order->agent_percentage) || $order->agent_percentage == 0) {
                $order->agent_percentage = 5.00;
            }
            if (!isset($order->curator_percentage) || $order->curator_percentage == 0) {
                $order->curator_percentage = 5.00;
            }
        });

        // Автоматический пересчёт бонусов при сохранении закупки
        static::saving(function ($order) {
            app(BonusCalculationService::class)->recalculateOrderBonuses($order);
        });

        // Обновляем updated_at проекта при изменении закупки
        static::saved(function ($order) {
            if ($order->project) {
                $order->project->touch();
            }
        });

        // Обновляем updated_at проекта при удалении закупки
        static::deleted(function ($order) {
            if ($order->project) {
                $order->project->touch();
            }
        });
    }

    /**
     * Generate unique order number in format ORDER-ххххх-ххх
     *
     * @return string
     */
    public static function generateOrderNumber(): string
    {
        do {
            // Generate random 5-digit number
            $firstPart = str_pad((string)rand(10000, 99999), 5, '0', STR_PAD_LEFT);

            // Generate random 3-digit number
            $secondPart = str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);

            // Combine into ORDER-ххххх-ххх format
            $orderNumber = "ORDER-{$firstPart}-{$secondPart}";

            // Check if this number already exists
            $exists = self::where('order_number', $orderNumber)->exists();
        } while ($exists);

        return $orderNumber;
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'orders';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'value',
        'company_id',
        'project_id',
        'order_number',
        'delivery_date',
        'actual_delivery_date',
        'is_active',
        'is_urgent',
        'order_amount',
        'agent_percentage',
        'curator_percentage',
        'agent_bonus',
        'curator_bonus',
        'partner_payment_status_id',
    ];

    /**
     * The model's default values for attributes.
     * Дефолтные проценты для закупок: агент 5%, куратор 5%
     *
     * @var array
     */
    protected $attributes = [
        'agent_percentage' => 5.00,
        'curator_percentage' => 5.00,
        'agent_bonus' => 0,
        'curator_bonus' => 0,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_urgent' => 'boolean',
        'delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'order_amount' => 'decimal:2',
        'agent_percentage' => 'decimal:2',
        'curator_percentage' => 'decimal:2',
        'agent_bonus' => 'decimal:2',
        'curator_bonus' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the company that owns the order.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the project that owns the order.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the positions for the order.
     */
    public function positions(): HasMany
    {
        return $this->hasMany(OrderPosition::class);
    }

    /**
     * Get the complaints for the order.
     */
    public function complaints()
    {
        return $this->hasMany(Complaint::class);
    }

    /**
     * Get the partner payment status.
     */
    public function partnerPaymentStatus(): BelongsTo
    {
        return $this->belongsTo(PartnerPaymentStatus::class, 'partner_payment_status_id');
    }

    /**
     * Get the agent bonus for this order.
     */
    public function agentBonus(): HasOne
    {
        return $this->hasOne(AgentBonus::class, 'order_id');
    }
}
