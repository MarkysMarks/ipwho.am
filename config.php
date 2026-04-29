<?php
// ── ipwho.am configuration ─────────────────────────────────────────────────
// Copy this file to config.local.php and override values there for production.
// config.local.php is in .gitignore and never committed.

return [
    'db' => [
        'host'    => getenv('DB_HOST')     ?: 'db',
        'port'    => getenv('DB_PORT')     ?: 3306,
        'name'    => getenv('DB_NAME')     ?: 'ipwhoam',
        'user'    => getenv('DB_USER')     ?: 'ipwhoam',
        'pass'    => getenv('DB_PASS')     ?: 'changeme',
        'charset' => 'utf8mb4',
    ],

    // How long to cache geo lookups in DB (seconds)
    'geo_cache_ttl' => 86400 * 7, // 7 days

    // Rate-limit: max requests per IP per minute (0 = disabled)
    'rate_limit' => 60,

    // The canonical hostname (used in HTML / JSON responses)
    'hostname' => getenv('APP_HOST') ?: 'ipwho.am',

    // GitHub repository URL
    'github_url' => 'https://github.com/MarkysMarks/ipwho.am',

    // Geo API backend — ipapi.co (free: 30k/month, no key needed)
    'geo_api_url' => 'https://ipapi.co/{ip}/json/',
    'geo_api_timeout' => 3, // seconds
];
