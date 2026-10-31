<?php

namespace Awove\Finance\Payroll;

use Awove\Core\Installation;
use Awove\Core\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Deployment-configured manual inputs, snapshotted per preparation. No formulas. */
class Inputs
{
    public function configured(): array
    {
        $fields = Installation::manifest()['payroll_inputs'] ?? [
            ['key' => 'reported_hours', 'label' => 'Reported hours', 'type' => 'decimal', 'unit' => 'hours', 'scale' => 2, 'required' => false],
            ['key' => 'absence_days', 'label' => 'Absence days', 'type' => 'decimal', 'unit' => 'days', 'scale' => 2, 'required' => false],
            ['key' => 'reimbursable_expenses', 'label' => 'Reimbursable expenses', 'type' => 'money', 'unit' => Installation::settings()['currency'], 'scale' => 2, 'required' => false],
        ];
        if (! is_array($fields) || ! array_is_list($fields) || count($fields) > 20) {
            throw new \RuntimeException('Configure at most 20 agreed payroll inputs.');
        }
        $keys = [];
        foreach ($fields as &$field) {
            if (! is_array($field) || ! preg_match('/^[a-z][a-z0-9_]{0,49}$/', $field['key'] ?? '') || in_array($field['key'], $keys, true) || ! in_array($field['type'] ?? null, ['decimal', 'money', 'text', 'date'], true) || ! is_string($field['label'] ?? null) || trim($field['label']) === '' || mb_strlen($field['label']) > 120 || ! is_string($field['unit'] ?? '') || mb_strlen($field['unit'] ?? '') > 40 || ! is_bool($field['required'] ?? false) || ! is_int($field['scale'] ?? 2) || ($field['scale'] ?? 2) < 0 || ($field['scale'] ?? 2) > 4) {
                throw new \RuntimeException('Invalid agreed payroll input definition.');
            }
            $field = ['key' => $field['key'], 'label' => trim($field['label']), 'type' => $field['type'], 'unit' => $field['type'] === 'money' ? Installation::settings()['currency'] : ($field['unit'] ?? ''), 'scale' => $field['type'] === 'money' ? 2 : ($field['scale'] ?? 2), 'required' => $field['required'] ?? false];
            $keys[] = $field['key'];
        }

        return $fields;
    }

    public function validate(array $schema, mixed $values, bool $ready): array
    {
        if (! is_array($values) || array_diff(array_keys($values), array_column($schema, 'key'))) {
            throw ValidationException::withMessages(['inputs' => 'Use only the agreed payroll input fields.']);
        }
        $result = [];
        foreach ($schema as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $path = 'inputs.'.$key;
            if ($value === null || $value === '') {
                if ($ready && $field['required']) {
                    throw ValidationException::withMessages([$path => 'Complete this agreed input before marking ready.']);
                }
                $result[$key] = null;

                continue;
            }
            if ($field['type'] === 'money') {
                $value = Money::amount($value, $path);
            } elseif ($field['type'] === 'decimal') {
                $scale = $field['scale'];
                $pattern = '/^\d{1,12}'.($scale > 0 ? '(?:\.\d{1,'.$scale.'})?' : '').'$/D';
                if ((! is_string($value) && ! is_int($value)) || ! preg_match($pattern, (string) $value)) {
                    throw ValidationException::withMessages([$path => 'Enter a nonnegative decimal with at most '.$scale.' decimal places.']);
                }
                $value = (string) BigDecimal::of((string) $value)->toScale($scale, RoundingMode::Unnecessary);
            } else {
                $value = validator(['value' => $value], ['value' => $field['type'] === 'date' ? 'date_format:Y-m-d' : 'string|max:4000'])->validate()['value'];
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
