<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        // APNs (token-based) - used by laravel-notification-channels/apn for member push.
        // The .p8 lives on the server (path via env); never committed.
        'apn' => [
            'key_id' => env('APN_KEY_ID'),
            'team_id' => env('APN_TEAM_ID'),
            'app_bundle_id' => env('APN_BUNDLE_ID', 'my.silentmode.pentana'),
            // A relative path is taken from the app root: web requests run from public/, so a
            // relative path only worked for scheduled commands (and briefly, from a cached token).
            'private_key_path' => ($path = env('APN_PRIVATE_KEY_PATH')) && ! str_starts_with($path, '/') ? base_path($path) : $path,
            'private_key_secret' => env('APN_PRIVATE_KEY_SECRET'),
            'production' => (bool) env('APN_PRODUCTION', false),
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
