<?php

declare(strict_types=1);

return [

    'root_domain' => env('TENANCY_ROOT_DOMAIN', 'accel.kz'),

    'admin_subdomain' => env('TENANCY_ADMIN_SUBDOMAIN', 'app'),

    /** Slug used when host has no tenant subdomain (tests, artisan). */
    'fallback_slug' => env('TENANCY_FALLBACK_SLUG', 'demo'),

    'super_admin_email' => env('SUPER_ADMIN_EMAIL', 'super@accel.kz'),

    /*
     * Поддомены, которые нельзя занимать тенантам — они зарезервированы под
     * технические или маркетинговые цели. NB: «demo» НЕ зарезервирован,
     * потому что используется как fallback_slug и как реальный демо-тенант.
     */
    'reserved_slugs' => [
        'app', 'www', 'api', 'admin', 'mail', 'static', 'cdn', 'ftp',
        'staging', 'dev', 'ns1', 'ns2',
    ],

    /** nginx map: известные FQDN тенантов → редирект остальных на лендинг */
    'nginx_known_tenants_map' => env(
        'TENANCY_NGINX_KNOWN_MAP',
        '/var/www/accel/shared/nginx/known-tenants.map',
    ),

];
