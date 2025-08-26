<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="WageStatistics",
 *
 *     @OA\Property(property="count", type="integer", description="Total number of wage reports"),
 *     @OA\Property(property="average_cents", type="integer", description="Average normalized hourly wage in cents"),
 *     @OA\Property(property="median_cents", type="integer", description="Median normalized hourly wage in cents"),
 *     @OA\Property(property="min_cents", type="integer", description="Minimum normalized hourly wage in cents"),
 *     @OA\Property(property="max_cents", type="integer", description="Maximum normalized hourly wage in cents"),
 *     @OA\Property(property="std_deviation_cents", type="integer", description="Standard deviation in cents"),
 *     @OA\Property(property="percentiles", type="object",
 *         @OA\Property(property="p25", type="integer", description="25th percentile in cents"),
 *         @OA\Property(property="p50", type="integer", description="50th percentile (median) in cents"),
 *         @OA\Property(property="p75", type="integer", description="75th percentile in cents"),
 *         @OA\Property(property="p90", type="integer", description="90th percentile in cents")
 *     ),
 *     @OA\Property(property="employment_types", type="array",
 *
 *         @OA\Items(type="object",
 *
 *             @OA\Property(property="type", type="string"),
 *             @OA\Property(property="type_display", type="string"),
 *             @OA\Property(property="count", type="integer"),
 *             @OA\Property(property="average_cents", type="integer")
 *         )
 *     ),
 *     @OA\Property(property="job_titles", type="array",
 *
 *         @OA\Items(type="object",
 *
 *             @OA\Property(property="title", type="string"),
 *             @OA\Property(property="count", type="integer"),
 *             @OA\Property(property="average_cents", type="integer")
 *         )
 *     ),
 *     @OA\Property(property="geographic_distribution", type="array",
 *
 *         @OA\Items(type="object",
 *
 *             @OA\Property(property="city", type="string"),
 *             @OA\Property(property="state", type="string"),
 *             @OA\Property(property="count", type="integer"),
 *             @OA\Property(property="average_cents", type="integer")
 *         )
 *     ),
 *     @OA\Property(property="display", type="object",
 *         @OA\Property(property="average", type="string", description="Formatted average wage"),
 *         @OA\Property(property="median", type="string", description="Formatted median wage"),
 *         @OA\Property(property="min", type="string", description="Formatted minimum wage"),
 *         @OA\Property(property="max", type="string", description="Formatted maximum wage"),
 *         @OA\Property(property="std_deviation", type="string", description="Formatted standard deviation")
 *     )
 * )
 */
class WageStatisticsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'count' => $data['count'],
            'average_cents' => $data['average_cents'],
            'median_cents' => $data['median_cents'],
            'min_cents' => $data['min_cents'],
            'max_cents' => $data['max_cents'],
            'std_deviation_cents' => $data['std_deviation_cents'],

            'percentiles' => [
                'p25' => $data['p25'],
                'p50' => $data['p50'],
                'p75' => $data['p75'],
                'p90' => $data['p90'],
            ],

            'employment_types' => $this->formatEmploymentTypes($data['employment_types'] ?? []),
            'job_titles' => $data['job_titles'] ?? [],
            'geographic_distribution' => $data['geographic_distribution'] ?? [],

            'display' => [
                'average' => $this->formatMoney($data['average_cents']),
                'median' => $this->formatMoney($data['median_cents']),
                'min' => $this->formatMoney($data['min_cents']),
                'max' => $this->formatMoney($data['max_cents']),
                'std_deviation' => $this->formatMoney($data['std_deviation_cents']),
            ],
        ];
    }

    /**
     * Format employment types with display names
     */
    private function formatEmploymentTypes(array $employmentTypes): array
    {
        return array_map(function ($item) {
            $item['type_display'] = match ($item['type']) {
                'full_time' => 'Full Time',
                'part_time' => 'Part Time',
                'seasonal' => 'Seasonal',
                'contract' => 'Contract',
                default => ucfirst($item['type']),
            };

            return $item;
        }, $employmentTypes);
    }

    /**
     * Format cents to money string
     */
    private function formatMoney(?int $cents): string
    {
        if ($cents === null) {
            return '$0.00';
        }

        return '$'.number_format($cents / 100, 2);
    }
}
