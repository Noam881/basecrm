<?php

return [
    'auth_mode' => env('VAULT_AUTH_MODE', 'password_email_totp'),
    // Supply a protected deployment-controlled keyring outside the web/document root.
    'key_file' => env('VAULT_KEY_FILE'),
    'active_key' => env('VAULT_ACTIVE_KEY'),
    'keys' => [],
    'development_question' => env('VAULT_DEV_QUESTION', false),
    'fictional_only' => env('VAULT_FICTIONAL_ONLY', false),
];
