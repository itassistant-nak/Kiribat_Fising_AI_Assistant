<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class AdvisoryZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'concern',
        'evidence',
        'classification',
        'status',
        'polygon_geojson',
        'source',
        'review_date',
        'created_by_user_id',
        'updated_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'published_at',
    ];

    protected $casts = [
        'polygon_geojson' => 'array',
        'review_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * Scope a query to only include published advisory zones.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope a query to include active warnings (approved or published).
     */
    public function scopeActiveWarnings(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Affected species associated with this advisory zone.
     */
    public function species(): BelongsToMany
    {
        return $this->belongsToMany(Species::class, 'advisory_zone_species')
            ->withPivot('risk_level', 'notes')
            ->withTimestamps();
    }

    /**
     * Author/Editor who created the advisory zone.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Editor who last updated the advisory zone.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Administrator who approved and published the advisory zone.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Audit log history for this advisory zone.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AdvisoryAuditLog::class)->orderBy('created_at', 'desc');
    }
}
