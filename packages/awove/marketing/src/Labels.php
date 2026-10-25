<?php

namespace Awove\Marketing;

use Awove\Core\Installation;

class Labels
{
    public static function statuses(string $kind): array
    {
        $base = $kind === 'campaigns' ? ['planned' => 'Planned', 'active' => 'Active', 'completed' => 'Completed', 'paused' => 'Paused'] : ['idea' => 'Idea', 'draft' => 'Draft', 'ready' => 'Ready', 'published' => 'Published'];
        $labels = array_replace($base, array_intersect_key(Installation::manifest()['marketing_'.$kind.'_statuses'] ?? [], $base));
        foreach ($labels as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw new \RuntimeException('Invalid Marketing status label.');
            }
        }

        return $labels;
    }

    public const ATTRIBUTION = ['platform' => 'Media / platform', 'landing_page_url' => 'Website / landing page', 'campaign_id' => 'Campaign ID', 'campaign_name' => 'Campaign name', 'ad_id' => 'Ad ID', 'ad_name' => 'Ad name', 'referrer_name' => 'Referred by', 'utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'UTM campaign', 'utm_content' => 'UTM content'];

    public const CHANNELS = ['website', 'facebook', 'instagram', 'linkedin', 'google', 'referral', 'email', 'direct', 'other'];
}
