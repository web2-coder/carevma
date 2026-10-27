<?php

return [
    // Admin/HR operational clock only. Client-owned records never inherit this
    // timezone; they always use the client's assigned IANA timezone.
    'business_timezone' => env('CARE_VMA_BUSINESS_TIMEZONE', 'Asia/Karachi'),

    // Database/application timestamp baseline. Keep this UTC.
    'storage_timezone' => 'UTC',
];
