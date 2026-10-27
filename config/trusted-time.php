<?php

return [
    // Returns a standard HTTP Date header. Change this URL in .env if your host
    // blocks Google; CareVMA falls back safely to the server UTC epoch.
    'http_date_url' => env('CARE_VMA_TRUSTED_TIME_URL', 'https://www.google.com/generate_204'),
    'connect_timeout' => (float) env('CARE_VMA_TRUSTED_TIME_CONNECT_TIMEOUT', 1.0),
    'timeout' => (float) env('CARE_VMA_TRUSTED_TIME_TIMEOUT', 2.0),
    'cache_seconds' => (int) env('CARE_VMA_TRUSTED_TIME_CACHE_SECONDS', 300),

    // Emergency/manual override. Leave blank normally. Example: 43200 = +12h.
    'manual_offset_seconds' => env('CARE_VMA_CLOCK_OFFSET_SECONDS'),
];
