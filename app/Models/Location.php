<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'address_line_1',
        'address_line_2',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'phone',
        'website_url',
        'description',
        'latitude',
        'longitude',
        'osm_id',
        'osm_type',
        'osm_data',
        'is_active',
        'is_verified',
        'verification_notes',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'osm_data' => 'array',
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    /**
     * Get the organization this location belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get all wage reports for this location.
     */
    public function wageReports(): HasMany
    {
        return $this->hasMany(WageReport::class);
    }

    /**
     * Scope to get only active locations.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only verified locations.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope to filter locations near a specific coordinate within a radius.
     *
     * @param  int  $radiusKm  Radius in kilometers (default: 10km)
     */
    public function scopeNear(Builder $query, float $latitude, float $longitude, int $radiusKm = 10): Builder
    {
        $radiusMeters = $radiusKm * 1000;

        return $query->whereRaw(
            'ST_DWithin(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$longitude, $latitude, $radiusMeters]
        );
    }

    /**
     * Scope to add distance calculation to query results.
     */
    public function scopeWithDistance(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query->selectRaw(
            'locations.*, ST_Distance(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_meters',
            [$longitude, $latitude]
        );
    }

    /**
     * Scope to order locations by distance from a specific coordinate.
     */
    public function scopeOrderByDistance(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query->orderByRaw(
            'ST_Distance(point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)',
            [$longitude, $latitude]
        );
    }

    /**
     * Scope to search locations by name, address, or city.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'ILIKE', $term)
                ->orWhere('address_line_1', 'ILIKE', $term)
                ->orWhere('city', 'ILIKE', $term);
        });
    }

    /**
     * Scope to search locations by name or category using PostgreSQL full-text search.
     * Returns results with text_rank column for relevance scoring.
     *
     * @param  string  $term  Search term to match against location names, addresses, and cities
     */
    public function scopeSearchByNameOrCategory(Builder $query, string $term): Builder
    {
        // Normalize search term for PostgreSQL tsquery
        // Convert spaces to '&' operator for multi-word queries
        $normalizedTerm = str_replace(' ', ' & ', trim($term));

        return $query->selectRaw(
            "locations.*,
            LEAST(
                ts_rank(
                    to_tsvector('english', coalesce(name, '') || ' ' || coalesce(address_line_1, '') || ' ' || coalesce(city, '')),
                    to_tsquery('english', ?),
                    1
                ),
                1.0
            ) as text_rank",
            [$normalizedTerm]
        )->whereRaw(
            "to_tsvector('english', coalesce(name, '') || ' ' || coalesce(address_line_1, '') || ' ' || coalesce(city, ''))
            @@ to_tsquery('english', ?)",
            [$normalizedTerm]
        );
    }

    /**
     * Scope to filter by city.
     */
    public function scopeInCity(Builder $query, string $city): Builder
    {
        return $query->where('city', 'ILIKE', $city);
    }

    /**
     * Scope to filter by state/province.
     */
    public function scopeInState(Builder $query, string $state): Builder
    {
        return $query->where('state_province', 'ILIKE', $state);
    }

    /**
     * Scope to filter by country.
     */
    public function scopeInCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', strtoupper($countryCode));
    }

    /**
     * Scope for default filters (active locations only).
     */
    public function scopeDefaultFilters(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the full address as a single string.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->state_province,
            $this->postal_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get the display name (falls back to address if no name).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: $this->address_line_1;
    }

    /**
     * Update the PostGIS point column when coordinates change.
     * This should be called after updating latitude/longitude.
     */
    public function updateSpatialPoint(): void
    {
        if ($this->latitude && $this->longitude) {
            DB::table('locations')
                ->where('id', $this->id)
                ->update([
                    'point' => DB::raw("ST_SetSRID(ST_MakePoint({$this->longitude}, {$this->latitude}), 4326)::geography"),
                ]);
        }
    }

    /**
     * Boot the model and register model events.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Update spatial point when location is created or updated
        static::created(function (Location $location) {
            $location->updateSpatialPoint();
        });

        static::updated(function (Location $location) {
            if ($location->wasChanged(['latitude', 'longitude'])) {
                $location->updateSpatialPoint();
            }
        });
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
