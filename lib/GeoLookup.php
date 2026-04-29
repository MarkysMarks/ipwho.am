<?php
// ── ipwho.am — IP Geo Lookup with DB caching ───────────────────────────────
class GeoLookup
{
    private static int $cacheTtl = 604800; // 7 days
    private static string $apiUrl = 'https://ipapi.co/{ip}/json/';
    private static int $timeout = 3;

    public static function init(array $cfg): void
    {
        self::$cacheTtl = $cfg['geo_cache_ttl'] ?? 604800;
        self::$apiUrl   = $cfg['geo_api_url']   ?? self::$apiUrl;
        self::$timeout  = $cfg['geo_api_timeout'] ?? 3;
    }

    /**
     * Resolve geo data for an IP.
     * Returns an associative array; all keys always present (may be null).
     */
    public static function lookup(string $ip): array
    {
        // Normalize IP
        $ip = trim($ip);

        // Private / loopback → return local stub
        if (self::isPrivate($ip)) {
            return self::stub($ip, 'Private / Loopback');
        }

        // Try cache
        $cached = self::fromCache($ip);
        if ($cached !== null) {
            return $cached;
        }

        // Fetch from API
        $data = self::fetchFromApi($ip);
        if ($data !== null) {
            self::storeCache($ip, $data);
            return $data;
        }

        // Fallback stub
        return self::stub($ip, 'Unknown');
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private static function fromCache(string $ip): ?array
    {
        try {
            $row = DB::one(
                'SELECT data FROM geo_cache WHERE ip = ? AND expires_at > NOW()',
                [$ip]
            );
            if ($row) {
                DB::q('UPDATE geo_cache SET hits = hits + 1 WHERE ip = ?', [$ip]);
                return json_decode($row['data'], true);
            }
        } catch (Throwable) {}
        return null;
    }

    private static function storeCache(string $ip, array $data): void
    {
        try {
            $expires = date('Y-m-d H:i:s', time() + self::$cacheTtl);
            $json    = json_encode($data, JSON_UNESCAPED_UNICODE);
            DB::q(
                'INSERT INTO geo_cache (ip, data, expires_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at), hits = hits + 1',
                [$ip, $json, $expires]
            );
        } catch (Throwable) {}
    }

    private static function fetchFromApi(string $ip): ?array
    {
        $url = str_replace('{ip}', urlencode($ip), self::$apiUrl);
        $ctx = stream_context_create([
            'http' => [
                'timeout'        => self::$timeout,
                'user_agent'     => 'ipwho.am/1.0',
                'ignore_errors'  => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;

        $d = json_decode($raw, true);
        if (!is_array($d) || isset($d['error'])) return null;

        return [
            'ip'           => $d['ip']           ?? $ip,
            'ip_decimal'   => self::ipToDecimal($d['ip'] ?? $ip),
            'ip_version'   => str_contains($ip, ':') ? 6 : 4,
            'hostname'     => $d['hostname']      ?? null,
            'country_code' => $d['country_code']  ?? null,
            'country_name' => $d['country_name']  ?? null,
            'region'       => $d['region']        ?? null,
            'city'         => $d['city']          ?? null,
            'postal'       => $d['postal']        ?? null,
            'latitude'     => $d['latitude']      ?? null,
            'longitude'    => $d['longitude']     ?? null,
            'timezone'     => $d['timezone']      ?? null,
            'utc_offset'   => $d['utc_offset']    ?? null,
            'asn'          => $d['asn']           ?? null,
            'org'          => $d['org']           ?? null,
            'in_eu'        => self::isEU($d['country_code'] ?? ''),
        ];
    }

    private static function stub(string $ip, string $label): array
    {
        return [
            'ip'           => $ip,
            'ip_decimal'   => self::ipToDecimal($ip),
            'ip_version'   => str_contains($ip, ':') ? 6 : 4,
            'hostname'     => null,
            'country_code' => null,
            'country_name' => $label,
            'region'       => null,
            'city'         => null,
            'postal'       => null,
            'latitude'     => null,
            'longitude'    => null,
            'timezone'     => null,
            'utc_offset'   => null,
            'asn'          => null,
            'org'          => null,
            'in_eu'        => false,
        ];
    }

    public static function ipToDecimal(string $ip): ?int
    {
        if (str_contains($ip, ':')) return null; // IPv6 doesn't fit in BIGINT
        $long = ip2long($ip);
        return $long === false ? null : ($long < 0 ? $long + 4294967296 : $long);
    }

    private static function isPrivate(string $ip): bool
    {
        return in_array($ip, ['127.0.0.1', '::1'], true)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function isEU(string $code): bool
    {
        return in_array(strtoupper($code), [
            'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI',
            'FR','GR','HR','HU','IE','IT','LT','LU','LV','MT',
            'NL','PL','PT','RO','SE','SI','SK'
        ], true);
    }
}
