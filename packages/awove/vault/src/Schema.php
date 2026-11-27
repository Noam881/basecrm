<?php

namespace Awove\Vault;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Schema
{
    public const TYPES = ['login' => 'Login details', 'server' => 'Hosting or server', 'card' => 'Payment card', 'ssh' => 'SSH or private key', 'recovery' => 'Recovery codes', 'wallet' => 'Digital wallet details', 'document' => 'Secure document', 'note' => 'Secure note or other'];

    public const FIELD_TYPES = ['text', 'multiline', 'password', 'secret_multiline', 'url', 'email', 'date'];

    public static function templates(): array
    {
        $groups = [
            'login' => [['website', 'Website address', 'url'], ['username', 'Username or email', 'text'], ['password', 'Password', 'password']],
            'server' => [['provider', 'Provider', 'text'], ['website', 'Website address', 'url'], ['username', 'Username', 'text'], ['password', 'Password', 'password'], ['server', 'Server details', 'multiline'], ['ssh_user', 'SSH user', 'text'], ['private_key', 'SSH private key', 'secret_multiline'], ['root_password', 'Root password', 'password'], ['recovery_codes', 'Recovery codes', 'secret_multiline']],
            'card' => [['cardholder', 'Cardholder name', 'text'], ['card_number', 'Card number', 'password'], ['expiry', 'Expiry', 'text']],
            'ssh' => [['description', 'Description', 'multiline'], ['server', 'User or server', 'text'], ['private_key', 'Private key', 'secret_multiline']],
            'recovery' => [['service', 'Service', 'text'], ['recovery_codes', 'Recovery codes', 'secret_multiline']],
            'wallet' => [['description', 'Description', 'multiline'], ['public_address', 'Public address', 'text'], ['private_key', 'Private key or recovery words', 'secret_multiline']],
            'document' => [['description', 'Description', 'multiline']],
            'note' => [['content', 'Secure content', 'multiline']],
        ];
        $types = [];
        foreach (self::TYPES as $id => $label) {
            $types[] = ['id' => $id, 'label' => (string) __($label), 'fields' => array_map(fn ($field) => ['id' => $id.'.'.$field[0], 'label' => (string) __($field[1]), 'type' => $field[2], 'hidden' => true], $groups[$id])];
        }

        return $types;
    }

    /** Validation never normalizes whitespace or echoes sensitive values in messages. */
    public static function payload(array $input): array
    {
        $validated = validator(['payload' => $input], [
            'payload' => 'required|array:fields,notes', 'payload.fields' => 'present|array|max:60',
            'payload.notes' => 'sometimes|nullable|string|max:20000',
            'payload.fields.*' => 'required|array:id,label,type,value,order,hidden',
            'payload.fields.*.id' => 'required|string|regex:/^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/D|distinct',
            'payload.fields.*.label' => 'required|string|max:120',
            'payload.fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'payload.fields.*.value' => 'present|nullable|string|max:100000',
            'payload.fields.*.order' => 'required|integer|min:0|max:1000',
            'payload.fields.*.hidden' => 'sometimes|boolean',
        ])->validate()['payload'];
        foreach ($validated['fields'] as $index => &$field) {
            if (self::prohibited($field['id'], $field['label'])) {
                throw ValidationException::withMessages(["payload.fields.$index.label" => __('Do not store card CVV, CVC, PIN or magnetic-stripe data in Vault.')]);
            }
            $field['hidden'] ??= true;
            if ($field['value'] !== null && $field['value'] !== '') {
                $rule = match ($field['type']) {
                    'url' => 'url:http,https|max:2048', 'email' => 'email|max:320', 'date' => 'date_format:Y-m-d',
                    'text' => 'string|max:10000', default => 'string|max:100000',
                };
                validator(['value' => $field['value']], ['value' => $rule], ['value.*' => __('Enter a valid value for this field type.')])->validate();
            }
        }
        unset($field);
        $validated['notes'] ??= '';
        if (strlen(json_encode($validated, JSON_THROW_ON_ERROR)) > 524288) {
            throw ValidationException::withMessages(['payload' => __('The secure content is too large.')]);
        }

        return $validated;
    }

    public static function prohibited(string $id, string $label): bool
    {
        return (bool) (preg_match('/(?:^|[\s_.-])(?:cvv\d*|cvc\d*|pin|magstripe|magnetic[\s_-]*stripe|security[\s_-]*code)(?:$|[\s_.-])/iu', $id.' '.$label)
            || preg_match('/קוד\s*(?:סודי|אבטחה).*כרטיס|פס\s*מגנטי/u', $label));
    }
}
