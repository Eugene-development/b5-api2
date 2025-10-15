<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPhone extends Model
{
    use HasUlids;

    protected $fillable = [
        'id',
        'client_id',
        'value',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Get the client that owns the phone.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
