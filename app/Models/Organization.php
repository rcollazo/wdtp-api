<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'website_url',
        'domain',
        'description',
        'logo_url',
        'primary_industry_id',
        'status',
        'verification_status',
        'created_by',
        'verified_by',
        'verified_at',
        'locations_count',
        'wage_reports_count',
        'is_active',
        'visible_in_ui',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'visible_in_ui' => 'boolean',
            'verified_at' => 'datetime',
            'locations_count' => 'integer',
            'wage_reports_count' => 'integer',
        ];
    }

    /**
     * Get the primary industry this organization belongs to.
     */
    public function primaryIndustry(): BelongsTo
    {
        return $this->belongsTo(Industry::class, 'primary_industry_id');
    }

    /**
     * Get all locations for this organization.
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * Get the user who created this organization.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who verified this organization.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Scope to get only active organizations.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only visible organizations.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visible_in_ui', true);
    }

    /**
     * Scope to get only approved organizations.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get only verified organizations.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }

    /**
     * Scope to find organization by slug.
     */
    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }

    /**
     * Scope to search organizations by name, domain, or legal name with relevance weighting.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($term) {
            // Primary match on name (highest relevance)
            $q->where('name', 'ILIKE', $term)
                // Secondary match on domain (medium relevance)
                ->orWhere('domain', 'ILIKE', $term)
                // Tertiary match on legal_name (lowest relevance)
                ->orWhere('legal_name', 'ILIKE', $term);
        })->orderByRaw('
            CASE 
                WHEN name ILIKE ? THEN 1
                WHEN domain ILIKE ? THEN 2  
                WHEN legal_name ILIKE ? THEN 3
                ELSE 4
            END
        ', [$term, $term, $term]);
    }

    /**
     * Scope to filter by industry (accepts both ID and slug).
     */
    public function scopeInIndustry(Builder $query, string|int $industry): Builder
    {
        if (is_numeric($industry)) {
            return $query->where('primary_industry_id', $industry);
        }

        return $query->whereHas('primaryIndustry', function (Builder $q) use ($industry) {
            $q->where('slug', $industry);
        });
    }

    /**
     * Scope to filter organizations that have locations.
     */
    public function scopeHasLocations(Builder $query): Builder
    {
        return $query->where('locations_count', '>', 0);
    }

    /**
     * Scope for default filters (active, visible, and approved).
     */
    public function scopeDefaultFilters(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('visible_in_ui', true)
            ->where('status', 'active');
    }

    /**
     * Get the display name attribute (name or fallback to legal_name).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: ($this->legal_name ?: '');
    }

    /**
     * Custom route model binding to support both ID and slug.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        // If the field is explicitly set, use it
        if ($field) {
            return $this->where($field, $value)->first();
        }

        // Try to resolve by ID first (numeric values)
        if (is_numeric($value)) {
            $result = $this->where('id', $value)->first();
            if ($result) {
                return $result;
            }
        }

        // Fall back to slug resolution
        return $this->where('slug', $value)->first();
    }
}
