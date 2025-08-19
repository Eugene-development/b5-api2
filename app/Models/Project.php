<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    protected $table = 'projects';

    protected $fillable = [
        'name',
        'agent_id',
        'city',
        'description',
        'contract_number',
        'contract_date',
        'contract_amount',
        'agent_rate',
        'agent_rate_type',
        'planned_completion',
        'status',
    ];

    protected $casts = [
        'contract_date' => 'date',
        'planned_completion' => 'date',
        'contract_amount' => 'decimal:2',
        'agent_rate' => 'decimal:2',
    ];

    /**
     * Get the agent associated with the project.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
