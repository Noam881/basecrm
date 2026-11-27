import assert from "node:assert/strict";
import test from "node:test";
import {
    formatCalendarDate,
    formatTimestamp,
    formatLeadCreated,
} from "../../packages/awove/crm-core/resources/js/dates.ts";

for (const locale of ["en-US", "he-IL"]) {
    test(`${locale} business entry dates retain calendar precision in every display timezone`, () => {
        for (const zone of Intl.supportedValuesOf("timeZone")) {
            for (const day of ["2020-02-29", "2011-12-30", "2026-03-27"]) {
                const expected = new Intl.DateTimeFormat(locale, {
                    dateStyle: "medium",
                    timeZone: "UTC",
                }).format(new Date(`${day}T00:00:00Z`));
                assert.equal(
                    formatLeadCreated(
                        { entered_on: day, created_at: "2026-11-27T12:34:56Z" },
                        locale,
                        zone,
                    ),
                    expected,
                );
            }
        }
    });
    test(`${locale} undated leads retain the normal exact timestamp display fallback`, () => {
        for (const zone of [
            "Pacific/Honolulu",
            "Pacific/Kiritimati",
            "Asia/Jerusalem",
        ]) {
            const instant = "2020-02-29T09:30:52.123456Z";
            const expected = new Intl.DateTimeFormat(locale, {
                dateStyle: "medium",
                timeStyle: "short",
                timeZone: zone,
            }).format(new Date(instant));
            assert.equal(
                formatLeadCreated(
                    { entered_on: null, created_at: instant },
                    locale,
                    zone,
                ),
                expected,
            );
            assert.equal(
                formatLeadCreated({ created_at: instant }, locale, zone),
                formatTimestamp(instant, locale, zone),
            );
        }
    });
}

test("invalid calendar values never turn into a normalized day or fabricated timestamp", () => {
    for (const value of [
        "2020-02-30",
        "2020-2-29",
        "2020-02-29T12:00:00Z",
        "tomorrow",
        "",
    ]) {
        assert.equal(formatCalendarDate(value, "en-US"), value);
    }
});
