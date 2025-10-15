<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Company extends Model
{
    use HasFactory, HasUlids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'legal_name',
        'inn',
        'ban',
        'is_active',
        'region',
        'status_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'ban' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the status of the company.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(CompanyStatus::class, 'status_id');
    }

    /**
     * Get the phones for the company.
     */
    public function phones(): HasMany
    {
        return $this->hasMany(CompanyPhone::class);
    }

    /**
     * Get the emails for the company.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(CompanyEmail::class);
    }
}
