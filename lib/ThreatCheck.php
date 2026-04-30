<?php
// ── ipwho.am — Threat / Reputation Check ──────────────────────────────────
// Detects: Tor exit nodes, VPN providers, datacenter/hosting IPs, open proxies.
//
// Data sources:
//   - Tor: https://check.torproject.org/torbulkexitlist (cached 1h in DB)
//   - VPN/Proxy/Datacenter: proxycheck.io free API (cached 24h in DB)

class ThreatCheck
{
    private static int $torTtl   = 3600;       // 1 hour
    private static int $vpnTtl   = 86400;      // 24 hours
    private static string $proxyCheckUrl = 'https://proxycheck.io/v2/{ip}?vpn=1&asn=1';
    private static int $timeout  = 3;

    /**
     * Check IP reputation. Returns array:
     * [
     *   'is_tor'        => bool,
     *   'is_vpn'        => bool,
     *   'is_proxy'      => bool,
     *   'is_datacenter' => bool,
     *   'threat_type'   => string|null,  // 'tor'|'vpn'|'proxy'|'datacenter'|null
     * ]
     */
    public static function check(string $ip): array
    {
        $empty = ['is_tor'=>false,'is_vpn'=>false,'is_proxy'=>false,'is_datacenter'=>false,'threat_type'=>null];

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $empty;
        }

        // 1. Check DB cache first
        $cached = self::fromCache($ip);
        if ($cached !== null) return $cached;

        // 2. Check Tor exit list
        $isTor = self::isTorExit($ip);

        // 3. Check proxycheck.io (VPN / proxy / datacenter)
        $proxy = self::checkProxyApi($ip);

        $result = [
            'is_tor'        => $isTor,
            'is_vpn'        => $proxy['vpn']        ?? false,
            'is_proxy'      => $proxy['proxy']       ?? false,
            'is_datacenter' => $proxy['datacenter']  ?? false,
            'threat_type'   => null,
        ];

        if ($result['is_tor'])        $result['threat_type'] = 'tor';
        elseif ($result['is_vpn'])    $result['threat_type'] = 'vpn';
        elseif ($result['is_proxy'])  $result['threat_type'] = 'proxy';
        elseif ($result['is_datacenter']) $result['threat_type'] = 'datacenter';

        self::storeCache($ip, $result);
        return $result;
    }

    // ── Tor ──────────────────────────────────────────────────────────────────

    private static function isTorExit(string $ip): bool
    {
        try {
            // Check if we have a fresh Tor list in DB
            $row = DB::one(
                "SELECT data FROM reputation_cache WHERE cache_key = 'tor_list' AND expires_at > NOW()"
            );

            if ($row) {
                $list = json_decode($row['data'], true) ?? [];
            } else {
                $list = self::fetchTorList();
                if ($list !== null) {
                    self::storeMeta('tor_list', json_encode($list), self::$torTtl);
                } else {
                    $list = [];
                }
            }

            return in_array($ip, $list, true);
        } catch (Throwable) {
            return false;
        }
    }

    private static function fetchTorList(): ?array
    {
        $ctx = stream_context_create(['http'=>['timeout'=>5,'user_agent'=>'ipwho.am/1.0','ignore_errors'=>true]]);
        $raw = @file_get_contents('https://check.torproject.org/torbulkexitlist', false, $ctx);
        if ($raw === false) return null;
        return array_filter(array_map('trim', explode("\n", $raw)), fn($l) => filter_var($l, FILTER_VALIDATE_IP));
    }

    // ── proxycheck.io ─────────────────────────────────────────────────────────

    private static function checkProxyApi(string $ip): array
    {
        $url = str_replace('{ip}', urlencode($ip), self::$proxyCheckUrl);
        $ctx = stream_context_create(['http'=>['timeout'=>self::$timeout,'user_agent'=>'ipwho.am/1.0','ignore_errors'=>true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return [];

        $d = json_decode($raw, true);
        if (!is_array($d) || ($d['status'] ?? '') === 'error') return [];

        $entry = $d[$ip] ?? [];
        return [
            'vpn'        => ($entry['proxy'] ?? 'no') === 'yes' && ($entry['type'] ?? '') === 'VPN',
            'proxy'      => ($entry['proxy'] ?? 'no') === 'yes' && ($entry['type'] ?? '') !== 'VPN',
            'datacenter' => ($entry['type'] ?? '') === 'Hosting',
        ];
    }

    // ── DB cache ─────────────────────────────────────────────────────────────

    private static function fromCache(string $ip): ?array
    {
        try {
            $row = DB::one(
                "SELECT data FROM reputation_cache WHERE cache_key = ? AND expires_at > NOW()",
                ["ip:{$ip}"]
            );
            return $row ? json_decode($row['data'], true) : null;
        } catch (Throwable) { return null; }
    }

    private static function storeCache(string $ip, array $data): void
    {
        self::storeMeta("ip:{$ip}", json_encode($data), self::$vpnTtl);
    }

    private static function storeMeta(string $key, string $data, int $ttl): void
    {
        try {
            $expires = date('Y-m-d H:i:s', time() + $ttl);
            DB::q(
                "INSERT INTO reputation_cache (cache_key, data, expires_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)",
                [$key, $data, $expires]
            );
        } catch (Throwable) {}
    }
}
