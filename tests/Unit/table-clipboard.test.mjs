import assert from "node:assert/strict";
import test from "node:test";
import { clipboardCell } from "../../packages/awove/crm-core/resources/js/components/table/clipboard.ts";

test("table clipboard neutralizes formulas after whitespace, BOM and control normalization", () => {
    for (const cell of [
        "=1+1",
        "+SUM(A1)",
        "-1+2",
        "@SUM(A1)",
        "  =1+1",
        "\uFEFF=1+1",
        "\t=1+1",
        "\r=1+1",
        "\n=1+1",
    ]) {
        assert.equal(clipboardCell(cell), `'${cell.replace(/[\t\r\n]/g, " ")}`);
    }
});

test("table clipboard cannot inject extra TSV rows or columns and retains literal quotes", () => {
    assert.equal(
        clipboardCell("Shira\tLevi\nA note\rAnother line"),
        "Shira Levi A note Another line",
    );
    assert.equal(clipboardCell('"=1+1"'), '"""=1+1"""');
    assert.equal(clipboardCell('A "quoted" note'), '"A ""quoted"" note"');
    assert.equal(clipboardCell("hello@example.test"), "hello@example.test");
    assert.equal(clipboardCell("123.50"), "123.50");
    assert.equal(clipboardCell(""), "");
});
