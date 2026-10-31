import assert from "node:assert/strict";
import test from "node:test";
import { exactDecimal } from "../../packages/awove/crm-core/resources/js/components/table/decimal.ts";

test("table preserves exact database goal, money and quantity decimals", () => {
    assert.equal(
        exactDecimal("99999999999999.9999"),
        "99,999,999,999,999.9999",
    );
    assert.equal(
        exactDecimal("-99999999999999.9999"),
        "-99,999,999,999,999.9999",
    );
    assert.equal(exactDecimal("999999999999.99"), "999,999,999,999.99");
    assert.equal(exactDecimal("12345678901.234"), "12,345,678,901.234");
    assert.equal(exactDecimal("9007199254740993"), "9,007,199,254,740,993");
    assert.equal(exactDecimal("0.000001"), "0.000001");
});

test("table keeps grouping, trimmed zeros and six-place display rounding", () => {
    for (const [value, expected] of [
        ["001234.5000", "1,234.5"],
        ["+1234.000000", "1,234"],
        ["0", "0"],
        ["-0.0000001", "-0"],
        ["0.0000005", "0.000001"],
        ["-0.0000005", "-0.000001"],
        ["99999999999999.9999995", "100,000,000,000,000"],
        ["1.2345674", "1.234567"],
        ["1.2345675", "1.234568"],
        [1234.5, "1,234.5"],
        ["1.234e3", "1,234"],
        ["1e-6", "0.000001"],
        ["1e21", "1,000,000,000,000,000,000,000"],
    ])
        assert.equal(exactDecimal(value), expected, String(value));
});

test("table leaves unsupported text intact and bounds exponent expansion", () => {
    for (const value of ["Not reported", "NaN", "1e100000000", "1e-100000000"])
        assert.equal(exactDecimal(value), value);
});
