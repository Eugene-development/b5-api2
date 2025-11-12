<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        'description',
        'contract_number',
        'contract_date',
        'planned_completion_date',
        'contract_amount',
        'agent_percentage',
        'is_active',
        'address',
        'user_id',
        'client_id',
        'status_id',
    ];

    protected $appends = ['contract_name', 'region'];

    protected $casts = [
        'contract_date' => 'date',
        'planned_completion_date' => 'date',
        'contract_amount' => 'decimal:2',
        'agent_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the phones for the project.
     */
    public function phones(): HasMany
    {
        return $this->hasMany(ProjectPhone::class);
    }

    /**
     * Get the agent relationship.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the status relationship.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    /**
     * Get the client relationship.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the users who have accepted this project.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_user', 'project_id', 'user_id')
                    ->using(ProjectUser::class)
                    ->withTimestamps();
    }

    /**
     * Get the sketches for the project.
     */
    public function sketches(): HasMany
    {
        return $this->hasMany(ProjectSketch::class)->orderBy('order');
    }

    /**
     * Get the offers for the project.
     */
    public function offers(): HasMany
    {
        return $this->hasMany(ProjectOffer::class)->orderBy('order');
    }

    /**
     * Accessor for 'contract_name' field (maps to 'contract_number').
     */
    public function getContractNameAttribute()
    {
        return $this->attributes['contract_number'] ?? null;
    }

    /**
     * Mutator for 'contract_name' field (maps to 'contract_number').
     */
    public function setContractNameAttribute($value)
    {
        $this->attributes['contract_number'] = $value;
    }

    /**
     * Accessor for 'region' field (maps to address for backward compatibility).
     */
    public function getRegionAttribute()
    {
        return $this->attributes['address'] ?? null;
    }

    /**
     * Mutator for 'region' field (maps to address for backward compatibility).
     */
    public function setRegionAttribute($value)
    {
        $this->attributes['address'] = $value;
    }
}
