<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TechnicalSpecification extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

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

    protected $table = 'technical_specifications';

    protected $fillable = [
        'value',
        'project_id',
        'description',
        'comment',
        'is_active',
        'requires_approval',
        'is_approved',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->value)) {
                $model->value = self::generateTzNumber();
            }
        });
    }

    /**
     * Генерирует уникальный номер ТЗ в формате TZ-ABCD-1234 (буквы + цифры)
     */
    public static function generateTzNumber(): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        do {
            // Генерируем 4 случайные буквы
            $part1 = '';
            for ($i = 0; $i < 4; $i++) {
                $part1 .= $letters[random_int(0, 25)];
            }
            // Генерируем 4 случайные цифры
            $part2 = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $tzNumber = "TZ-{$part1}-{$part2}";
        } while (self::where('value', $tzNumber)->exists());

        return $tzNumber;
    }

    protected $casts = [
        'is_active' => 'boolean',
        'requires_approval' => 'boolean',
        'is_approved' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the project that owns the technical specification.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get all files associated with this technical specification.
     */
    public function files(): HasMany
    {
        return $this->hasMany(TechnicalSpecificationFile::class, 'technical_specification_id');
    }

    /**
     * Get sketch files associated with this technical specification.
     */
    public function sketches(): HasMany
    {
        return $this->files()->where('file_type', 'sketch');
    }

    /**
     * Get commercial offer files associated with this technical specification.
     */
    public function commercialOffers(): HasMany
    {
        return $this->files()->where('file_type', 'commercial_offer');
    }
}
