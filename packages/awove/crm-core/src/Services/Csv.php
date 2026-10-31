<?php

namespace Awove\Core\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shared format-only CSV handling. Domain services own authorization, limits, preview and writes. */
class Csv
{
    public const MAX_ROWS = 500;

    public const MAX_BYTES = 1048576;

    /** @return list<array{line: int, data: array}> */
    public function parse(UploadedFile $file, array $allowedHeaders, array $requiredHeaders = []): array
    {
        validator(['file' => $file], ['file' => 'required|file|max:1024'])->validate();
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages(['file' => __('Upload a UTF-8 CSV file. Excel workbooks are not supported.')]);
        }
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || strlen($contents) > self::MAX_BYTES || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw ValidationException::withMessages(['file' => __('Use a UTF-8 CSV file up to 1 MB without null bytes.')]);
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $this->validateCsvQuotes($contents);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);
        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            $header = $header === false ? [] : array_map(fn ($value) => trim($value ?? ''), $header);
            if ($header === [] || in_array('', $header, true) || count($header) !== count(array_unique($header)) || array_diff($header, $allowedHeaders) !== []) {
                throw ValidationException::withMessages(['file' => __('Use unique, supported column names from the CSV template. Read-only IDs and unknown columns cannot be imported.')]);
            }
            $required = $requiredHeaders;
            if (array_diff($required, $header) !== []) {
                throw ValidationException::withMessages(['file' => __('Missing required columns: :columns.', ['columns' => implode(', ', $required)])]);
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $line++;
                if (count(array_filter($values, fn ($value) => trim($value ?? '') !== '')) === 0) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw ValidationException::withMessages(['file' => __('CSV record :line has a different number of columns than its header.', ['line' => $line])]);
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => __('Import at most 500 records at a time.')]);
                }
                $rows[] = ['line' => $line, 'data' => array_combine($header, array_map(fn ($value) => filled(trim($value ?? '')) ? trim($value) : null, $values))];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => __('Add at least one data record below the CSV header.')]);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /** Reject malformed quoting rather than silently accepting a damaged spreadsheet. */
    private function validateCsvQuotes(string $contents): void
    {
        $state = 'start';
        for ($i = 0, $length = strlen($contents); $i < $length; $i++) {
            $char = $contents[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($contents[$i + 1] ?? null) === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }

                continue;
            }
            if (in_array($char, [',', "\r", "\n"], true)) {
                $state = 'start';
            } elseif ($char === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($char === '"' || $state === 'closed') {
                throw ValidationException::withMessages(['file' => __('CSV quotes are malformed. Save the file as standard comma-separated CSV.')]);
            } else {
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            throw ValidationException::withMessages(['file' => __('A quoted CSV field is not closed.')]);
        }
    }

    public function download(string $name, array $header, iterable $rows, bool $localizeHeaders = true): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows, $localizeHeaders) {
            $output = fopen('php://output', 'w');
            fputcsv($output, array_map(fn ($value) => self::safeCell($localizeHeaders ? self::heading((string) $value) : (string) $value), $header), ',', '"', '', "\r\n");
            foreach ($rows as $row) {
                fputcsv($output, array_map(fn ($value) => self::safeCell((string) $value), $row), ',', '"', '', "\r\n");
            }
            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Human-facing report headers may translate; import templates keep machine keys. */
    public static function heading(string $value): string
    {
        $key = 'CSV '.$value;
        $translated = (string) __($key);

        return $translated === $key ? (string) __($value) : $translated;
    }

    public static function safeCell(string $value): string
    {
        return preg_match('/^[\t\r\n]|^[\p{Z}\x{FEFF}\x00-\x20]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
