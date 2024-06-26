<?php

return [
    "trace" => env("SCIM_TRACE", false),
    // below, if we ever get 'sure' that we can change this default to 'true' we should
    "omit_main_schema_in_return" => env('SCIM_STANDARDS_COMPLIANCE', true),
    "publish_routes" => false,
    "access_token" => env("SCIM_ACCESS_TOKEN", ''),
    "base_url" => env("SCIM_BASE_URL", null),
    "api_base_url" => env("API_BASE_URL", null),
];
