/** Serialize one plain TSV cell without allowing spreadsheet formula execution. */
export function clipboardCell(value: string): string {
    let text = value.replace(/[\t\r\n]/g, " ");
    if (/^[\p{Z}\uFEFF\x00-\x20]*[=+\-@]/u.test(text)) text = `'${text}`;
    return text.includes('"') ? `"${text.replaceAll('"', '""')}"` : text;
}
