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
     * Get the status attribute (virtual field for backward compatibility)
     */
    protected function status(): \Illuminate\Database\Eloquent\Casts\Attribute
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
}
