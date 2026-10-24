<?php

namespace Awove\Core;

use Illuminate\Validation\Rule;

class LeadAttribution
{
    public const LABELS = [
        'platform' => 'Media / platform',
        'landing_page_url' => 'Website / landing page',
        'campaign_id' => 'Campaign ID',
        'campaign_name' => 'Campaign name',
        'ad_id' => 'Ad ID',
        'ad_name' => 'Ad name',
        'referrer_name' => 'Referred by',
        'utm_source' => 'UTM source',
        'utm_medium' => 'UTM medium',
        'utm_campaign' => 'UTM campaign',
        'utm_content' => 'UTM content',
    ];

    public const PLATFORMS = ['website', 'facebook', 'instagram', 'linkedin', 'google', 'referral', 'direct', 'other'];

    public static function rules(): array
    {
        $rules = ['attribution' => 'sometimes|nullable|array:'.implode(',', array_keys(self::LABELS))];
        foreach (self::LABELS as $field => $label) {
            $rules['attribution.'.$field] = $field === 'landing_page_url'
                ? ['nullable', 'url:http,https', 'max:2048']
                : ($field === 'platform' ? ['nullable', Rule::in(self::PLATFORMS)] : ['nullable', 'string', 'max:200']);
        }

        return $rules;
    }

    public static function validate(array $data): array
    {
        if (array_key_exists('attribution', $data)) {
            validator($data, self::rules())->validate();
            if (is_array($data['attribution'])) {
                $data['attribution'] = array_map(static fn ($value) => is_string($value) ? (trim($value) ?: null) : $value, $data['attribution']);
            }
        }

        return $data;
    }
}
