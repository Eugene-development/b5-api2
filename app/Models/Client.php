<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasUlids;

    protected $fillable = [
        'id',
        'name',
        'birthday',
        'ban',
        'status_id',
    ];

    protected $casts = [
        'birthday' => 'date',
        'ban' => 'boolean',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Get the phones for the client.
     */
    public function phones(): HasMany
    {
        return $this->hasMany(ClientPhone::class);
    }

    /**
     * Get the projects for the client.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'client_id');
    }
}
