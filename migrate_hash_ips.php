#!/usr/bin/env php
<?php
// ── ipwho.am — Migration: Hash existing plaintext IPs in visits table ──────
// Run ONCE on the server after deploying this update:
//   php migrate_hash_ips.php
//
// What it does:
//   - Reads every row in `visits` where `ip` looks like a real IP address
//   - Replaces it with hash_hmac('sha256', $ip, $salt) from config.php
//   - Also anonymises any IP addresses still visible in endpoint paths
//
// Safe to run multiple times (already-hashed rows are skipped).

declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Tracker.php';

DB::init($cfg['db']);
Tracker::init($cfg['ip_salt'] ?? '');

$salt = $cfg['ip_salt'] ?? '';
if ($salt === 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING' || $salt === '') {
    echo "ERROR: ip_salt in config.php not set. Aborting.\n";
    exit(1);
}

// Regex to detect a raw IPv4 address (64-char hashes are already safe)
$ipv4Pattern = '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/';
$ipv6Pattern = '/^[0-9a-fA-F:]{3,39}$/';

$rows = DB::all('SELECT id, ip, endpoint FROM visits');
$updated = 0;
$skipped = 0;

foreach ($rows as $row) {
    $ip       = $row['ip'];
    $endpoint = $row['endpoint'];
    $changed  = false;

    // Hash IP if it looks like a real address
    $isRawIp = preg_match($ipv4Pattern, $ip) || preg_match($ipv6Pattern, $ip);
    if ($isRawIp) {
        $ip      = hash_hmac('sha256', $ip, $salt);
        $changed = true;
    }

    // Anonymise endpoint path
    $anonEndpoint = preg_replace('#/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})(/|$)#', '/{ip}$2', $endpoint);
    $anonEndpoint = preg_replace('#/([0-9a-fA-F:]{3,39})(/|$)#', '/{ip}$2', $anonEndpoint);
    if ($anonEndpoint !== $endpoint) {
        $endpoint = $anonEndpoint;
        $changed  = true;
    }

    if ($changed) {
        DB::q('UPDATE visits SET ip = ?, endpoint = ? WHERE id = ?', [$ip, $endpoint, $row['id']]);
        $updated++;
    } else {
        $skipped++;
    }
}

echo "Migration complete.\n";
echo "  Updated: {$updated} rows\n";
echo "  Skipped (already hashed): {$skipped} rows\n";
