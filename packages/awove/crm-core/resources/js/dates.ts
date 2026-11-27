/** Calendar dates never pass through a viewer's timezone or acquire a source clock. */
export function formatCalendarDate(value: string, locale: string): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    const day = new Date(`${value}T00:00:00.000Z`);
    if (Number.isNaN(day.valueOf()) || day.toISOString().slice(0, 10) !== value)
        return value;
    return new Intl.DateTimeFormat(locale, {
        dateStyle: "medium",
        timeZone: "UTC",
    }).format(day);
}

export function formatTimestamp(
    value: string,
    locale: string,
    zone?: string,
): string {
    const instant = new Date(value);
    if (Number.isNaN(instant.valueOf())) return value;
    return new Intl.DateTimeFormat(locale, {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: zone,
    }).format(instant);
}

export function formatLeadCreated(
    lead: { entered_on?: string | null; created_at: string },
    locale: string,
    zone?: string,
): string {
    return lead.entered_on
        ? formatCalendarDate(lead.entered_on, locale)
        : formatTimestamp(lead.created_at, locale, zone);
}
