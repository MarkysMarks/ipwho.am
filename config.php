<?php
// ── ipwho.am — Konfiguration ───────────────────────────────────────────────
// Trage hier deine Datenbankzugangsdaten ein. Fertig.

return [

    'db' => [
        'host'    => 'localhost',          // DB-Host, fast immer localhost
        'port'    => 3306,
        'name'    => 'ipwhoam',            // Datenbankname
        'user'    => 'ipwhoam',            // DB-Benutzername
        'pass'    => 'DEIN_PASSWORT_HIER', // DB-Passwort
        'charset' => 'utf8mb4',
    ],

    // Wie lange Geo-Lookups in der DB gecacht werden (Sekunden)
    'geo_cache_ttl' => 86400 * 7, // 7 Tage

    // Deine Domain (wird in HTML/JSON-Antworten genutzt)
    'hostname' => 'ipwho.am',

    // GitHub Repository URL
    'github_url' => 'https://github.com/MarkysMarks/ipwho.am',

    // Geo-API (ipapi.co — kostenlos bis 30k Anfragen/Monat, kein Key nötig)
    'geo_api_url'     => 'https://ipapi.co/{ip}/json/',
    'geo_api_timeout' => 4,

];
