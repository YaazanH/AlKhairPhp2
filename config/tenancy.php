<?php

return [
    /* All initial tenant sites use a registered subdomain of this host. */
    'base_domain' => env('TENANT_BASE_DOMAIN', 'localhost'),

    /* These hosts belong to the platform or planned shared endpoints. */
    'reserved_subdomains' => ['admin', 'api', 'mail', 'platform', 'support', 'www'],
];
