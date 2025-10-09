<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'name',
        'contract_number',
        'contract_date',
        'planned_completion_date',
        'contract_amount',
        'agent_percentage',
        'is_active',
        'address',
    ];

    protected $casts = [
        'contract_date' => 'date',
        'planned_completion_date' => 'date',
        'contract_amount' => 'decimal:2',
        'agent_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
