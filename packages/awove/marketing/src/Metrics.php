<?php

namespace Awove\Marketing;

use Awove\Core\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class Metrics
{
    public static function cpl(string $spend, string|int $leads): ?string
    {
        return (string) $leads === '0' ? null : (string) BigDecimal::of($spend)->dividedBy((string) $leads, 2, RoundingMode::HalfUp);
    }

    public static function totals($query): array
    {
        $row = (clone $query)->reorder()->select([])->selectRaw('COALESCE(SUM(actual_spend),0)::text AS spend, COALESCE(SUM(reported_leads),0)::text AS leads')->first();
        $spend = Money::sum([$row?->getAttribute('spend') ?? '0']);
        $leads = $row?->getAttribute('leads') ?? '0';

        return ['actual_spend' => $spend, 'reported_leads' => $leads, 'cost_per_lead' => self::cpl($spend, $leads)];
    }
}
