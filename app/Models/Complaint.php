<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Complaint extends Model
{
    use HasUlids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'contract_id',
        'order_id',
        'title',
        'description',
        'is_active',
        'planned_resolution_date',
        'responsible_person',
        'guilty_party',
        'priority',
        'status',
        'resolution_notes',
        'actual_resolution_date',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'planned_resolution_date' => 'date',
        'actual_resolution_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the contract that owns the complaint.
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Get the order that owns the complaint.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
