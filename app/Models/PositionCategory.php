<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PositionCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'industry_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'industry_id' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('name') && empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    /**
     * Get the industry that this position category belongs to.
     */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /**
     * Get the wage reports for this position category.
     *
     * @return HasMany<\App\Models\WageReport>
     */
    public function wageReports(): HasMany
    {
        return $this->hasMany(\App\Models\WageReport::class);
    }

    /**
     * Scope to get only active position categories.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter by industry.
     */
    public function scopeForIndustry(Builder $query, int $industryId): Builder
    {
        return $query->where('industry_id', $industryId);
    }

    /**
     * Scope to search position categories by name or description.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($term) {
            $q->where('position_categories.name', 'ILIKE', $term)
                ->orWhere('position_categories.description', 'ILIKE', $term);
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
