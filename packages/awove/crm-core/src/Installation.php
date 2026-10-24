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
        // Legacy manifests may retain a color; the shared product palette is fixed.
        $result['color'] = config('core.defaults.color');
        if (! in_array($result['timezone'], timezone_identifiers_list()) || $result['locale'] !== 'en') {
            throw new \RuntimeException('Invalid installation branding, timezone or locale.');
        }
        if (! is_array($result['modules']) || ! array_is_list($result['modules']) || count(array_unique($result['modules'], SORT_REGULAR)) !== count($result['modules'])) {
            throw new \RuntimeException('Modules must be a unique list of stable identifiers.');
        }
        foreach ($result['modules'] as $module) {
            if (! is_string($module) || ! preg_match('/^[a-z][a-z0-9-]*$/', $module)) {
                throw new \RuntimeException('Invalid module identifier in installation manifest.');
            }
        }
        if (! is_array($result['capabilities']) || ! is_array($result['module_labels']) || ! preg_match('/^[A-Z]{3}$/', $result['currency'])) {
            throw new \RuntimeException('Invalid capabilities, module labels or installation currency.');
        }
        foreach ($result['capabilities'] as $key => $enabled) {
            if (! is_string($key) || ! is_bool($enabled)) {
                throw new \RuntimeException('Capabilities must map stable identifiers to booleans.');
            }
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
        return array_values(array_unique(array_merge(['color'], config('core.locks'), self::manifest()['locks'] ?? [])));
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
        validator($data, ['name' => 'sometimes|required|string|max:80', 'color' => 'prohibited', 'timezone' => 'sometimes|timezone', 'lead_label' => 'sometimes|required|string|max:30'])->validate();
        foreach ($data as $key => $value) {
            if (! in_array($key, ['name', 'timezone', 'lead_label']) || in_array($key, self::locks())) {
                throw ValidationException::withMessages([$key => 'Deployment controlled setting.']);
            }
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
