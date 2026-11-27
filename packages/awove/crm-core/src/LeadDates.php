<?php

namespace Awove\Core;

/** Business calendar precision is retained independently of the exact creation instant. */
final class LeadDates
{
    public static function calendarExpression(string $zone): string
    {
        $zone = self::zone($zone);

        // Do not round-trip a date through local midnight: some historical days were skipped.
        return "COALESCE(crm_leads.entered_on, (crm_leads.created_at AT TIME ZONE '$zone')::date)";
    }

    private static function zone(string $zone): string
    {
        if (! in_array($zone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException('A recognized display timezone is required.');
        }

        return str_replace("'", "''", $zone);
    }
}
