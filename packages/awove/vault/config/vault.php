<?php

return [
    'auth_mode' => env('VAULT_AUTH_MODE', 'password_email_totp'),
    // Supply a protected deployment-controlled keyring outside the web/document root.
    'key_file' => env('VAULT_KEY_FILE'),
    'active_key' => env('VAULT_ACTIVE_KEY'),
    'keys' => [],
    'development_question' => env('VAULT_DEV_QUESTION', false),
    'fictional_only' => env('VAULT_FICTIONAL_ONLY', false),
    'monday_token_file' => env('VAULT_MONDAY_TOKEN_FILE'),
    'monday_plan_file' => env('VAULT_MONDAY_PLAN_FILE'),
    'monday_plan_sha256' => env('VAULT_MONDAY_PLAN_SHA256'),
    'monday_workdoc_plan_file' => env('VAULT_MONDAY_WORKDOC_PLAN_FILE'),
    'monday_workdoc_plan_sha256' => env('VAULT_MONDAY_WORKDOC_PLAN_SHA256'),
];
