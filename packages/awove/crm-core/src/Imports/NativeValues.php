<?php

namespace Awove\Core\Imports;

use Awove\Core\ContactDetails;
use Awove\Core\Currency;
use Awove\Core\CustomFields;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Money;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Sales;
use Carbon\CarbonImmutable;

/** Native destination representations only; no source snapshots/staging or module-owned data. */
final class NativeValues
{
    public const MONEY = ['estimated_value', 'final_value'];

    public const DATES = ['entered_on', 'closing_date', 'contract_sent_on', 'invoice_sent_on', 'payment_received_on', 'process_started_on'];

    public static function fields(string $kind): array
    {
        return match ($kind) {
            'contacts' => array_values(array_diff(ContactDetails::ATTRIBUTES, ['source'])),
            'companies' => Company::ATTRIBUTES,
            'leads' => ['title', 'contact_id', 'company_id', 'owner_id', 'status_id', 'source', 'priority', 'entered_on', 'created_at', ...array_keys(Sales::opportunityRules()),
                ...array_map(fn ($key) => 'custom:'.$key, array_keys(Installation::manifest()['custom_fields']))],
            default => abort(422, 'Unsupported ordinary native kind.'),
        };
    }

    public static function validateFields(string $kind, array $fields): void
    {
        abort_unless($fields !== [] && count($fields) <= 200 && ! array_is_list($fields), 422, 'Invalid native field plan.');
        foreach ($fields as $key => $field) {
            abort_unless(is_string($key) && strlen($key) <= 200 && in_array($key, self::fields($kind), true)
                && ! preg_match('/^custom:(?:marketing(?:_|$)|attribution(?:_|$)|qualification(?:_|$))/iD', $key) && is_array($field), 422, 'Unsupported ordinary native field.');
            Metadata::exact($field, ['available', 'value', 'request_key', 'previous_receipt_id']);
            abort_unless(is_bool($field['available']) && ($field['available'] || $field['value'] === null)
                && ($field['previous_receipt_id'] === null || is_int($field['previous_receipt_id']) && $field['previous_receipt_id'] > 0), 422, 'Invalid native field context.');
            Metadata::uuid($field['request_key']);
        }
    }

    public static function prepare($actor, string $kind, array $values, Contact|Company|Lead|null $target, bool $acceptDuplicate, ?string $currency): array
    {
        $data = [];
        $custom = $target instanceof Lead ? ($target->custom_fields ?? []) : [];
        $dates = [];
        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'custom:')) {
                $custom[substr($key, 7)] = $value;
            } elseif ($kind === 'leads' && in_array($key, ['entered_on', 'created_at'], true)) {
                $dates[$key] = $value;
            } else {
                $data[$key] = $value;
            }
        }
        if ($kind === 'leads') {
            if (array_filter(array_keys($values), fn ($key) => str_starts_with($key, 'custom:')) !== []) {
                $data['custom_fields'] = CustomFields::validate($custom);
            }
            $service = app(Leads::class);
            $data = $service->input($actor, [...$data, ...($target ? ['version' => $target->version] : [])], $target !== null);
            unset($data['version']);
            $data = $service->titleData($actor, $data, $target);
            self::assertCurrency($kind, $data, $currency);
            foreach (self::MONEY as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                    $data[$field] = Money::amount($data[$field], $field);
                }
            }
            $data = [...$data, ...Sales::opportunityData($data)];
            $dates = Leads::historicalDates($dates);
        } else {
            $data = app(Identities::class)->data($actor, $kind, [...$data, 'accept_duplicate' => $acceptDuplicate], $target);
        }
        $canonical = [];
        foreach ($values as $key => $value) {
            $canonical[$key] = str_starts_with($key, 'custom:') ? self::json($data['custom_fields'][substr($key, 7)]) : self::canonical($kind, $key, ($dates + $data)[$key]);
        }
        // Native normalization may not silently rewrite another business field outside this plan.
        if ($target instanceof Contact || $target instanceof Company) {
            foreach (self::fields($kind) as $key) {
                if (! array_key_exists($key, $values) && array_key_exists($key, $data)) {
                    abort_unless(self::canonical($kind, $key, $data[$key]) === self::read($target, $key)['value'], 409, 'Native normalization requires an explicit dependent field plan.');
                }
            }
        }

        return ['data' => $data, 'dates' => $dates, 'canonical' => $canonical];
    }

    public static function assertCurrency(string $kind, array $values, ?string $currency): void
    {
        foreach ($kind === 'leads' ? self::MONEY : [] as $field) {
            if (array_key_exists($field, $values) && $values[$field] !== null && $values[$field] !== '') {
                abort_unless($currency !== null && $currency === Currency::current(), 409, 'The reviewed source currency must match the installation currency.');
                app(Currency::class)->assertConsistent();
            }
        }
    }

    public static function read(Contact|Company|Lead $target, string $field): array
    {
        if (str_starts_with($field, 'custom:')) {
            $custom = $target->getAttribute('custom_fields') ?? [];
            $key = substr($field, 7);

            return array_key_exists($key, $custom) ? ['present' => true, 'value' => self::json($custom[$key])] : ['present' => false];
        }
        $kind = $target instanceof Lead ? 'leads' : ($target instanceof Contact ? 'contacts' : 'companies');

        return ['present' => true, 'value' => self::canonical($kind, $field, $target->getAttribute($field))];
    }

    public static function fieldHash(string $kind, string $field, array $snapshot): string
    {
        return self::digest('field-value', [$kind, $field, $snapshot, $kind === 'leads' && in_array($field, self::MONEY, true) && ($snapshot['value'] ?? null) !== null ? Currency::current() : null]);
    }

    public static function contextHash(string $kind, int $id): string
    {
        return self::digest('entity-target', [$kind, $id]);
    }

    public static function digest(string $purpose, array $data): string
    {
        return hash_hmac('sha256', 'core-native-import:v1:'.$purpose."\0".json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), app('encrypter')->getKey());
    }

    private static function canonical(string $kind, string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (in_array($field, ['contact_id', 'company_id', 'owner_id'], true)) {
            return (int) $value;
        }
        if ($kind === 'leads' && $field === 'status_id') {
            return (string) $value;
        }
        if ($kind === 'leads' && $field === 'created_at') {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:sP');
        }
        if ($kind === 'leads' && in_array($field, self::DATES, true)) {
            return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        if ($kind === 'leads' && in_array($field, self::MONEY, true)) {
            return Money::amount($value, $field);
        }
        if ($kind === 'leads' && $field === 'privacy_policy_accepted') {
            return (bool) $value;
        }

        return $value;
    }

    private static function json(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
