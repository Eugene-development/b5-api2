<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'graphql', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // Local development
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5040',
        'http://localhost:5137',
        'http://127.0.0.1:5137',

        // Production - bonus5.ru domain
        'https://bonus5.ru',
        'https://www.bonus5.ru',
        'https://auth.bonus5.ru',

        // Production - bonus.band domain
        'https://bonus.band',
        'https://www.bonus.band',
        'https://admin.bonus.band',
        'https://auth.bonus.band',

        // Production - rubonus domains
        'https://rubonus.info',
        'https://rubonus.pro',
        'https://www.rubonus.pro',
        'https://auth.rubonus.pro',

        // Production - other domains
        'https://mebelmobile.ru',
        'https://www.mebelmobile.ru',
    ],
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
