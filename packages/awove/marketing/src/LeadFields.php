<?php

namespace Awove\Marketing;

use Illuminate\Validation\Rule;

/** Canonical, module-owned JSONB keys for lead attribution and qualification. */
final class LeadFields
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
        'utm_term' => 'UTM term',
        'fbclid' => 'Facebook click ID',
        'gclid' => 'Google click ID',
        'referrer' => 'Referrer URL',
        'marketing_consent' => 'Marketing consent',
        'qualification_reason' => 'Enquiry reason',
        'qualification_business_description' => 'Business description',
        'qualification_improvement_topics' => 'Areas to improve',
        'qualification_help_type' => 'Type of help requested',
        'qualification_current_handler' => 'Who handles this today',
        'qualification_desired_outcome' => 'Desired call outcome',
        'qualification_current_processes' => 'Current processes and tools',
    ];

    /** Source-header aliases describe equivalence; this does not import customer rows. */
    public const ALIASES = [
        'landing_page' => 'landing_page_url',
        '- utm_campaign' => 'utm_campaign',
        '- utm_term' => 'utm_term',
        'הסכמה אופציונלית לקבלת תוכן שיווקי' => 'marketing_consent',
        'שאלון: סיבת הפנייה' => 'qualification_reason',
        'שאלון: תיאור העסק' => 'qualification_business_description',
        'שאלון: נושאים לשיפור' => 'qualification_improvement_topics',
        'שאלון: סוג העזרה המבוקשת' => 'qualification_help_type',
        'שאלון: מי מטפל כיום' => 'qualification_current_handler',
        'שאלון: תוצאה רצויה מהשיחה' => 'qualification_desired_outcome',
        'שאלון: תהליך וכלים כיום' => 'qualification_current_processes',
    ];

    /** @return array<string,array{label:string,type:string,max_length:int|null,nullable:bool,options:list<string>}> */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::LABELS as $key => $label) {
            $type = match (true) {
                $key === 'marketing_consent' => 'boolean',
                str_starts_with($key, 'qualification_') => 'textarea',
                in_array($key, ['landing_page_url', 'referrer'], true) => 'url',
                $key === 'platform' => 'select',
                default => 'text',
            };
            $schema[$key] = [
                'label' => $label,
                'type' => $type,
                'max_length' => match ($type) {
                    'boolean' => null,
                    'textarea' => 5000,
                    'url' => 2048,
                    default => in_array($key, ['fbclid', 'gclid'], true) ? 500 : 200,
                },
                'nullable' => true,
                'options' => $type === 'select' ? Labels::CHANNELS : [],
            ];
        }

        return $schema;
    }

    public static function rules(): array
    {
        $rules = [];
        foreach (self::schema() as $key => $field) {
            $rules['fields.'.$key] = match ($field['type']) {
                'boolean' => ['nullable', 'boolean'],
                'url' => ['nullable', 'url:http,https', 'max:'.$field['max_length']],
                'select' => ['nullable', Rule::in($field['options'])],
                default => ['nullable', 'string', 'max:'.$field['max_length']],
            };
        }

        return $rules;
    }
}
