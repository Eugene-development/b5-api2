<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;


class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'bun',
        'is_active',
        'region',
        'user_id',
        'key',
        'company_id',
        'status_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'bun' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the legacy status attribute (virtual field for backward compatibility)
     * Returns 'active' or 'banned' based on the bun field
     */
    protected function statusLegacy(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn () => $this->bun ? 'banned' : 'active',
        );
    }

    /**
     * Ban the user
     */
    public function ban(): bool
    {
        return $this->update(['bun' => true]);
    }

    /**
     * Unban the user
     */
    public function unban(): bool
    {
        return $this->update(['bun' => false]);
    }

    /**
     * Check if user is banned
     */
    public function isBanned(): bool
    {
        return $this->bun === true;
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->bun === false;
    }

    /**
     * Get the company that the user belongs to.
     */
    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Get the status that the user belongs to.
     */
    public function status(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UserStatus::class, 'status_id');
    }
}
