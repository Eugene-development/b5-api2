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
        'ban',
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
            'ban' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the legacy status attribute (virtual field for backward compatibility)
     * Returns 'active' or 'banned' based on the ban field
     */
    protected function statusLegacy(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn () => $this->ban ? 'banned' : 'active',
        );
    }

    /**
     * Ban the user
     */
    public function ban(): bool
    {
        return $this->update(['ban' => true]);
    }

    /**
     * Unban the user
     */
    public function unban(): bool
    {
        return $this->update(['ban' => false]);
    }

    /**
     * Check if user is banned
     */
    public function isBanned(): bool
    {
        return $this->ban === true;
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->ban === false;
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

    /**
     * Get the phones for the user.
     */
    public function phones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserPhone::class);
    }
}
