<?php

return [
    'dashboard_ttl_seconds' => (int) env('TENANT_DASHBOARD_CACHE_TTL', 60),
    'entitlements_ttl_seconds' => (int) env('TENANT_ENTITLEMENTS_CACHE_TTL', 300),
];
