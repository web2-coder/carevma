<?php

return [
    // Admin/HR operational clock only. Client-owned records never inherit this
    // timezone; they always use the client's assigned IANA timezone.
    'business_timezone' => env('CARE_VMA_BUSINESS_TIMEZONE', 'Asia/Karachi'),

    // Database/application timestamp baseline. Keep this UTC.
    'storage_timezone' => 'UTC',

    // Safe defaults for high-volume installations. These can be overridden later
    // from the server environment without changing application code.
    'performance' => [
        'max_per_page' => (int) env('CARE_VMA_MAX_PER_PAGE', 100),
        'activity_filter_cache_seconds' => (int) env('CARE_VMA_ACTIVITY_FILTER_CACHE_SECONDS', 300),
    ],
];
