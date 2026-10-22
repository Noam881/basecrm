<?php

namespace Awove\Core;

use Awove\Core\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Installation
{
    public static function manifest(): array
    {
        $path = config('core.manifest') ?: base_path('installations/examples/'.config('core.installation').'/manifest.json');
        if (! is_file($path)) {
            throw new \RuntimeException('Installation manifest missing.');
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $result = array_replace(config('core.defaults'), $data);
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $result['color']) || ! in_array($result['timezone'], timezone_identifiers_list()) || $result['locale'] !== 'en') {
            throw new \RuntimeException('Invalid installation branding, timezone or locale.');
        }
        foreach (['logo', 'favicon'] as $key) {
            if (! preg_match('~^/[a-zA-Z0-9/_\-.]+$~', $result[$key])) {
                throw new \RuntimeException('Brand assets must be local public paths.');
            }
        }

        return $result;
    }

    public static function locks(): array
    {
        return array_values(array_unique(array_merge(config('core.locks'), self::manifest()['locks'] ?? [])));
    }

    public static function settings(): array
    {
        $data = self::manifest();
        if (Schema::hasTable('crm_settings')) {
            foreach (Setting::all() as $setting) {
                if (! in_array($setting->key, self::locks())) {
                    $data[$setting->key] = $setting->value;
                }
            }
        }

        return $data;
    }

    public static function display($user): array
    {
        $settings = self::settings();
        $timezone = $user?->display_preferences['timezone'] ?? null;
        if ($timezone && ! in_array('timezone', self::locks()) && in_array($timezone, timezone_identifiers_list())) {
            $settings['timezone'] = $timezone;
        }

        return $settings;
    }

    public static function save(array $data): void
    {
        validator($data, ['name' => 'sometimes|required|string|max:80', 'color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'], 'timezone' => 'sometimes|timezone', 'lead_label' => 'sometimes|required|string|max:30'])->validate();
        foreach ($data as $key => $value) {
            if (! in_array($key, ['name', 'color', 'timezone', 'lead_label']) || in_array($key, self::locks())) {
                throw ValidationException::withMessages([$key => 'Deployment controlled setting.']);
            }
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
