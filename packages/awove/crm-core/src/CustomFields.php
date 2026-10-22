<?php

namespace Awove\Core;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomFields
{
    public static function validate(array $values): array
    {
        $rules = [];
        $definitions = Installation::manifest()['custom_fields'];
        foreach ($values as $key => $value) {
            if (! isset($definitions[$key])) {
                throw ValidationException::withMessages(['custom_fields.'.$key => 'Unknown field.']);
            }
        }
        foreach ($definitions as $key => $d) {
            $rules[$key] = array_merge(['nullable'], match ($d['type']) {
                'text' => ['string', 'max:500'],'number' => ['numeric'],'date' => ['date_format:Y-m-d'],'select' => [Rule::in($d['options'])],'boolean' => ['boolean'],default => throw new \RuntimeException('Unsupported custom field type.')
            });
        }

        return validator($values, $rules)->validate();
    }
}
