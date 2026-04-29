<?php
// ── ipwho.am — Request Tracker ─────────────────────────────────────────────
class Tracker
{
    /**
     * Log a request to the visits table.
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
            DB::q(
                'INSERT INTO visits
                    (ip, ip_decimal, endpoint, method, user_agent, referer,
                     is_browser, is_api,
                     country_code, country_name, city, region, org, asn,
                     latitude, longitude, timezone, response_ms)
                 VALUES
                    (?, ?, ?, ?, ?, ?,
                     ?, ?,
                     ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?)',
                [
                    $ip,
                    $geo['ip_decimal'] ?? null,
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

            // Upsert into daily_stats
            $col = $isBrowser ? 'web_hits' : ($isApi ? 'api_hits' : 'cli_hits');
            DB::q(
                "INSERT INTO daily_stats (stat_date, {$col})
                 VALUES (CURDATE(), 1)
                 ON DUPLICATE KEY UPDATE {$col} = {$col} + 1",
            );
        } catch (Throwable $e) {
            // Never let tracking break the response
            error_log('[ipwhoam] Tracker::record failed: ' . $e->getMessage());
        }
    }
}
