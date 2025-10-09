<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Comment extends Model
{
    use HasFactory, HasUlids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'is_active',
        'value',
        'author_id',
        'author_name',
        'author_email',
        'author_ip',
        'is_approved',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
    ];

    /**
     * Get all of the entities that are assigned this comment.
     */
    public function commentables(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Project::class,
            'commentables',
            'comment_id',
            'commentable_id'
        );
    }
}
