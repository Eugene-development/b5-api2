<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class TechnicalSpecificationFile extends Model
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

    protected $table = 'technical_specification_files';

    protected $fillable = [
        'technical_specification_id',
        'file_type',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the technical specification that owns the file.
     */
    public function technicalSpecification(): BelongsTo
    {
        return $this->belongsTo(TechnicalSpecification::class, 'technical_specification_id');
    }

    /**
     * Get the user who uploaded the file.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get a temporary download URL for the file (valid for 5 minutes).
     *
     * @return string
     */
    public function getDownloadUrlAttribute(): string
    {
        // For Yandex Cloud, construct public URL
        $bucket = config('filesystems.disks.yandex.bucket');
        $endpoint = config('filesystems.disks.yandex.endpoint');
        return rtrim($endpoint, '/') . '/' . $bucket . '/' . $this->file_path;
    }
}
