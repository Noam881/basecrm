<?php

namespace Awove\Core;

class ContactDetails
{
    public const ATTRIBUTES = [
        'name', 'email', 'phone', 'company_id', 'secondary_phone', 'secondary_phone_label',
        'preferred_contact_method', 'preferred_contact_times', 'preferred_contact_days',
        'address', 'city', 'country', 'source',
    ];

    public const METHODS = [
        'email' => 'Email', 'phone' => 'Phone call', 'sms' => 'SMS',
        'whatsapp' => 'WhatsApp', 'any' => 'Any method',
    ];

    public const PHONE_LABELS = [
        'office' => 'Office', 'mobile' => 'Mobile', 'home' => 'Home', 'other' => 'Other',
    ];

    public const TIMES = [
        'mornings' => 'Mornings', 'afternoons' => 'Afternoons',
        'evenings' => 'Evenings', 'anytime' => 'Anytime',
    ];

    public const DAYS = [
        'monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday',
        'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday',
    ];

    public const SOURCES = [
        'referral' => 'Referral / word of mouth', 'google_search' => 'Google search',
        'website' => 'Website', 'facebook' => 'Facebook', 'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube' => 'YouTube',
        'google_ads' => 'Google Ads', 'social_ads' => 'Social media ad',
        'event' => 'Event / trade show', 'email_campaign' => 'Email campaign',
        'returning_customer' => 'Returning customer', 'outreach' => 'Direct outreach', 'other' => 'Other',
    ];

    public static function options(): array
    {
        $options = [];
        foreach (['methods' => self::METHODS, 'phone_labels' => self::PHONE_LABELS, 'times' => self::TIMES, 'days' => self::DAYS, 'sources' => self::SOURCES] as $key => $choices) {
            $options[$key] = array_map(fn ($value, $label) => compact('value', 'label'), array_keys($choices), array_values($choices));
        }

        return $options;
    }
}
