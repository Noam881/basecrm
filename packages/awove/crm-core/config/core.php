<?php

return ['version' => '1.0.0', 'contract' => 1, 'installation' => env('CRM_INSTALLATION', 'alpha'), 'manifest' => env('CRM_MANIFEST'), 'defaults' => ['name' => 'Awove CRM', 'color' => '#25635b', 'logo' => '/brands/alpha.svg', 'favicon' => '/brands/alpha.svg', 'locale' => 'en', 'timezone' => 'UTC', 'lead_label' => 'Leads', 'modules' => [], 'custom_fields' => []], 'locks' => ['modules', 'custom_fields', 'logo', 'favicon']];
