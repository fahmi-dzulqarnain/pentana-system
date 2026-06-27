<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        // APNs (token-based) — used by laravel-notification-channels/apn for member push.
        // The .p8 lives on the server (path via env); never committed.
        'apn' => [
            'key_id' => env('APN_KEY_ID'),
            'team_id' => env('APN_TEAM_ID'),
            'app_bundle_id' => env('APN_BUNDLE_ID', 'my.silentmode.pentana'),
            'private_key_path' => env('APN_PRIVATE_KEY_PATH'),
            'private_key_secret' => env('APN_PRIVATE_KEY_SECRET'),
            'production' => (bool) env('APN_PRODUCTION', false),
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
