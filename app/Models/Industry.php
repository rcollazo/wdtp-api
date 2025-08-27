<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Industry extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'depth',
        'path',
        'sort',
        'is_active',
        'visible_in_ui',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'visible_in_ui' => 'boolean',
        ];
    }

    /**
     * Get the parent industry.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Industry::class, 'parent_id');
    }

    /**
     * Get the child industries.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Industry::class, 'parent_id')->orderBy('sort');
    }

    // Note: businesses() relationship will be added when Business model is implemented

    /**
     * Scope to get only root industries (no parent).
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope to get only active industries.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only visible industries.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visible_in_ui', true);
    }

    /**
     * Scope for default filters (active and visible).
     */
    public function scopeDefaultFilters(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('visible_in_ui', true);
    }

    /**
     * Scope to find industry by slug.
     */
    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }

    /**
     * Scope to search industries by name or slug.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'ILIKE', $term)
                ->orWhere('slug', 'ILIKE', $term);
        });
    }

    /**
     * Get breadcrumb trail for this industry using recursive CTE.
     */
    public function breadcrumbs(): Collection
    {
        $sql = '
            WITH RECURSIVE industry_path AS (
                SELECT id, name, slug, parent_id, 0 as level
                FROM industries 
                WHERE id = ? AND is_active = true AND visible_in_ui = true
                
                UNION ALL
                
                SELECT i.id, i.name, i.slug, i.parent_id, ip.level + 1
                FROM industries i
                JOIN industry_path ip ON i.id = ip.parent_id
                WHERE i.is_active = true AND i.visible_in_ui = true
            )
            SELECT name, slug FROM industry_path 
            ORDER BY level DESC
        ';

        $results = DB::select($sql, [$this->id]);

        return collect($results)->map(function ($item) {
            return (object) [
                'name' => $item->name,
                'slug' => $item->slug,
            ];
        });
    }

    /**
     * Check if this is a root industry.
     */
    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * Get the full human-readable path.
     */
    public function getFullPath(): string
    {
        if ($this->isRoot()) {
            return $this->name;
        }

        $breadcrumbs = $this->breadcrumbs();

        return $breadcrumbs->pluck('name')->implode(' > ');
    }

    /**
     * Convert flat collection to nested tree structure.
     */
    public static function buildTree(Collection $industries): Collection
    {
        $indexed = $industries->keyBy('id');
        $tree = collect();

        foreach ($industries as $industry) {
            if (is_null($industry->parent_id)) {
                // This is a root industry
                $tree->push($industry);
            } else {
                // This is a child industry
                $parent = $indexed->get($industry->parent_id);
                if ($parent) {
                    if (! isset($parent->nested_children)) {
                        $parent->nested_children = collect();
                    }
                    $parent->nested_children->push($industry);
                }
            }
        }

        // Recursively sort children by sort order
        $sortTree = function ($items) use (&$sortTree) {
            return $items->sortBy('sort')->map(function ($item) use ($sortTree) {
                if (isset($item->nested_children)) {
                    $item->nested_children = $sortTree($item->nested_children);
                }

                return $item;
            })->values();
        };

        return $sortTree($tree);
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
