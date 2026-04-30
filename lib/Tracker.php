<?php
// ── ipwho.am — Request Tracker ─────────────────────────────────────────────
class Tracker
{
    private static string $salt = '';

    public static function init(string $salt): void
    {
        self::$salt = $salt;
    }

    /**
     * Hash an IP with HMAC-SHA256 + configured salt.
     * One-way, not reversible without the salt.
     * Same IP → same hash (within this install) so unique-visitor counting works.
     */
    public static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, self::$salt);
    }

    /**
     * Log a request. Stores hashed IP — never the raw address.
     * Non-blocking: swallows all exceptions.
     */
    public static function record(
        string $ip,
        array  $geo,
        string $endpoint,
        string $method,
        bool   $isBrowser,
        bool   $isApi,
        int    $responseMs
    ): void {
        try {
            $hashedIp = self::hashIp($ip);
            $endpoint = self::anonymiseEndpoint($endpoint);

            DB::q(
                'INSERT INTO visits
                    (ip, ip_decimal, endpoint, method, user_agent, referer,
                     is_browser, is_api,
                     country_code, country_name, city, region, org, asn,
                     latitude, longitude, timezone, response_ms)
                 VALUES
                    (?, NULL, ?, ?, ?, ?,
                     ?, ?,
                     ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?)',
                [
                    $hashedIp,
                    $endpoint,
                    $method,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['HTTP_REFERER']    ?? null,
                    (int)$isBrowser,
                    (int)$isApi,
                    $geo['country_code'] ?? null,
                    $geo['country_name'] ?? null,
                    $geo['city']         ?? null,
                    $geo['region']       ?? null,
                    $geo['org']          ?? null,
                    $geo['asn']          ?? null,
                    $geo['latitude']     ?? null,
                    $geo['longitude']    ?? null,
                    $geo['timezone']     ?? null,
                    $responseMs,
                ]
            );

            $col = $isBrowser ? 'web_hits' : ($isApi ? 'api_hits' : 'cli_hits');
            DB::q(
                "INSERT INTO daily_stats (stat_date, {$col})
                 VALUES (CURDATE(), 1)
                 ON DUPLICATE KEY UPDATE {$col} = {$col} + 1"
            );
        } catch (Throwable $e) {
            error_log('[ipwhoam] Tracker::record failed: ' . $e->getMessage());
        }
    }

    /**
     * Replace IP addresses in endpoint paths with /{ip}/
     * /8.8.8.8/json  →  /{ip}/json
     * /2606:4700::1/json  →  /{ip}/json
     */
    private static function anonymiseEndpoint(string $endpoint): string
    {
        $ipv4 = '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}';
        $ipv6 = '[0-9a-fA-F:]{3,39}';
        $endpoint = preg_replace('#/(' . $ipv4 . ')(/|$)#', '/{ip}$2', $endpoint);
        $endpoint = preg_replace('#/(' . $ipv6 . ')(/|$)#', '/{ip}$2', $endpoint);
        return $endpoint;
    }
}
