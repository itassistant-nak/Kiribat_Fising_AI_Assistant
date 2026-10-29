<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvisoryAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'advisory_zone_id',
        'user_id',
        'action',
        'changes',
        'notes',
        'ip_address',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AdvisoryZone::class, 'advisory_zone_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
