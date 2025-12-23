<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class ProjectUser extends Pivot
{
    use HasUlids;

    /**
     * Константы ролей пользователей в проекте
     */
    const ROLE_AGENT = 'agent';
    const ROLE_CURATOR = 'curator';
    const ROLE_DESIGNER = 'designer';
    const ROLE_MANAGER = 'manager';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'project_user';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'project_id',
        'role',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the project relationship.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the project that owns the relationship.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Получить список доступных ролей
     */
    public static function getAvailableRoles(): array
    {
        return [
            self::ROLE_AGENT,
            self::ROLE_CURATOR,
            self::ROLE_DESIGNER,
            self::ROLE_MANAGER,
        ];
    }

    /**
     * Проверить, является ли роль валидной
     */
    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::getAvailableRoles());
    }
}
