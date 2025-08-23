<?php

namespace App\Observers;

use App\Models\Industry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IndustryObserver
{
    /**
     * Handle the Industry "creating" event.
     */
    public function creating(Industry $industry): void
    {
        // Prevent cycles if parent_id is being set
        if ($industry->parent_id) {
            $this->preventCycles($industry);
        }

        // Compute depth and path
        $this->computeDepthAndPath($industry);
    }

    /**
     * Handle the Industry "updating" event.
     */
    public function updating(Industry $industry): void
    {
        $parentChanged = $industry->isDirty('parent_id');
        $slugChanged = $industry->isDirty('slug');

        // Prevent cycles if parent_id is being changed
        if ($parentChanged && $industry->parent_id) {
            $this->preventCycles($industry);
        }

        // Recompute depth and path if parent or slug changed
        if ($parentChanged || $slugChanged) {
            $this->computeDepthAndPath($industry);
        }
    }

    /**
     * Handle the Industry "updated" event.
     */
    public function updated(Industry $industry): void
    {
        $parentChanged = $industry->wasChanged('parent_id');
        $slugChanged = $industry->wasChanged('slug');

        // If parent_id or slug changed, update the entire subtree
        if ($parentChanged || $slugChanged) {
            $this->recomputeSubtree($industry);
        }
    }

    /**
     * Handle the Industry "saved" event.
     */
    public function saved(Industry $industry): void
    {
        $this->incrementCacheVersion();
    }

    /**
     * Handle the Industry "deleted" event.
     */
    public function deleted(Industry $industry): void
    {
        $this->incrementCacheVersion();
    }

    /**
     * Prevent creation of cycles in the industry hierarchy.
     */
    protected function preventCycles(Industry $industry): void
    {
        if (! $industry->parent_id) {
            return;
        }

        // Use recursive CTE to check if the target industry appears in the parent chain
        $sql = '
            WITH RECURSIVE parent_chain AS (
                SELECT parent_id, 1 as depth
                FROM industries 
                WHERE id = ? AND parent_id IS NOT NULL
                
                UNION ALL
                
                SELECT i.parent_id, pc.depth + 1
                FROM industries i
                JOIN parent_chain pc ON i.id = pc.parent_id
                WHERE pc.depth < 10 AND i.parent_id IS NOT NULL
            )
            SELECT COUNT(*) as cycle_count FROM parent_chain WHERE parent_id = ?
        ';

        $result = DB::selectOne($sql, [$industry->parent_id, $industry->id ?? 0]);

        if ($result->cycle_count > 0) {
            throw new \InvalidArgumentException('Cannot set parent: would create a cycle in the industry hierarchy');
        }
    }

    /**
     * Compute depth and path for an industry.
     */
    protected function computeDepthAndPath(Industry $industry): void
    {
        if (! $industry->parent_id) {
            // Root industry
            $industry->depth = 0;
            $industry->path = $industry->slug;
        } else {
            // Child industry - get parent's depth and path
            $parent = Industry::find($industry->parent_id);
            if (! $parent) {
                throw new \InvalidArgumentException('Parent industry not found');
            }

            $industry->depth = $parent->depth + 1;
            $industry->path = $parent->path.'/'.$industry->slug;
        }
    }

    /**
     * Recompute depth and path for all descendants of an industry.
     */
    protected function recomputeSubtree(Industry $industry): void
    {
        DB::transaction(function () use ($industry) {
            // Get all descendants using recursive CTE
            $sql = '
                WITH RECURSIVE industry_subtree AS (
                    SELECT id, parent_id, slug
                    FROM industries
                    WHERE parent_id = ?
                    
                    UNION ALL
                    
                    SELECT i.id, i.parent_id, i.slug
                    FROM industries i
                    JOIN industry_subtree ist ON i.parent_id = ist.id
                )
                SELECT id, parent_id, slug FROM industry_subtree
            ';

            $descendants = collect(DB::select($sql, [$industry->id]));

            if ($descendants->isEmpty()) {
                return;
            }

            // Build a map of all industries (including root) for efficient lookup
            $allIndustries = collect([$industry])
                ->concat(Industry::whereIn('id', $descendants->pluck('id'))->get())
                ->keyBy('id');

            // Update each descendant
            foreach ($descendants as $descendant) {
                $descendantModel = $allIndustries->get($descendant->id);
                if (! $descendantModel) {
                    continue;
                }

                $parent = $allIndustries->get($descendant->parent_id);
                if (! $parent) {
                    continue;
                }

                $newDepth = $parent->depth + 1;
                $newPath = $parent->path.'/'.$descendant->slug;

                // Update the database directly to avoid triggering observers
                DB::table('industries')
                    ->where('id', $descendant->id)
                    ->update([
                        'depth' => $newDepth,
                        'path' => $newPath,
                        'updated_at' => now(),
                    ]);

                // Update the model instance for potential further processing
                $descendantModel->depth = $newDepth;
                $descendantModel->path = $newPath;
            }
        });
    }

    /**
     * Increment the cache version for industries.
     */
    protected function incrementCacheVersion(): void
    {
        $currentVersion = Cache::get('industries:ver', 0);
        Cache::put('industries:ver', $currentVersion + 1);
    }
}
