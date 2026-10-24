<?php

namespace Awove\Core\Services;

use Awove\Core\Access;
use Awove\Core\ContactDetails;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Status;
use Awove\Core\Modules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV is deliberately create-only: exports are snapshots, not update instructions. */
class Spreadsheet
{
    public const MAX_ROWS = 500;

    public const MAX_BYTES = 1048576;

    public const MAX_EXPORT_ROWS = 10000;

    private const COLUMNS = [
        'contacts' => ['name', 'email', 'phone', 'company_id', 'secondary_phone', 'secondary_phone_label', 'preferred_contact_method', 'preferred_contact_times', 'preferred_contact_days', 'address', 'city', 'country', 'source', 'notes'],
        'companies' => ['name', 'email', 'phone', 'notes'],
        'leads' => ['title', 'contact_id', 'company_id', 'status_id', 'source', 'priority', 'owner_id', 'needs', 'estimated_value', 'closing_date', 'final_value', 'loss_reason'],
    ];

    public function authorize($user, string $action, ?string $type = null): void
    {
        abort_unless($user && $user->active && $user->can($action === 'import' ? 'imports.manage' : 'exports.view'), 403);
        if ($type !== null) {
            abort_unless(isset(self::COLUMNS[$type]), 404);
            if ($type === 'leads') {
                abort_unless(app(Modules::class)->enabled('sales'), 404);
            }
        }
        if ($action === 'import') {
            abort_unless($user->can('leads.create'), 403);
        }
    }

    public function definitions(): array
    {
        $definitions = array_map(fn ($columns) => ['columns' => $columns, 'options' => []], self::COLUMNS);
        $definitions['contacts']['options'] = [
            'secondary_phone_label' => ContactDetails::PHONE_LABELS,
            'preferred_contact_method' => ContactDetails::METHODS,
            'preferred_contact_times' => ContactDetails::TIMES,
            'preferred_contact_days' => ContactDetails::DAYS,
            'source' => ContactDetails::SOURCES,
        ];
        if (app(Modules::class)->enabled('sales')) {
            $definitions['leads']['options'] = ['status_id' => Status::orderBy('position')->pluck('name', 'id')->all(), 'priority' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High']];
        }

        return $definitions;
    }

    public function preview($user, string $type, UploadedFile $file): array
    {
        $this->authorize($user, 'import', $type);
        validator(['file' => $file], ['file' => 'required|file|max:1024'])->validate();
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages(['file' => 'Upload a UTF-8 CSV file. Excel workbooks are not supported.']);
        }
        $rows = $this->parse($file, $type);
        [$validated, $errors, $warnings] = $this->validateRows($user, $type, $rows);
        $token = null;
        if ($errors === []) {
            $token = bin2hex(random_bytes(32));
            Cache::put($this->key($user, $token), ['type' => $type, 'rows' => $rows, 'warnings' => $warnings], now()->addMinutes(15));
        }

        return ['type' => $type, 'count' => count($rows), 'rows' => $validated, 'errors' => $errors, 'warnings' => $warnings, 'token' => $token, 'expires_in_minutes' => 15];
    }

    public function confirm($user, string $token, bool $acceptDuplicates = false): array
    {
        $this->authorize($user, 'import');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 409, 'Preview this file again before importing.');
        $key = $this->key($user, $token);
        $result = Cache::lock($key.':lock', 60)->get(function () use ($user, $key, $acceptDuplicates) {
            $preview = Cache::get($key);
            abort_unless(is_array($preview), 409, 'This preview expired or was already imported. Preview the file again.');
            $this->authorize($user, 'import', $preview['type']);
            $result = DB::transaction(function () use ($user, $preview, $acceptDuplicates, $key) {
                [$rows, $errors, $warnings] = $this->validateRows($user, $preview['type'], $preview['rows']);
                if ($errors !== []) {
                    throw ValidationException::withMessages(['preview' => 'Some records are no longer valid or accessible. Preview this file again.']);
                }
                abort_unless($warnings === $preview['warnings'], 409, 'Possible duplicates changed since preview. Preview the file again.');
                if ($warnings !== [] && ! $acceptDuplicates) {
                    throw ValidationException::withMessages(['accept_duplicates' => 'Review the duplicate warnings and confirm keeping separate records.']);
                }
                // Consume before creating: a cache failure after DB commit must never make this token replayable.
                abort_unless(Cache::forget($key), 503, 'The preview could not be consumed safely. Preview the file again.');
                $ids = [];
                foreach ($rows as $row) {
                    $record = $preview['type'] === 'leads'
                        ? app(Leads::class)->create($user, $row['data'])
                        : app(Identities::class)->create($user, $preview['type'], [...$row['data'], 'accept_duplicate' => $acceptDuplicates]);
                    $ids[] = $record->id;
                }

                return ['type' => $preview['type'], 'count' => count($ids), 'ids' => $ids];
            });

            return $result;
        });
        abort_if($result === false, 409, 'An import is already processing this preview.');

        return $result;
    }

    public function template($user, string $type): StreamedResponse
    {
        $this->authorize($user, 'import', $type);

        return $this->csv($type.'-template.csv', self::COLUMNS[$type], []);
    }

    public function export($user, string $type): StreamedResponse
    {
        $this->authorize($user, 'export', $type);
        $query = $type === 'leads'
            ? Access::leads($user)->whereNull('archived_at')->with(['contact:id,name', 'company:id,name'])
            : Access::identities($user, $type === 'contacts' ? Contact::class : Company::class);
        abort_if((clone $query)->count() > self::MAX_EXPORT_ROWS, 422, 'This export exceeds the 10,000-record limit.');
        $columns = $type === 'leads'
            ? ['id', ...self::COLUMNS[$type], 'contact_name', 'company_name', 'created_at']
            : ['id', ...array_diff(self::COLUMNS[$type], ['notes']), 'created_at'];
        $rows = (function () use ($query, $columns, $user) {
            foreach ($query->orderBy('id')->lazyById(200) as $record) {
                $row = $record->only($columns);
                if ($record instanceof Lead) {
                    $row['contact_name'] = $record->getRelationValue('contact')?->getAttribute('name');
                    $row['company_name'] = $record->getRelationValue('company')?->getAttribute('name');
                    $row['closing_date'] = $record->getRawOriginal('closing_date');
                } elseif ($record instanceof Contact && $record->getAttribute('company_id') && ! Access::identities($user, Company::class)->whereKey($record->getAttribute('company_id'))->exists()) {
                    $row['company_id'] = null;
                }
                yield array_map(fn ($column) => is_array($row[$column] ?? null) ? implode('|', $row[$column]) : ($row[$column] ?? ''), $columns);
            }
        })();

        return $this->csv($type.'-'.now()->format('Y-m-d').'.csv', $columns, $rows);
    }

    /** @return list<array{line: int, data: array}> */
    private function parse(UploadedFile $file, string $type): array
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || strlen($contents) > self::MAX_BYTES || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw ValidationException::withMessages(['file' => 'Use a UTF-8 CSV file up to 1 MB without null bytes.']);
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $this->validateCsvQuotes($contents);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);
        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            $header = $header === false ? [] : array_map(fn ($value) => trim($value ?? ''), $header);
            if ($header === [] || in_array('', $header, true) || count($header) !== count(array_unique($header)) || array_diff($header, self::COLUMNS[$type]) !== []) {
                throw ValidationException::withMessages(['file' => 'Use unique, supported column names from the CSV template. Read-only IDs and unknown columns cannot be imported.']);
            }
            $required = $type === 'leads' ? ['status_id'] : ['name'];
            if (array_diff($required, $header) !== []) {
                throw ValidationException::withMessages(['file' => 'Missing required columns: '.implode(', ', $required).'.']);
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $line++;
                if (count(array_filter($values, fn ($value) => trim($value ?? '') !== '')) === 0) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw ValidationException::withMessages(['file' => "CSV record $line has a different number of columns than its header."]);
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => 'Import at most 500 records at a time.']);
                }
                $rows[] = ['line' => $line, 'data' => array_combine($header, array_map(fn ($value) => filled(trim($value ?? '')) ? trim($value) : null, $values))];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'Add at least one data record below the CSV header.']);
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
                throw ValidationException::withMessages(['file' => 'CSV quotes are malformed. Save the file as standard comma-separated CSV.']);
            } else {
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            throw ValidationException::withMessages(['file' => 'A quoted CSV field is not closed.']);
        }
    }

    /** Validation never writes records or dispatches domain events. */
    private function validateRows($user, string $type, array $rows): array
    {
        $validated = $errors = $warnings = $seen = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            $rules = $this->rules($type);
            if ($type === 'contacts') {
                foreach (['preferred_contact_times', 'preferred_contact_days'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $data[$field] = filled($data[$field]) ? explode('|', $data[$field]) : [];
                    }
                }
            }
            if ($type === 'leads') {
                $data['source'] = $data['source'] ?? 'Direct';
                $data['priority'] = $data['priority'] ?? 'normal';
                $data['owner_id'] = $data['owner_id'] ?? $user->id;
            }
            $validator = validator($data, $rules);
            if ($validator->fails()) {
                $errors[] = ['line' => $row['line'], 'messages' => $validator->errors()->all()];

                continue;
            }
            $data = $validator->validated();
            $messages = [];
            foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $key => $model) {
                if (! empty($data[$key]) && ! Access::identities($user, $model)->whereKey($data[$key])->exists()) {
                    $messages[] = ucfirst(str_replace('_', ' ', $key)).' is not available to you.';
                }
            }
            if ($type === 'leads') {
                if (empty($data['contact_id']) && empty($data['company_id'])) {
                    $messages[] = 'Choose a contact_id or company_id for every lead.';
                }
                if (($data['owner_id'] != $user->id && ! $user->can('leads.assign')) || ! config('auth.providers.users.model')::where('active', true)->whereKey($data['owner_id'])->exists()) {
                    $messages[] = 'The selected owner is not available for assignment.';
                }
            } elseif ($type === 'contacts' && in_array('anytime', $data['preferred_contact_times'] ?? [], true) && count($data['preferred_contact_times']) > 1) {
                $messages[] = 'Choose Anytime or specific contact times, not both.';
            }
            if ($messages !== []) {
                $errors[] = ['line' => $row['line'], 'messages' => $messages];

                continue;
            }
            if ($type !== 'leads') {
                $duplicate = $this->duplicate($user, $type, $data, $seen);
                if ($duplicate) {
                    $warnings[] = ['line' => $row['line'], 'message' => 'Possible duplicate in records you can access or earlier in this file. Keeping it will create a separate record.'];
                }
                $seen[] = $data;
            }
            $validated[] = ['line' => $row['line'], 'data' => $data];
        }

        return [$validated, $errors, $warnings];
    }

    private function rules(string $type): array
    {
        if ($type === 'leads') {
            return ['title' => 'nullable|string|max:160', 'contact_id' => 'nullable|integer|min:1', 'company_id' => 'nullable|integer|min:1', 'status_id' => ['required', Rule::exists(Status::class, 'id')], 'source' => 'required|string|max:100', 'priority' => ['required', Rule::in(['low', 'normal', 'high'])], 'owner_id' => 'required|integer|min:1', ...Sales::opportunityRules()];
        }
        $rules = ['name' => 'required|string|max:120', 'email' => 'nullable|email|max:160', 'phone' => 'nullable|string|max:40'];
        if ($type === 'companies') {
            return [...$rules, 'notes' => 'nullable|string|max:10000'];
        }

        return [...$rules, 'company_id' => 'nullable|integer|min:1', 'secondary_phone' => 'nullable|string|max:40', 'secondary_phone_label' => ['nullable', Rule::in(array_keys(ContactDetails::PHONE_LABELS))], 'preferred_contact_method' => ['nullable', Rule::in(array_keys(ContactDetails::METHODS))], 'preferred_contact_times' => 'array|max:4', 'preferred_contact_times.*' => ['required', 'distinct', Rule::in(array_keys(ContactDetails::TIMES))], 'preferred_contact_days' => 'array|max:7', 'preferred_contact_days.*' => ['required', 'distinct', Rule::in(array_keys(ContactDetails::DAYS))], 'address' => 'nullable|string|max:500', 'city' => 'nullable|string|max:120', 'country' => 'nullable|string|max:120', 'source' => ['nullable', Rule::in(array_keys(ContactDetails::SOURCES))], 'notes' => 'nullable|string|max:10000'];
    }

    private function duplicate($user, string $type, array $data, array $seen): bool
    {
        $name = mb_strtolower(trim($data['name']));
        $email = mb_strtolower(trim($data['email'] ?? ''));
        $phones = array_filter(array_map(fn ($phone) => preg_replace('/[^0-9]/', '', $phone ?? ''), [$data['phone'] ?? null, $data['secondary_phone'] ?? null]));
        $query = Access::identities($user, $type === 'contacts' ? Contact::class : Company::class)->where(function ($query) use ($name, $email, $phones, $type) {
            $query->whereRaw('lower(name) = ?', [$name]);
            if ($email !== '') {
                $query->orWhereRaw('lower(email) = ?', [$email]);
            }
            foreach ($phones as $phone) {
                $query->orWhereRaw("regexp_replace(coalesce(phone,''), '[^0-9]', '', 'g') = ?", [$phone]);
                if ($type === 'contacts') {
                    $query->orWhereRaw("regexp_replace(coalesce(secondary_phone,''), '[^0-9]', '', 'g') = ?", [$phone]);
                }
            }
        });
        if ($query->exists()) {
            return true;
        }
        foreach ($seen as $earlier) {
            $earlierPhones = array_filter(array_map(fn ($phone) => preg_replace('/[^0-9]/', '', $phone ?? ''), [$earlier['phone'] ?? null, $earlier['secondary_phone'] ?? null]));
            if ($name === mb_strtolower(trim($earlier['name'])) || ($email !== '' && $email === mb_strtolower(trim($earlier['email'] ?? ''))) || array_intersect($phones, $earlierPhones) !== []) {
                return true;
            }
        }

        return false;
    }

    private function key($user, string $token): string
    {
        $installation = hash('sha256', config('app.url').'|'.config('database.connections.'.config('database.default').'.database'));

        return 'crm:csv:'.$installation.':'.$user->id.':'.$token;
    }

    private function csv(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $output = fopen('php://output', 'w');
            fputcsv($output, $header, ',', '"', '', "\r\n");
            foreach ($rows as $row) {
                fputcsv($output, array_map(fn ($value) => self::safeCell((string) $value), $row), ',', '"', '', "\r\n");
            }
            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function safeCell(string $value): string
    {
        return preg_match('/^[\t\r\n]|^[\p{Z}\x{FEFF}\x00-\x20]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
