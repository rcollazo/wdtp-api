<?php

namespace App\Observers;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

class OrganizationObserver
{
    /**
     * Handle the Organization "saving" event.
     * Normalize domain from website_url before saving.
     */
    public function saving(Organization $organization): void
    {
        // Extract and normalize domain from website_url if provided
        if ($organization->website_url) {
            $organization->domain = $this->extractDomain($organization->website_url);
        }
    }

    /**
     * Handle the Organization "saved" event.
     * Increment cache version after save.
     */
    public function saved(Organization $organization): void
    {
        Cache::increment('orgs:ver');
    }

    /**
     * Handle the Organization "deleted" event.
     * Increment cache version after delete.
     */
    public function deleted(Organization $organization): void
    {
        Cache::increment('orgs:ver');
    }

    /**
     * Extract bare hostname from various URL formats.
     */
    private function extractDomain(string $url): string
    {
        // Add protocol if missing to ensure proper parsing
        if (! preg_match('/^https?:\/\//', $url)) {
            $url = 'https://'.$url;
        }

        try {
            $parsed = parse_url($url);

            if (! isset($parsed['host'])) {
                return '';
            }

            $host = strtolower($parsed['host']);

            // Remove 'www.' prefix if present
            if (strpos($host, 'www.') === 0) {
                $host = substr($host, 4);
            }

            return $host;
        } catch (\Exception $e) {
            // Return empty string if URL parsing fails
            return '';
        }
    }
}
