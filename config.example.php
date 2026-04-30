<?php
// ── ipwho.am — Konfiguration ───────────────────────────────────────────────
// Kopiere diese Datei nach config.php und trage deine Werte ein:
//   cp config.example.php config.php
//
// config.php ist in .gitignore — deine Zugangsdaten werden nie committet.

return [

    'db' => [
        'host'    => 'localhost',          // DB-Host, fast immer localhost
        'port'    => 3306,
        'name'    => 'ipwhoam',            // Datenbankname
        'user'    => 'ipwhoam',            // DB-Benutzername
        'pass'    => 'DEIN_DB_PASSWORT',   // DB-Passwort
        'charset' => 'utf8mb4',
    ],

    // Salt für IP-Hashing — einmalig setzen, NIE mehr ändern!
    // Beliebiger langer zufälliger String, z.B.: openssl rand -hex 32
    'ip_salt' => 'DEIN_LANGER_ZUFALLS_STRING',

    // Deine Domain
    'hostname' => 'ipwho.am',

    // GitHub Repository URL
    'github_url' => 'https://github.com/MarkysMarks/ipwho.am',

    // Geo-API (ipapi.co — kostenlos bis 30k/Monat, kein Key nötig)
    'geo_api_url'     => 'https://ipapi.co/{ip}/json/',
    'geo_api_timeout' => 4,

    // Wie lange Geo-Lookups in der DB gecacht werden (Sekunden)
    'geo_cache_ttl' => 604800, // 7 Tage

];
