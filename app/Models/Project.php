<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    use HasFactory, HasUlids;

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

    protected $table = 'projects';

    protected $fillable = [
        'value',
        'user_id',
        'city',
        'description',
        'is_active',
        'contract_name',
        'contract_date',
        'contract_amount',
        'agent_percentage',
        'planned_completion_date',
    ];

    protected $casts = [
        'contract_date' => 'date',
        'planned_completion_date' => 'date',
        'contract_amount' => 'decimal:2',
        'agent_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the agent associated with the project.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
