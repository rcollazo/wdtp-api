<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class WageReport extends Model
{
    use HasFactory;

    // Normalization constants
    const DEFAULT_HOURS_PER_WEEK = 40;

    const DEFAULT_SHIFT_HOURS = 8;

    const MIN_HOURLY_CENTS = 200;  // $2.00/hour minimum

    const MAX_HOURLY_CENTS = 20000;  // $200.00/hour maximum

    protected $fillable = [
        'user_id',
        'organization_id',
        'location_id',
        'job_title',
        'employment_type',
        'wage_period',
        'currency',
        'amount_cents',
        'normalized_hourly_cents',
        'hours_per_week',
        'effective_date',
        'tips_included',
        'unionized',
        'source',
        'status',
        'sanity_score',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'normalized_hourly_cents' => 'integer',
            'hours_per_week' => 'integer',
            'effective_date' => 'date',
            'tips_included' => 'boolean',
            'unionized' => 'boolean',
            'sanity_score' => 'integer',
        ];
    }

    /**
     * Relationships
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Scopes
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForOrganization(Builder $query, int|string $organizationIdOrSlug): Builder
    {
        if (is_numeric($organizationIdOrSlug)) {
            return $query->where('organization_id', $organizationIdOrSlug);
        }

        return $query->whereHas('organization', function (Builder $q) use ($organizationIdOrSlug) {
            $q->where('slug', $organizationIdOrSlug);
        });
    }

    public function scopeForLocation(Builder $query, int $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeSince(Builder $query, string $date): Builder
    {
        return $query->where('effective_date', '>=', $date);
    }

    public function scopeByJobTitle(Builder $query, string $jobTitle): Builder
    {
        return $query->where('job_title', 'ILIKE', '%'.$jobTitle.'%');
    }

    public function scopeInCurrency(Builder $query, string $currencyCode): Builder
    {
        return $query->where('currency', strtoupper($currencyCode));
    }

    public function scopeRange(Builder $query, int $minCents, int $maxCents): Builder
    {
        return $query->whereBetween('normalized_hourly_cents', [$minCents, $maxCents]);
    }

    public function scopeByEmploymentType(Builder $query, string $employmentType): Builder
    {
        return $query->where('employment_type', $employmentType);
    }

    /**
     * Spatial scope: Find wage reports near a given lat/lng within radius
     */
    public function scopeNearby(Builder $query, float $lat, float $lng, int $radiusMeters = 5000): Builder
    {
        return $query->select('wage_reports.*')
            ->selectRaw('ST_Distance(locations.point, ST_SetSRID(ST_MakePoint(?,?),4326)::geography) as distance_meters', [$lng, $lat])
            ->join('locations', 'wage_reports.location_id', '=', 'locations.id')
            ->whereRaw('ST_DWithin(locations.point, ST_SetSRID(ST_MakePoint(?,?),4326)::geography, ?)', [$lng, $lat, $radiusMeters])
            ->orderByRaw('ST_Distance(locations.point, ST_SetSRID(ST_MakePoint(?,?),4326)::geography)', [$lng, $lat]);
    }

    /**
     * Spatial scope: Add distance calculation to query
     */
    public function scopeWithDistance(Builder $query, float $lat, float $lng): Builder
    {
        return $query->selectRaw('ST_Distance(locations.point, ST_SetSRID(ST_MakePoint(?,?),4326)::geography) as distance_meters', [$lng, $lat])
            ->join('locations', 'wage_reports.location_id', '=', 'locations.id');
    }

    /**
     * Spatial scope: Order by distance from point
     */
    public function scopeOrderByDistance(Builder $query, float $lat, float $lng): Builder
    {
        return $query->join('locations', 'wage_reports.location_id', '=', 'locations.id')
            ->orderByRaw('ST_Distance(locations.point, ST_SetSRID(ST_MakePoint(?,?),4326)::geography)', [$lng, $lat]);
    }

    /**
     * Wage normalization engine - converts any wage period to hourly rate in cents
     * Uses integer-only math to avoid float precision issues
     */
    public static function normalizeToHourly(
        int $amountCents,
        string $period,
        ?int $hoursPerWeek = null,
        ?int $shiftHours = null
    ): int {
        $hoursPerWeek = $hoursPerWeek ?? self::DEFAULT_HOURS_PER_WEEK;
        $shiftHours = $shiftHours ?? self::DEFAULT_SHIFT_HOURS;

        $normalizedCents = match ($period) {
            'hourly' => $amountCents,
            'weekly' => intval($amountCents / $hoursPerWeek),
            'biweekly' => intval($amountCents / (2 * $hoursPerWeek)),
            'monthly' => intval(($amountCents * 12) / (52 * $hoursPerWeek)),
            'yearly' => intval($amountCents / (52 * $hoursPerWeek)),
            'per_shift' => intval($amountCents / $shiftHours),
            default => throw new InvalidArgumentException("Invalid wage period: {$period}")
        };

        // Ensure result is within reasonable bounds
        if ($normalizedCents < self::MIN_HOURLY_CENTS || $normalizedCents > self::MAX_HOURLY_CENTS) {
            throw new InvalidArgumentException(
                "Normalized hourly wage ({$normalizedCents} cents) is outside acceptable range"
            );
        }

        return $normalizedCents;
    }

    /**
     * Get the normalized hourly wage as a formatted money string
     */
    public function normalizedHourlyMoney(): string
    {
        return '$'.number_format($this->normalized_hourly_cents / 100, 2);
    }

    /**
     * Get the original wage amount as a formatted money string
     */
    public function originalAmountMoney(): string
    {
        return '$'.number_format($this->amount_cents / 100, 2);
    }

    /**
     * Check if this wage report is considered an outlier based on sanity score
     */
    public function isOutlier(): bool
    {
        return $this->sanity_score < -2;
    }

    /**
     * Check if this wage report seems suspiciously high
     */
    public function isSuspiciouslyHigh(): bool
    {
        return $this->normalized_hourly_cents > 10000; // > $100/hour
    }

    /**
     * Check if this wage report seems suspiciously low
     */
    public function isSuspiciouslyLow(): bool
    {
        return $this->normalized_hourly_cents < 725; // < $7.25/hour (federal minimum)
    }

    /**
     * Get display employment type
     */
    public function getEmploymentTypeDisplayAttribute(): string
    {
        return match ($this->employment_type) {
            'full_time' => 'Full Time',
            'part_time' => 'Part Time',
            'seasonal' => 'Seasonal',
            'contract' => 'Contract',
            default => ucfirst($this->employment_type),
        };
    }

    /**
     * Get display wage period
     */
    public function getWagePeriodDisplayAttribute(): string
    {
        return match ($this->wage_period) {
            'hourly' => 'Hourly',
            'weekly' => 'Weekly',
            'biweekly' => 'Bi-weekly',
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            'per_shift' => 'Per Shift',
            default => ucfirst($this->wage_period),
        };
    }

    /**
     * Get display status with proper capitalization
     */
    public function getStatusDisplayAttribute(): string
    {
        return ucfirst($this->status);
    }

    /**
     * Boot the model
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically calculate normalized hourly cents and derive organization on create/update
        static::creating(function (WageReport $wageReport) {
            // Derive organization_id from location if not set
            if (empty($wageReport->organization_id) && ! empty($wageReport->location_id)) {
                $location = Location::find($wageReport->location_id);
                if ($location && $location->organization_id) {
                    $wageReport->organization_id = $location->organization_id;
                }
            }

            // Calculate normalized hourly cents
            if (empty($wageReport->normalized_hourly_cents)) {
                $wageReport->normalized_hourly_cents = self::normalizeToHourly(
                    $wageReport->amount_cents,
                    $wageReport->wage_period,
                    $wageReport->hours_per_week
                );
            }
        });

        static::updating(function (WageReport $wageReport) {
            if ($wageReport->wasChanged(['amount_cents', 'wage_period', 'hours_per_week'])) {
                $wageReport->normalized_hourly_cents = self::normalizeToHourly(
                    $wageReport->amount_cents,
                    $wageReport->wage_period,
                    $wageReport->hours_per_week
                );
            }
        });
    }
}
