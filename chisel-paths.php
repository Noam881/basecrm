<?php

return [
    'login' => 'resources/js/pages/auth/Login.vue',
    'register' => 'resources/js/pages/auth/Register.vue',
    'welcome' => 'resources/js/pages/Welcome.vue',
    'profile' => 'resources/js/pages/settings/Profile.vue',
    'security' => 'resources/js/pages/settings/Security.vue',
    'auth_types' => 'resources/js/types/auth.ts',

    'two_factor_files' => [],

    'two_factor_otp_package' => 'vue-input-otp',

    'passkey_files' => [
        'resources/js/components/PasskeyItem.vue',
        'resources/js/components/ManagePasskeys.vue',
        'resources/js/components/PasskeyRegister.vue',
        'resources/js/components/PasskeyVerify.vue',
    ],
];
