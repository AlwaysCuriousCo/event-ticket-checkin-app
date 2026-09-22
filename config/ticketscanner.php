<?php

return [

    /*
    | Which ApiClient implementation to bind: "fixture" serves docs/api/fixtures
    | (all development through Stage 6), "http" talks to a real WordPress site
    | running the companion plugin (Stage 8+).
    */
    'api' => env('TICKETSCANNER_API', 'fixture'),

    /*
    | Friendly device name sent as `device_id` with check-in batches. Falls
    | back to the native device id, then "dev-device". Becomes editable in
    | the Settings screen (Stage 6).
    */
    'device_name' => env('TICKETSCANNER_DEVICE_NAME'),

    /*
    | Extra CA bundle for HTTPS verification — needed only for local dev
    | sites signed by a private CA (e.g. Herd/Valet's *.test certificates).
    | Absolute path, or a path relative to storage/ (packaged into the app).
    | Leave unset in production: the system bundle verifies real sites.
    */
    'ca_bundle' => env('TICKETSCANNER_CA_BUNDLE'),

    // Attendee page size for sync pulls (server caps at 200).
    'sync_per_page' => 100,

    // Quiet window after a scan before the sync job fires (batches rapid scans).
    // 0 = push to the server inline, immediately after every check-in.
    'sync_debounce_seconds' => (int) env('TICKETSCANNER_SYNC_DEBOUNCE_SECONDS', 15),

];
