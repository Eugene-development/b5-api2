<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Contract extends Model
{
    use \App\Models\Concerns\HasFinancialTransactions, HasFactory, HasUlids;

    /**
     * The model's default values for attributes.
     * Дефолтные проценты для договоров: агент 3%, куратор 2%
     *
     * @var array
     */
    protected $attributes = [
        'agent_percentage' => 3.00,
        'curator_percentage' => 2.00,
        'is_active' => true,
        'is_urgent' => false,
    ];

    /**
     * Boot the model and add event listeners for automatic bonus recalculation.
     */
    protected static function boot()
    {
        parent::boot();
        static::saving(function ($source) {
            if ($source->exists && $source->isDirty('project_id') && $source->bonuses()->exists()) {
                throw new \App\Exceptions\FinancialException('Нельзя переносить источник с начисленными бонусами в другой проект.');
            }
        });
        static::deleting(function ($source) {
            \App\Services\FinancialLedger::assertUncommitted($source->bonuses());
            $source->bonuses()->delete();
        });

        // Применяем дефолтные значения процентов при создании
        static::creating(function ($contract) {
            // Генерируем уникальный номер договора, если не указан
            if (empty($contract->contract_number)) {
                do {
                    $letters = '';
                    for ($i = 0; $i < 4; $i++) {
                        $letters .= chr(rand(65, 90)); // A-Z
                    }
                    $digits = str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
                    $contractNumber = 'DOC-'.$letters.'-'.$digits;
                } while (Contract::where('contract_number', $contractNumber)->exists());

                $contract->contract_number = $contractNumber;
            }

            // Если процент агента не указан или равен 0, устанавливаем дефолт 3%
            if ($contract->agent_percentage === null) {
                $contract->agent_percentage = 3.00;
            }

            // Если процент куратора не указан или равен 0, устанавливаем дефолт 2%
            if ($contract->curator_percentage === null) {
                $contract->curator_percentage = 2.00;
            }
        });

        // Автоматический пересчёт бонусов при сохранении договора
        static::saving(function ($contract) {
            // Убедимся, что проценты установлены перед расчетом бонусов
            if ($contract->agent_percentage === null) {
                $contract->agent_percentage = 3.00;
            }
            if ($contract->curator_percentage === null) {
                $contract->curator_percentage = 2.00;
            }
        });

        // Создаем записи в bonuses при создании договора
        static::created(function ($contract) {
            $bonusService = app(\App\Services\BonusService::class);
            $bonusService->createBonusForContract($contract);
        });

        // Обновляем бонусы при изменении договора
        static::updated(function ($contract) {
            $bonusService = app(\App\Services\BonusService::class);
            $bonusService->updateBonusesForContract($contract);
        });

        // Обновляем updated_at проекта при изменении договора
        static::saved(function ($contract) {
            if ($contract->project) {
                $contract->project->touch();
            }
        });

        // Обновляем updated_at проекта при удалении договора
        static::deleted(function ($contract) {
            if ($contract->project) {
                $contract->project->touch();
            }
        });
    }

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'contracts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'project_id',
        'company_id',
        'contract_number',
        'value', // Номер договора от фабрики (опциональный)
        'contract_date',
        'planned_completion_date',
        'actual_completion_date',
        'contract_amount',
        'agent_percentage',
        'curator_percentage',
        'partner_payment_date',
        'is_active',
        'is_urgent',
        'status_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'contract_date' => 'date',
        'planned_completion_date' => 'date',
        'actual_completion_date' => 'date',
        'contract_amount' => 'decimal:2',
        'agent_percentage' => 'decimal:2',
        'curator_percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'is_urgent' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the project that owns the contract.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get the company that owns the contract.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Get the complaints for the contract.
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
     * Get the agent bonus for this contract.
     */
    public function agentBonus(): HasOne
    {
        return $this->hasOne(Bonus::class, 'contract_id')
            ->where('recipient_type', Bonus::RECIPIENT_AGENT);
    }

    /**
     * Get the curator bonus for this contract.
     */
    public function curatorBonus(): HasOne
    {
        return $this->hasOne(Bonus::class, 'contract_id')
            ->where('recipient_type', Bonus::RECIPIENT_CURATOR);
    }

    /**
     * Get all bonuses for this contract (agent, curator, referral).
     */
    public function bonuses(): HasMany
    {
        return $this->hasMany(Bonus::class, 'contract_id');
    }

    /**
     * Get all agent bonuses for this contract (agent + referral).
     *
     * @deprecated Use bonuses() instead
     */
    public function agentBonuses(): HasMany
    {
        return $this->hasMany(Bonus::class, 'contract_id');
    }

    /**
     * Get the status of the contract.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ContractStatus::class, 'status_id');
    }

    /**
     * Get the comments for the contract.
     */
    public function comments(): MorphToMany
    {
        return $this->morphToMany(Comment::class, 'commentable', 'commentables', 'commentable_id', 'comment_id');
    }

    public function agentBonusAmount(): float
    {
        return (float) $this->bonuses()->where('recipient_type', 'agent')->sum('commission_amount');
    }

    public function curatorBonusAmount(): float
    {
        return (float) $this->bonuses()->where('recipient_type', 'curator')->sum('commission_amount');
    }
}
