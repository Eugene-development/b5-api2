<?php

namespace App\Models;

use App\Services\BonusCalculationService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contract extends Model
{
    use HasFactory, HasUlids;

    /**
     * The model's default values for attributes.
     * Дефолтные проценты для договоров: агент 3%, куратор 2%
     *
     * @var array
     */
    protected $attributes = [
        'agent_percentage' => 3.00,
        'curator_percentage' => 2.00,
        'agent_bonus' => 0,
        'curator_bonus' => 0,
        'is_active' => true,
    ];

    /**
     * Boot the model and add event listeners for automatic bonus recalculation.
     */
    protected static function boot()
    {
        parent::boot();

        // Применяем дефолтные значения процентов при создании
        static::creating(function ($contract) {
            // Генерируем уникальный номер договора, если не указан
            if (empty($contract->contract_number)) {
                do {
                    $letters = '';
                    for ($i = 0; $i < 4; $i++) {
                        $letters .= chr(rand(65, 90)); // A-Z
                    }
                    $digits = str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);
                    $contractNumber = 'DOC-' . $letters . '-' . $digits;
                } while (Contract::where('contract_number', $contractNumber)->exists());

                $contract->contract_number = $contractNumber;
            }

            // Если процент агента не указан или равен 0, устанавливаем дефолт 3%
            if (empty($contract->agent_percentage) || floatval($contract->agent_percentage) == 0) {
                $contract->agent_percentage = 3.00;
            }

            // Если процент куратора не указан или равен 0, устанавливаем дефолт 2%
            if (empty($contract->curator_percentage) || floatval($contract->curator_percentage) == 0) {
                $contract->curator_percentage = 2.00;
            }
        });

        // Автоматический пересчёт бонусов при сохранении договора
        static::saving(function ($contract) {
            // Убедимся, что проценты установлены перед расчетом бонусов
            if (empty($contract->agent_percentage) || floatval($contract->agent_percentage) == 0) {
                $contract->agent_percentage = 3.00;
            }
            if (empty($contract->curator_percentage) || floatval($contract->curator_percentage) == 0) {
                $contract->curator_percentage = 2.00;
            }

            app(BonusCalculationService::class)->recalculateContractBonuses($contract);
        });

        // Создаем запись в agent_bonuses при создании договора
        static::created(function ($contract) {
            $bonusService = app(\App\Services\BonusService::class);
            $bonusService->createBonusForContract($contract);
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
        'contract_date',
        'planned_completion_date',
        'actual_completion_date',
        'contract_amount',
        'agent_percentage',
        'curator_percentage',
        'agent_bonus',
        'curator_bonus',
        'partner_payment_date',
        'is_active',
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
        'agent_bonus' => 'decimal:2',
        'curator_bonus' => 'decimal:2',
        'is_active' => 'boolean',
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
     * Get the agent bonus for this contract (first/primary bonus).
     * @deprecated Use agentBonuses() to get all bonuses including referral bonuses
     */
    public function agentBonus(): HasOne
    {
        return $this->hasOne(AgentBonus::class, 'contract_id');
    }

    /**
     * Get all agent bonuses for this contract (agent + referral).
     */
    public function agentBonuses(): HasMany
    {
        return $this->hasMany(AgentBonus::class, 'contract_id');
    }

    /**
     * Get the status of the contract.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ContractStatus::class, 'status_id');
    }
}
