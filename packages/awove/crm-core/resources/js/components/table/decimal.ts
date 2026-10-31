/** Group exact database decimals without converting their digits to binary floating point.
 * Keep the table's existing six-place, half-away-from-zero display rounding.
 */
export function exactDecimal(value: string | number | boolean): string {
    if (typeof value === "boolean") return value ? "1" : "0";
    const original = String(value);
    const match = /^([+-]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/.exec(
        original.trim(),
    );
    if (!match) return original;

    const exponent = BigInt(match[4] ?? "0");
    // Real database fields have bounded precision; do not expand an unreasonable exponent.
    if (exponent > 1000n || exponent < -1000n) return original;
    const fraction = match[3] ?? "";
    const shift = exponent + 6n - BigInt(fraction.length);
    const digits = BigInt(match[2] + fraction);
    let scaled: bigint;
    if (shift >= 0n) scaled = digits * 10n ** shift;
    else {
        const divisor = 10n ** -shift;
        scaled = (digits + divisor / 2n) / divisor;
    }
    const integer = (scaled / 1000000n)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    const decimal = (scaled % 1000000n)
        .toString()
        .padStart(6, "0")
        .replace(/0+$/, "");
    return `${match[1] === "-" ? "-" : ""}${integer}${decimal ? `.${decimal}` : ""}`;
}
