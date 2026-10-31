/** Encode the same nested table state accepted by the server query contract. */
export function queryString(values: Record<string, unknown>): string {
    const params = new URLSearchParams();
    function append(key: string, value: unknown) {
        if (value === null || value === undefined || value === "") return;
        if (Array.isArray(value))
            value.forEach((entry, index) => append(`${key}[${index}]`, entry));
        else if (typeof value === "object")
            Object.entries(value).forEach(([field, entry]) =>
                append(`${key}[${field}]`, entry),
            );
        else params.append(key, String(value));
    }
    Object.entries(values).forEach(([key, value]) => append(key, value));
    return params.toString();
}
