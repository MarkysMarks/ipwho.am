<?php
// ── ipwho.am — Main Router ─────────────────────────────────────────────────
declare(strict_types=1);

$startTime = microtime(true);

// ── Bootstrap ──────────────────────────────────────────────────────────────
$cfg = require __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    $cfg   = array_replace_recursive($cfg, $local);
}

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/GeoLookup.php';
require __DIR__ . '/lib/Tracker.php';
require __DIR__ . '/lib/ThreatCheck.php';

DB::init($cfg['db']);
GeoLookup::init($cfg);
Tracker::init($cfg['ip_salt'] ?? 'fallback-salt-set-in-config');

// ── Helpers ─────────────────────────────────────────────────────────────────

/** Get the real client IP, respecting common proxy headers */
function clientIp(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        $val = $_SERVER[$k] ?? '';
        if ($val === '') continue;
        // X-Forwarded-For can be a list; take first
        $ip = trim(explode(',', $val)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

/** Detect whether this request comes from a real browser */
function isBrowserRequest(): bool
{
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT']     ?? '';

    // Explicit CLI tools — never a browser
    if (preg_match('/^(curl|wget|HTTPie|python-requests|Go-http|Java\/|Perl|Ruby|php-)/i', $ua)) {
        return false;
    }
    // No UA at all
    if ($ua === '') return false;

    // Browser Accept header always contains text/html
    if (str_contains($accept, 'text/html')) return true;

    // Fallback: if UA looks like a browser engine
    return (bool)preg_match('/(Mozilla|Chrome|Safari|Firefox|Opera|Edge)/i', $ua);
}

/** Detect explicit JSON/API request */
function isApiRequest(string $path): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($accept, 'application/json')
        || str_ends_with($path, '.json')
        || str_ends_with($path, '/json');
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function textResponse(string $text, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo $text . "\n";
    exit;
}

function elapsedMs(float $start): int
{
    return (int)(( microtime(true) - $start ) * 1000);
}

// ── Parse request ──────────────────────────────────────────────────────────
$method     = strtoupper($_SERVER['REQUEST_METHOD']);
$rawPath    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path       = rtrim(str_replace('.json', '', $rawPath), '/');
$path       = $path === '' ? '/' : $path;
$isBrowser  = isBrowserRequest();
$isApi      = isApiRequest($rawPath);
$clientIp   = clientIp();

// CORS preflight
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Accept');
    http_response_code(204);
    exit;
}

// Only GET allowed
if ($method !== 'GET') {
    jsonResponse(['error' => 'Method Not Allowed'], 405);
}

// ── Route ──────────────────────────────────────────────────────────────────

// /ping — health check / latency test
if ($path === '/ping') {
    $ms = elapsedMs($startTime);
    if ($isBrowser) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>pong — ipwho.am</title>'
           . '<link rel="icon" type="image/svg+xml" href="/favicon.svg">'
           . '<style>body{background:#060c06;color:#00ff88;font-family:monospace;display:flex;'
           . 'align-items:center;justify-content:center;min-height:100vh;margin:0;flex-direction:column;gap:.5rem}'
           . 'h1{font-size:3rem;margin:0;text-shadow:0 0 20px #00ff88}p{color:#5a8a65;font-size:.85rem}'
           . 'a{color:#00cc66;text-decoration:none}'
           . '</style></head><body>'
           . '<h1>PONG</h1>'
           . '<p>response time: <span style="color:#00e5ff">' . $ms . 'ms</span></p>'
           . '<p>server: <span style="color:#00e5ff">' . htmlspecialchars($cfg['hostname']) . '</span></p>'
           . '<p><a href="/">← back</a></p>'
           . '</body></html>';
    } else {
        header('X-Response-Time: ' . $ms . 'ms');
        textResponse('pong');
    }
    exit;
}

// /map → map page
if ($path === '/map') {
    header('Location: /map/');
    exit;
}

// /stats → redirect to stats page
if ($path === '/stats') {
    header('Location: /stats/');
    exit;
}

// /datenschutz → redirect to privacy page
if ($path === '/datenschutz' || $path === '/privacy') {
    header('Location: /datenschutz/');
    exit;
}

// /api/stats → internal stats API for the dashboard
if ($path === '/api/stats') {
    handleStatsApi();
}

// /{ip}/json  or  /{ip}  — look up a specific IP
$pathParts = explode('/', trim($path, '/'));
$lookupIp  = null;
if (count($pathParts) >= 1 && filter_var($pathParts[0], FILTER_VALIDATE_IP)) {
    $lookupIp = $pathParts[0];
}

// Resolve geo for target IP
$targetIp  = $lookupIp ?? $clientIp;
$geo       = GeoLookup::lookup($targetIp);


// /port/{port} — check if port is reachable on client IP
if (preg_match('#^/port/(\d+)$#', $path, $m)) {
    $port = (int)$m[1];
    if ($port < 1 || $port > 65535) {
        jsonResponse(['error' => 'Port must be 1–65535'], 400);
    }
    $targetIp  = $lookupIp ?? $clientIp;
    $startConn = microtime(true);
    $sock      = @fsockopen($targetIp, $port, $errno, $errstr, 2);
    $ms        = (int)((microtime(true) - $startConn) * 1000);
    $open      = $sock !== false;
    if ($sock) fclose($sock);

    track($clientIp, [], '/port/' . $port, $isBrowser, $isApi);

    if ($isBrowser || $isApi) {
        jsonResponse([
            'ip'         => $targetIp,
            'port'       => $port,
            'reachable'  => $open,
            'latency_ms' => $open ? $ms : null,
            'message'    => $open ? "Port {$port} is open" : "Port {$port} is closed or filtered",
        ]);
    } else {
        textResponse($open ? "open" : "closed");
    }
}


// ── Field-specific endpoints ──────────────────────────────────────────────
$fieldMap = [
    '/country'     => 'country_name',
    '/country-iso' => 'country_code',
    '/city'        => 'city',
    '/region'      => 'region',
    '/postal'      => 'postal',
    '/asn'         => 'asn',
    '/org'         => 'org',
    '/timezone'    => 'timezone',
    '/hostname'    => 'hostname',
    '/latitude'    => 'latitude',
    '/longitude'   => 'longitude',
];

$fieldPath = $lookupIp ? '/' . implode('/', array_slice($pathParts, 1)) : $path;

if (isset($fieldMap[$fieldPath])) {
    $value = $geo[$fieldMap[$fieldPath]] ?? '';
    track($clientIp, $geo, $path, $isBrowser, $isApi);

    if ($isApi || !$isBrowser) {
        textResponse((string)$value);
    } else {
        textResponse((string)$value); // fields always plain text
    }
}

// ── /json or Accept: application/json ────────────────────────────────────
if ($fieldPath === '/json' || $isApi || (!$isBrowser && $path !== '/')) {
    track($clientIp, $geo, $path, $isBrowser, true);
    jsonResponse(buildFullResponse($targetIp, $geo));
}

// ── / — Root endpoint ─────────────────────────────────────────────────────
if ($path === '/') {
    if ($isBrowser) {
        // Serve HTML page
        track($clientIp, $geo, '/', true, false);
        serveHtml($targetIp, $geo, $cfg);
    } else {
        // CLI: return plain IP
        track($clientIp, $geo, '/', false, false);
        textResponse($geo['ip']);
    }
}

// 404
jsonResponse(['error' => 'Not Found', 'path' => $path], 404);

// ── Functions ──────────────────────────────────────────────────────────────

function track(string $ip, array $geo, string $path, bool $isBrowser, bool $isApi): void
{
    global $startTime;
    Tracker::record($ip, $geo, $path, $_SERVER['REQUEST_METHOD'], $isBrowser, $isApi, elapsedMs($startTime));
}

function buildFullResponse(string $ip, array $geo): array
{
    $threat = ThreatCheck::check($ip);
    return [
        'ip'           => $geo['ip'],
        'ip_decimal'   => $geo['ip_decimal'],
        'ip_version'   => $geo['ip_version'],
        'hostname'     => $geo['hostname'],
        'country_code' => $geo['country_code'],
        'country_name' => $geo['country_name'],
        'region'       => $geo['region'],
        'city'         => $geo['city'],
        'postal'       => $geo['postal'],
        'latitude'     => $geo['latitude'],
        'longitude'    => $geo['longitude'],
        'timezone'     => $geo['timezone'],
        'utc_offset'   => $geo['utc_offset'],
        'asn'          => $geo['asn'],
        'org'          => $geo['org'],
        'in_eu'        => $geo['in_eu'],
        'is_tor'       => $threat['is_tor'],
        'is_vpn'       => $threat['is_vpn'],
        'is_proxy'     => $threat['is_proxy'],
        'is_datacenter'=> $threat['is_datacenter'],
        'threat_type'  => $threat['threat_type'],
    ];
}

function handleStatsApi(): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $range = (int)($_GET['days'] ?? 30);
    $range = max(7, min(90, $range));

    try {
        // Daily hits for the last N days
        $daily = DB::all(
            "SELECT
                DATE(created_at) AS day,
                SUM(is_browser)  AS web,
                SUM(is_api)      AS api,
                SUM(CASE WHEN is_browser = 0 AND is_api = 0 THEN 1 ELSE 0 END) AS cli,
                COUNT(*)         AS total
             FROM visits
             WHERE created_at >= CURDATE() - INTERVAL ? DAY
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            [$range]
        );

        // Totals
        $totals = DB::one(
            "SELECT
                COUNT(*)         AS total,
                SUM(is_browser)  AS web,
                SUM(is_api)      AS api,
                SUM(CASE WHEN is_browser = 0 AND is_api = 0 THEN 1 ELSE 0 END) AS cli,
                COUNT(DISTINCT ip) AS unique_ips
             FROM visits"
        );

        // Today
        $today = DB::one(
            "SELECT
                COUNT(*)         AS total,
                SUM(is_browser)  AS web,
                SUM(is_api)      AS api
             FROM visits
             WHERE DATE(created_at) = CURDATE()"
        );

        // Top countries
        $countries = DB::all(
            "SELECT country_code, country_name,
                    COUNT(*) AS hits,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM visits), 1) AS pct
             FROM visits
             WHERE country_code IS NOT NULL
             GROUP BY country_code, country_name
             ORDER BY hits DESC
             LIMIT 10"
        );

        // Hourly distribution (all time)
        $hourly = DB::all(
            "SELECT HOUR(created_at) AS hour, COUNT(*) AS hits
             FROM visits
             WHERE created_at >= CURDATE() - INTERVAL 30 DAY
             GROUP BY HOUR(created_at)
             ORDER BY hour"
        );

        // Top endpoints
        $endpoints = DB::all(
            "SELECT endpoint, COUNT(*) AS hits
             FROM visits
             GROUP BY endpoint
             ORDER BY hits DESC
             LIMIT 10"
        );

        // Weekday distribution
        $weekdays = DB::all(
            "SELECT DAYOFWEEK(created_at) AS dow, COUNT(*) AS hits
             FROM visits
             WHERE created_at >= CURDATE() - INTERVAL 30 DAY
             GROUP BY DAYOFWEEK(created_at)
             ORDER BY dow"
        );

        echo json_encode([
            'ok'        => true,
            'daily'     => $daily,
            'totals'    => $totals,
            'today'     => $today,
            'countries' => $countries,
            'hourly'    => $hourly,
            'endpoints' => $endpoints,
            'weekdays'  => $weekdays,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function serveHtml(string $ip, array $geo, array $cfg): never
{
    $esc  = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    $json = json_encode(buildFullResponse($ip, $geo), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $eu_countries = ['AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'];
    $flag = function($code) {
        if (!$code || strlen($code) !== 2) return '';
        $cp1 = 0x1F1E6 + (ord(strtoupper($code)[0]) - 65);
        $cp2 = 0x1F1E6 + (ord(strtoupper($code)[1]) - 65);
        return mb_convert_encoding("&#$cp1;&#$cp2;", 'UTF-8', 'HTML-ENTITIES');
    };

    $countryFlag = $flag($geo['country_code']);
    $isEu        = in_array($geo['country_code'], $eu_countries) ? 'true ✓' : 'false';
    $ipVersion   = $geo['ip_version'] === 6 ? 'IPv6' : 'IPv4';
    $hostname    = $cfg['hostname'];
    $github      = $cfg['github_url'];
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ipwho.am — What is my IP?</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<meta name="description" content="Find out your IP address, location, ASN, and more. Works with curl. Open source.">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;700&display=swap" rel="stylesheet">
<style>
:root{--green:#00ff88;--green-dim:#00cc66;--green-dark:#007a3d;--green-faint:#003320;--orange:#ff6b35;--cyan:#00e5ff;--amber:#ffb300;--bg:#060c06;--bg2:#0a110a;--bg3:#0e180e;--border:#0f2a0f;--border2:#163a16;--text:#c8f0d4;--text-dim:#5a8a65;--text-faint:#2a4a30}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'JetBrains Mono',monospace;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.08) 2px,rgba(0,0,0,0.08) 4px);pointer-events:none;z-index:1000}
body::after{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at center,transparent 60%,rgba(0,0,0,.7) 100%);pointer-events:none;z-index:999}
.glow{text-shadow:0 0 10px var(--green),0 0 20px rgba(0,255,136,.4)}
.wrapper{max-width:900px;margin:0 auto;padding:2rem 1.5rem 4rem}
@keyframes fadeInUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes blink{50%{opacity:0}}
@keyframes glitch{0%{transform:translate(0)}20%{transform:translate(-3px,1px);color:var(--cyan)}40%{transform:translate(3px,-1px);color:var(--orange)}60%{transform:translate(-1px,2px)}80%{transform:translate(2px,-2px);color:var(--green)}100%{transform:translate(0)}}
@keyframes scanline-h{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}
.ascii-header{color:var(--green-dark);font-size:.55rem;line-height:1.1;white-space:pre;margin-bottom:1.5rem;animation:fadeInUp .8s ease both}
.top-bar{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border2);padding-bottom:.75rem;margin-bottom:2.5rem;animation:fadeInUp .5s ease both;flex-wrap:wrap;gap:.5rem}
.top-bar-left{display:flex;align-items:center;gap:.75rem}
.top-bar-right{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.traffic-lights{display:flex;gap:6px}.dot{width:12px;height:12px;border-radius:50%}
.dot-red{background:#ff5f57}.dot-amber{background:#febc2e}.dot-green{background:#28c840}
.site-title{color:var(--green);font-size:1rem;font-weight:700;letter-spacing:.05em}
.site-title span{color:var(--orange)}
.nav-link{font-size:.65rem;color:var(--text-dim);border:1px solid var(--border2);padding:2px 10px;border-radius:2px;letter-spacing:.1em;text-decoration:none;transition:all .2s}
.nav-link:hover{color:var(--green);border-color:var(--green-dark)}
.nav-link.cyan{color:var(--cyan);border-color:#0a2a35}
.status-badge{font-size:.65rem;color:var(--green-dim);border:1px solid var(--border2);padding:2px 10px;border-radius:2px;letter-spacing:.1em}
.status-badge::before{content:'● ';color:#28c840;animation:blink 1.5s step-start infinite}
.hero{background:var(--bg2);border:1px solid var(--border2);border-top:2px solid var(--green-dark);border-radius:4px;padding:2rem 2.5rem;margin-bottom:2rem;position:relative;overflow:hidden;animation:fadeInUp .6s ease .1s both}
.hero::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--green),var(--cyan),var(--green),transparent);animation:scanline-h 4s linear infinite;opacity:.6}
.hero-label{font-size:.65rem;color:var(--text-dim);letter-spacing:.2em;text-transform:uppercase;margin-bottom:.5rem}
.hero-label::before{content:'$ ';color:var(--green)}
.ip-display{font-size:clamp(2rem,5vw,3.5rem);font-weight:700;color:var(--green);letter-spacing:.05em;line-height:1;margin-bottom:1rem}
.ip-display.glitch-anim{animation:glitch .15s linear}
.hero-meta{display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap}
.meta-tag{display:flex;align-items:center;gap:.4rem;font-size:.8rem}
.meta-tag .label{color:var(--text-dim)}.meta-tag .val{color:var(--cyan)}
.copy-btn{margin-left:auto;background:transparent;border:1px solid var(--border2);color:var(--green-dim);font-family:inherit;font-size:.7rem;padding:6px 14px;cursor:pointer;letter-spacing:.1em;border-radius:2px;transition:all .2s;text-transform:uppercase}
.copy-btn:hover{border-color:var(--green);color:var(--green);box-shadow:0 0 10px rgba(0,255,136,.2)}
.copy-btn.copied{border-color:var(--amber);color:var(--amber)}
.sections-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:2rem}
@media(max-width:600px){.sections-grid{grid-template-columns:1fr}.hero{padding:1.25rem}}
.section-card{background:var(--bg2);border:1px solid var(--border);border-radius:4px;overflow:hidden;animation:fadeInUp .6s ease both}
.section-card:nth-child(1){animation-delay:.2s}.section-card:nth-child(2){animation-delay:.3s}.section-card:nth-child(3){animation-delay:.4s}.section-card:nth-child(4){animation-delay:.5s}
.card-header{background:var(--bg3);border-bottom:1px solid var(--border);padding:.5rem 1rem;font-size:.65rem;letter-spacing:.2em;color:var(--text-dim);text-transform:uppercase;display:flex;align-items:center;gap:.5rem}
.data-table{width:100%;border-collapse:collapse}
.data-table tr{border-bottom:1px solid var(--border);transition:background .15s}
.data-table tr:last-child{border-bottom:none}.data-table tr:hover{background:var(--green-faint)}
.data-table td{padding:.55rem 1rem;font-size:.75rem;vertical-align:middle}
.data-table .key{color:var(--text-dim);width:45%;white-space:nowrap}
.data-table .key::before{content:'─ ';color:var(--text-faint)}
.data-table .val{color:var(--text);font-weight:500;word-break:break-all}
.data-table .val.c{color:var(--cyan)}.data-table .val.o{color:var(--orange)}.data-table .val.a{color:var(--amber)}.data-table .val.g{color:var(--green)}
.terminal-section{background:var(--bg2);border:1px solid var(--border2);border-radius:4px;margin-bottom:2rem;overflow:hidden;animation:fadeInUp .6s ease .6s both}
.term-header{background:var(--bg3);border-bottom:1px solid var(--border2);padding:.6rem 1rem;font-size:.65rem;color:var(--text-dim);letter-spacing:.15em;display:flex;align-items:center;gap:.5rem}
.term-tabs{display:flex;border-bottom:1px solid var(--border);overflow-x:auto}
.tab-btn{background:transparent;border:none;border-bottom:2px solid transparent;color:var(--text-dim);font-family:inherit;font-size:.65rem;letter-spacing:.15em;padding:.6rem 1.2rem;cursor:pointer;text-transform:uppercase;transition:all .2s;white-space:nowrap}
.tab-btn:hover{color:var(--text);background:var(--green-faint)}.tab-btn.active{color:var(--green);border-bottom-color:var(--green)}
.tab-content{display:none;padding:1.25rem 1.5rem;font-size:.78rem}.tab-content.active{display:block}
.tl{display:flex;align-items:flex-start;gap:.5rem;flex-wrap:wrap;line-height:1.8}
.tp{color:var(--green);flex-shrink:0}.tc{color:var(--text)}.tm{color:var(--text-dim)}
.to{color:var(--cyan);padding-left:1.2rem;word-break:break-all}
.ep{display:flex;align-items:center;gap:.75rem;padding:.4rem 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.ep:last-child{border-bottom:none}
.ep-m{color:var(--amber);font-weight:700;font-size:.65rem;letter-spacing:.1em;min-width:35px}
.ep-p{color:var(--cyan);flex:1;min-width:180px}.ep-d{color:var(--text-dim);font-size:.7rem}
.privacy-box{background:var(--bg2);border:1px solid var(--border);border-left:3px solid var(--green-dark);border-radius:4px;padding:1.5rem;margin-bottom:2rem;animation:fadeInUp .6s ease .65s both}
.privacy-box-title{font-size:.65rem;letter-spacing:.2em;color:var(--green-dim);text-transform:uppercase;margin-bottom:1rem}
.privacy-box-title::before{content:'🔒 '}
.privacy-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:600px){.privacy-grid{grid-template-columns:1fr}}
.pi{padding:.75rem;background:var(--bg3);border:1px solid var(--border);border-radius:2px}
.pi-t{font-size:.62rem;color:var(--text-faint);letter-spacing:.15em;text-transform:uppercase;margin-bottom:.35rem}
.pi-b{font-size:.72rem;color:var(--text-dim);line-height:1.7}
.pi-b a{color:var(--green-dim);text-decoration:none}.pi-b a:hover{color:var(--green)}
.faq{animation:fadeInUp .6s ease .7s both}
.faq-title{font-size:.65rem;letter-spacing:.2em;color:var(--text-dim);text-transform:uppercase;margin-bottom:1rem;display:flex;align-items:center;gap:.75rem}
.faq-title::after{content:'';flex:1;height:1px;background:var(--border2)}
.faq-item{border:1px solid var(--border);border-radius:4px;margin-bottom:.75rem;overflow:hidden}
.faq-q{padding:.9rem 1.2rem;font-size:.8rem;color:var(--green-dim);cursor:pointer;display:flex;justify-content:space-between;align-items:center;transition:background .15s;user-select:none;background:var(--bg2)}
.faq-q:hover{background:var(--green-faint)}.faq-q::before{content:'//';color:var(--text-faint);margin-right:.6rem}
.faq-arr{color:var(--text-faint);transition:transform .2s}.faq-item.open .faq-arr{transform:rotate(90deg)}
.faq-a{display:none;padding:1rem 1.2rem 1rem 2.5rem;font-size:.75rem;color:var(--text-dim);line-height:1.8;background:var(--bg);border-top:1px solid var(--border)}
.faq-item.open .faq-a{display:block}
.faq-a code{background:var(--green-faint);color:var(--green);padding:1px 6px;border-radius:2px;font-family:inherit;font-size:.85em}
footer{text-align:center;padding-top:2rem;border-top:1px solid var(--border);font-size:.65rem;color:var(--text-faint);letter-spacing:.1em;animation:fadeInUp .6s ease .8s both;margin-top:2rem}
.ipv6-sub{font-size:1.1rem;font-weight:500;color:var(--green-dim);letter-spacing:.03em;margin-top:.3rem;opacity:.75;display:none}
.ipv6-sub .ipv6-label{font-size:.55rem;color:var(--text-faint);letter-spacing:.15em;text-transform:uppercase;vertical-align:middle;margin-right:.4rem}
.mini-map-card{background:var(--bg2);border:1px solid var(--border);border-radius:4px;overflow:hidden;animation:fadeInUp .6s ease .25s both;display:flex;flex-direction:column}
#mini-map{flex:1;min-height:0;width:100%;z-index:1}
.mini-map-card .card-header{display:flex;align-items:center;justify-content:space-between}
.mini-map-card .card-header a{font-size:.6rem;color:var(--text-faint);text-decoration:none;letter-spacing:.1em;transition:color .2s}
.mini-map-card .card-header a:hover{color:var(--green)}
.mini-map-no-geo{padding:1.5rem;text-align:center;font-size:.72rem;color:var(--text-faint)}
.leaflet-popup-content-wrapper{background:var(--bg2);color:var(--text);border:1px solid var(--border2);border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:.72rem;box-shadow:none}
.leaflet-popup-tip{background:var(--bg2)}
.leaflet-popup-content{margin:.6rem .9rem;line-height:1.7}
.leaflet-popup-content strong{color:var(--green)}
.leaflet-control-zoom a{background:var(--bg2)!important;color:var(--text)!important;border-color:var(--border2)!important}
.leaflet-control-zoom a:hover{background:var(--bg3)!important}
.leaflet-control-attribution{background:rgba(6,12,6,.8)!important;color:var(--text-faint)!important;font-size:.5rem!important}
.leaflet-control-attribution a{color:var(--text-dim)!important}
.ip-search-wrap{margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--border)}
.ip-search-row{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
.ip-search-input{background:var(--bg3);border:1px solid var(--border2);color:var(--text);font-family:inherit;font-size:.85rem;padding:8px 14px;border-radius:2px;flex:1;min-width:200px;outline:none;transition:border-color .2s}
.ip-search-input::placeholder{color:var(--text-faint)}
.ip-search-input:focus{border-color:var(--green-dark)}
.ip-search-btn{background:transparent;border:1px solid var(--green-dark);color:var(--green-dim);font-family:inherit;font-size:.7rem;padding:8px 16px;cursor:pointer;letter-spacing:.1em;border-radius:2px;transition:all .2s;white-space:nowrap}
.ip-search-btn:hover{border-color:var(--green);color:var(--green);box-shadow:0 0 8px rgba(0,255,136,.15)}
.ip-search-btn:disabled{opacity:.4;cursor:not-allowed}
.ip-search-label{font-size:.6rem;color:var(--text-faint);letter-spacing:.15em;text-transform:uppercase;margin-bottom:.4rem}
.ip-search-label::before{content:'$ ';color:var(--text-faint)}
.search-result{margin-top:.75rem;padding:.75rem 1rem;background:var(--bg3);border:1px solid var(--border2);border-radius:2px;font-size:.75rem;display:none}
.search-result.visible{display:block;animation:fadeInUp .2s ease both}
.search-result-ip{font-size:1.4rem;font-weight:700;color:var(--green);margin-bottom:.5rem}
.search-result-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.5rem}
.sr-field{display:flex;flex-direction:column;gap:.15rem}
.sr-key{font-size:.6rem;color:var(--text-faint);letter-spacing:.1em;text-transform:uppercase}
.sr-val{color:var(--cyan);font-size:.78rem}
.search-error{color:var(--orange);font-size:.75rem;margin-top:.5rem}
footer a{color:var(--text-dim);text-decoration:none;transition:color .2s}
footer a:hover{color:var(--green)}
</style>
</head>
<body>
<div class="wrapper">

<pre class="ascii-header" aria-hidden="true">
 ██╗██████╗ ██╗    ██╗██╗  ██╗ ██████╗      █████╗ ███╗   ███╗
 ██║██╔══██╗██║    ██║██║  ██║██╔═══██╗    ██╔══██╗████╗ ████║
 ██║██████╔╝██║ █╗ ██║███████║██║   ██║    ███████║██╔████╔██║
 ██║██╔═══╝ ██║███╗██║██╔══██║██║   ██║    ██╔══██║██║╚██╔╝██║
 ██║██║     ╚███╔███╔╝██║  ██║╚██████╔╝    ██║  ██║██║ ╚═╝ ██║
 ╚═╝╚═╝      ╚══╝╚══╝ ╚═╝  ╚═╝ ╚═════╝     ╚═╝  ╚═╝╚═╝     ╚═╝</pre>

<div class="top-bar">
  <div class="top-bar-left">
    <div class="traffic-lights"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
    <span class="site-title">ipwho<span>.am</span></span>
  </div>
  <div class="top-bar-right">
    <a href="/stats/" class="nav-link cyan">[ 📊 stats ]</a>
    <a href="/map/" class="nav-link cyan">[ 🗺 map ]</a>
    <a href="/datenschutz/" class="nav-link">[ 🔒 datenschutz ]</a>
    <a href="<?= $esc($github) ?>" target="_blank" class="nav-link">[ ⌥ github ]</a>
    <div class="status-badge">ONLINE</div>
  </div>
</div>

<div class="hero">
  <div class="hero-label">curl <?= $esc($hostname) ?></div>
  <div class="ip-display glow" id="ip-display"><?= $esc($geo['ip']) ?></div>
  <div class="ipv6-sub" id="ipv6-sub"><span class="ipv6-label">IPv6</span><span id="ipv6-addr"></span></div>
  <div class="hero-meta">
    <div class="meta-tag"><span class="label">protocol</span><span class="val"><?= $esc($ipVersion) ?></span></div>
    <div class="meta-tag"><span class="label">country</span><span class="val"><?= $countryFlag ?> <?= $esc($geo['country_name']) ?></span></div>
    <div class="meta-tag"><span class="label">org</span><span class="val"><?= $esc($geo['org'] ?? $geo['asn'] ?? '—') ?></span></div>
    <button class="copy-btn" id="copy-btn" onclick="copyIP('<?= $esc($geo['ip']) ?>')">[ copy ]</button>
  </div>
  <!-- IP Search -->
  <div class="ip-search-wrap">
    <div class="ip-search-label">lookup any ip or domain</div>
    <div class="ip-search-row">
      <input class="ip-search-input" id="search-input" type="text"
             placeholder="8.8.8.8 or 2606:4700::1 ..."
             onkeydown="if(event.key==='Enter')doSearch()">
      <button class="ip-search-btn" id="search-btn" onclick="doSearch()">[ lookup → ]</button>
    </div>
    <div class="search-result" id="search-result">
      <div class="search-result-ip" id="sr-ip"></div>
      <div class="search-result-grid">
        <div class="sr-field"><span class="sr-key">country</span><span class="sr-val" id="sr-country"></span></div>
        <div class="sr-field"><span class="sr-key">city</span><span class="sr-val" id="sr-city"></span></div>
        <div class="sr-field"><span class="sr-key">org / ASN</span><span class="sr-val" id="sr-org"></span></div>
        <div class="sr-field"><span class="sr-key">timezone</span><span class="sr-val" id="sr-tz"></span></div>
        <div class="sr-field"><span class="sr-key">hostname</span><span class="sr-val" id="sr-host"></span></div>
        <div class="sr-field"><span class="sr-key">in EU?</span><span class="sr-val" id="sr-eu"></span></div>
      </div>
      <div class="search-error" id="sr-error" style="display:none"></div>
    </div>
  </div>
</div>

<div class="sections-grid">
  <div class="section-card">
    <div class="card-header"><span>◈</span> GEO / LOCATION</div>
    <table class="data-table">
      <tr><td class="key">country</td><td class="val c"><?= $countryFlag ?> <?= $esc($geo['country_name'] ?? '—') ?></td></tr>
      <tr><td class="key">country code</td><td class="val"><?= $esc($geo['country_code'] ?? '—') ?><?= $geo['in_eu'] ? ' <span style="color:var(--green)">(EU ✓)</span>' : '' ?></td></tr>
      <tr><td class="key">region</td><td class="val"><?= $esc($geo['region'] ?? '—') ?></td></tr>
      <tr><td class="key">city</td><td class="val a"><?= $esc($geo['city'] ?? '—') ?></td></tr>
      <tr><td class="key">postal code</td><td class="val"><?= $esc($geo['postal'] ?? '—') ?></td></tr>
      <tr><td class="key">latitude</td><td class="val"><?= $geo['latitude'] ? number_format((float)$geo['latitude'], 4).'°' : '—' ?></td></tr>
      <tr><td class="key">longitude</td><td class="val"><?= $geo['longitude'] ? number_format((float)$geo['longitude'], 4).'°' : '—' ?></td></tr>
      <tr><td class="key">timezone</td><td class="val"><?= $esc($geo['timezone'] ?? '—') ?></td></tr>
      <tr><td class="key">in EU?</td><td class="val <?= $geo['in_eu'] ? 'g' : '' ?>"><?= $isEu ?></td></tr>
    </table>
  </div>
  <div class="mini-map-card">
    <div class="card-header">
      <span>◈ STANDORT</span>
      <?php if ($geo['latitude']): ?><a href="/map/">Vollbild →</a><?php endif; ?>
    </div>
    <?php if ($geo['latitude'] && $geo['longitude']): ?>
    <div id="mini-map"></div>
    <?php else: ?>
    <div class="mini-map-no-geo">Kein Geo-Standort verfügbar.</div>
    <?php endif; ?>
  </div>
  <div class="section-card">
    <div class="card-header"><span>◈</span> NETWORK / ASN</div>
    <table class="data-table">
      <tr><td class="key">ip address</td><td class="val c"><?= $esc($geo['ip']) ?></td></tr>
      <tr><td class="key">ip decimal</td><td class="val"><?= $geo['ip_decimal'] ? number_format((float)$geo['ip_decimal'], 0, ',', '.') : 'N/A (IPv6)' ?></td></tr>
      <tr><td class="key">ip version</td><td class="val"><?= $esc($ipVersion) ?></td></tr>
      <tr><td class="key">hostname</td><td class="val"><?= $esc($geo['hostname'] ?? 'N/A') ?></td></tr>
      <tr><td class="key">ASN</td><td class="val o"><?= $esc($geo['asn'] ?? '—') ?></td></tr>
      <tr><td class="key">ASN org</td><td class="val"><?= $esc(trim(str_replace($geo['asn'] ?? '', '', $geo['org'] ?? ''))) ?: '—' ?></td></tr>
      <tr><td class="key">ISP</td><td class="val"><?= $esc($geo['org'] ?? '—') ?></td></tr>
    </table>
  </div>
  <div class="section-card" style="animation-delay:.35s">
    <div class="card-header"><span>◈</span> REPUTATION / THREAT</div>
    <table class="data-table" id="threat-table">
      <tr><td class="key">checking</td><td class="val loading" id="t-status">resolving</td></tr>
    </table>
    </table>
  </div>
  <div class="section-card">
    <div class="card-header"><span>◈</span> CLIENT / BROWSER</div>
    <table class="data-table">
      <tr><td class="key">user agent</td><td class="val" style="font-size:.65rem;word-break:break-word" id="d-ua">—</td></tr>
      <tr><td class="key">language</td><td class="val" id="d-lang">—</td></tr>
      <tr><td class="key">platform</td><td class="val" id="d-platform">—</td></tr>
      <tr><td class="key">screen</td><td class="val" id="d-screen">—</td></tr>
      <tr><td class="key">color depth</td><td class="val" id="d-color">—</td></tr>
      <tr><td class="key">cookies</td><td class="val" id="d-cookies">—</td></tr>
      <tr><td class="key">touch points</td><td class="val" id="d-touch">—</td></tr>
    </table>
  </div>
  <div class="section-card">
    <div class="card-header"><span>◈</span> TIME / STATUS</div>
    <table class="data-table">
      <tr><td class="key">local time</td><td class="val g" id="d-localtime">—</td></tr>
      <tr><td class="key">utc time</td><td class="val" id="d-utctime">—</td></tr>
      <tr><td class="key">utc offset</td><td class="val" id="d-utcoffset">—</td></tr>
      <tr><td class="key">server tz</td><td class="val"><?= $esc($geo['timezone'] ?? '—') ?></td></tr>
      <tr><td class="key">online</td><td class="val g" id="d-online">—</td></tr>
      <tr><td class="key">do not track</td><td class="val" id="d-dnt">—</td></tr>
      <tr><td class="key">connection</td><td class="val" id="d-conn">—</td></tr>
    </table>
  </div>
</div>

<!-- Mini Map -->
<div class="mini-map-card">
  <div class="card-header">
    <span><span style="margin-right:.4rem">◈</span> STANDORT</span>
    <?php if ($geo['latitude']): ?>
    <a href="/map/">Vollbild →</a>
    <?php endif; ?>
  </div>
  <?php if ($geo['latitude'] && $geo['longitude']): ?>
  <div id="mini-map"></div>
  <?php else: ?>
  <div class="mini-map-no-geo">Kein Geo-Standort verfügbar für diese IP.</div>
  <?php endif; ?>
</div>

<div class="terminal-section">
  <div class="term-header"><span>◈</span> API &amp; USAGE</div>
  <div class="term-tabs">
    <button class="tab-btn active" onclick="tab('curl',this)">curl</button>
    <button class="tab-btn" onclick="tab('endpoints',this)">endpoints</button>
    <button class="tab-btn" onclick="tab('formats',this)">formats</button>
  </div>
  <div class="tab-content active" id="tab-curl">
    <div class="tl"><span class="tp">$</span><span class="tc">curl <span style="color:var(--cyan)"><?= $esc($hostname) ?></span></span><span class="tm"># plain IP</span></div>
    <div class="to"><?= $esc($geo['ip']) ?></div>
    <div class="tl" style="margin-top:.5rem"><span class="tp">$</span><span class="tc">curl <span style="color:var(--cyan)"><?= $esc($hostname) ?>/json</span></span><span class="tm"># full JSON</span></div>
    <div class="to" style="color:var(--text-dim);font-size:.7rem;white-space:pre-wrap;line-height:1.6"><?= htmlspecialchars($json, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="tl" style="margin-top:.5rem"><span class="tp">$</span><span class="tc">curl <span style="color:var(--cyan)"><?= $esc($hostname) ?>/country</span></span><span class="tm"># country only</span></div>
    <div class="to"><?= $esc($geo['country_name'] ?? '—') ?></div>
  </div>
  <div class="tab-content" id="tab-endpoints">
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/</span><span class="ep-d">plain IP (curl) or HTML page (browser)</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/json</span><span class="ep-d">full JSON response</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/country</span><span class="ep-d">country name</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/country-iso</span><span class="ep-d">ISO 3166 country code</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/city</span><span class="ep-d">city name</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/region</span><span class="ep-d">region / state</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/asn</span><span class="ep-d">ASN number</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/org</span><span class="ep-d">organization / ISP</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/timezone</span><span class="ep-d">timezone string</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/ping</span><span class="ep-d">latency check — returns "pong" + X-Response-Time header</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/port/443</span><span class="ep-d">check if port is reachable from server to your IP</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/map/</span><span class="ep-d">interactive map mit IP-Standort</span></div>
    <div class="ep"><span class="ep-m">GET</span><span class="ep-p">/{ip}/json</span><span class="ep-d">look up a different IP</span></div>
  </div>
  <div class="tab-content" id="tab-formats">
    <div class="tl"><span class="tp">$</span><span class="tc">curl -H <span style="color:var(--amber)">"Accept: application/json"</span> <?= $esc($hostname) ?></span></div>
    <div class="tl" style="margin-top:.4rem"><span class="tp">$</span><span class="tc">curl -6 <?= $esc($hostname) ?></span><span class="tm"># force IPv6</span></div>
    <div class="tl" style="margin-top:.4rem"><span class="tp">$</span><span class="tc">curl -4 <?= $esc($hostname) ?></span><span class="tm"># force IPv4</span></div>
    <div class="tl" style="margin-top:.4rem"><span class="tp">$</span><span class="tc">curl <?= $esc($hostname) ?>/8.8.8.8/json</span><span class="tm"># lookup any IP</span></div>
    <div style="margin-top:1rem;font-size:.7rem;color:var(--text-dim);border-top:1px solid var(--border);padding-top:.75rem">
      <span style="color:var(--orange)">rate limit:</span> 60 req/min &nbsp;|&nbsp; <span style="color:var(--orange)">429</span> = rate limited &nbsp;|&nbsp; no auth required
    </div>
  </div>
</div>

<div class="privacy-box">
  <div class="privacy-box-title">Datenschutz &amp; Privatsphäre</div>
  <div class="privacy-grid">
    <div class="pi"><div class="pi-t">Was wird gespeichert?</div><div class="pi-b">Anonymisierte Zugriffsstatistiken in einer eigenen Datenbank. Keine Weitergabe an Dritte. Kein Fingerprinting, keine Werbe-Cookies.</div></div>
    <div class="pi"><div class="pi-t">Externe Dienste</div><div class="pi-b">Geo-Lookup via <a href="https://ipapi.co/privacy/" target="_blank">ipapi.co</a>. Fonts via Google Fonts. Hosting via eigenem Server / Hoster deiner Wahl.</div></div>
    <div class="pi"><div class="pi-t">Open Source</div><div class="pi-b">Vollständiger Quellcode auf <a href="<?= $esc($github) ?>" target="_blank">GitHub</a>. Jede Zeile prüfbar. MIT Lizenz.</div></div>
    <div class="pi"><div class="pi-t">Deine Rechte</div><div class="pi-b">Vollständige Datenschutzerklärung: <a href="/datenschutz/">Datenschutzerklärung</a>. Fragen via <a href="<?= $esc($github) ?>/issues" target="_blank">GitHub Issues</a>.</div></div>
  </div>
</div>

<div class="faq">
  <div class="faq-title">FAQ</div>
  <div class="faq-item"><div class="faq-q" onclick="this.closest('.faq-item').classList.toggle('open')">Wie erzwinge ich IPv4 oder IPv6?<span class="faq-arr">▶</span></div><div class="faq-a"><code>curl -4 <?= $esc($hostname) ?></code> für IPv4, <code>curl -6 <?= $esc($hostname) ?></code> für IPv6.</div></div>
  <div class="faq-item"><div class="faq-q" onclick="this.closest('.faq-item').classList.toggle('open')">Wie bekomme ich JSON?<span class="faq-arr">▶</span></div><div class="faq-a"><code>curl <?= $esc($hostname) ?>/json</code> — oder Header <code>Accept: application/json</code> setzen.</div></div>
  <div class="faq-item"><div class="faq-q" onclick="this.closest('.faq-item').classList.toggle('open')">Wie schlage ich eine fremde IP nach?<span class="faq-arr">▶</span></div><div class="faq-a"><code>curl <?= $esc($hostname) ?>/8.8.8.8/json</code></div></div>
  <div class="faq-item"><div class="faq-q" onclick="this.closest('.faq-item').classList.toggle('open')">Werden meine Daten gespeichert?<span class="faq-arr">▶</span></div><div class="faq-a">Nur anonyme Zugriffsstatistiken (Zeitstempel, Land, Endpunkt). Keine persönliche Zuordnung möglich. Mehr: <a href="/datenschutz/" style="color:var(--green)">Datenschutzerklärung</a>.</div></div>
</div>

<footer>
  <div style="margin-bottom:.4rem">
    <a href="/"><?= $esc($hostname) ?></a> &nbsp;·&nbsp;
    <a href="/stats/">stats</a> &nbsp;·&nbsp;
    <a href="/datenschutz/">datenschutz</a> &nbsp;·&nbsp;
    <a href="<?= $esc($github) ?>" target="_blank">github</a>
  </div>
  <div>data: maxmind geolite2 via ipapi.co &nbsp;·&nbsp; php + mariadb &nbsp;·&nbsp; MIT license</div>
</footer>
</div>

<script>

// ── Threat / Reputation check ─────────────────────────────────────────────
async function loadThreat(){
  const table = document.getElementById('threat-table');
  if(!table) return;
  try {
    const res = await fetch('/json');
    const d   = await res.json();

    const flags = [
      d.is_tor        ? '🧅 Tor exit node'   : null,
      d.is_vpn        ? '🔒 VPN provider'     : null,
      d.is_proxy      ? '↔ Open proxy'        : null,
      d.is_datacenter ? '🏢 Datacenter / Hosting' : null,
    ].filter(Boolean);

    const clean = !d.is_tor && !d.is_vpn && !d.is_proxy && !d.is_datacenter;

    table.innerHTML = `
      <tr>
        <td class="key">tor exit</td>
        <td class="val ${d.is_tor ? 'o' : ''}">${d.is_tor ? '✓ ja' : 'nein'}</td>
      </tr>
      <tr>
        <td class="key">vpn</td>
        <td class="val ${d.is_vpn ? 'o' : ''}">${d.is_vpn ? '✓ ja' : 'nein'}</td>
      </tr>
      <tr>
        <td class="key">proxy</td>
        <td class="val ${d.is_proxy ? 'o' : ''}">${d.is_proxy ? '✓ ja' : 'nein'}</td>
      </tr>
      <tr>
        <td class="key">datacenter</td>
        <td class="val ${d.is_datacenter ? 'a' : ''}">${d.is_datacenter ? '✓ ja' : 'nein'}</td>
      </tr>
      <tr>
        <td class="key">status</td>
        <td class="val ${clean ? 'g' : 'o'}">${clean ? '✓ clean' : '⚠ ' + (d.threat_type || 'flagged')}</td>
      </tr>`;
  } catch(e) {
    const table = document.getElementById('threat-table');
    if(table) table.innerHTML = '<tr><td class="key">status</td><td class="val" style="color:var(--text-faint)">unavailable</td></tr>';
  }
}


// ── Mini Map ──────────────────────────────────────────────────────────────
function initMiniMap(){
  const el = document.getElementById('mini-map');
  if(!el) return;

  const lat  = <?= json_encode($geo['latitude']  !== null ? (float)$geo['latitude']  : null) ?>;
  const lon  = <?= json_encode($geo['longitude'] !== null ? (float)$geo['longitude'] : null) ?>;
  const city = <?= json_encode($geo['city']         ?? '') ?>;
  const ip   = <?= json_encode($geo['ip']           ?? '') ?>;
  const cc   = <?= json_encode($geo['country_name'] ?? '') ?>;

  if(!lat || !lon) return;

  const map = L.map('mini-map', {
    zoomControl: true,
    scrollWheelZoom: false,
    dragging: true,
    doubleClickZoom: true,
    attributionControl: true,
  }).setView([lat, lon], 9);

  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
    subdomains: 'abcd',
    maxZoom: 20
  }).addTo(map);

  const markerHtml = '<div style="width:14px;height:14px;background:#00ff88;border:2px solid #060c06;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 0 0 2px #00ff88,0 0 10px rgba(0,255,136,.5)"></div>';
  const icon = L.divIcon({className:'', html:markerHtml, iconSize:[14,14], iconAnchor:[7,14]});

  L.marker([lat, lon], {icon})
    .addTo(map)
    .bindPopup('<strong>'+ip+'</strong><br>'+(city ? city+(cc ? ', '+cc : '')+'<br>' : '')+'<span style="color:var(--text-dim);font-size:.65rem">⚠ Schätzung, nicht exakt</span>',
               {closeButton:false, offset:[0,-8]})
    .openPopup();

  // Accuracy circle
  L.circle([lat, lon], {radius:20000, color:'#00ff88', fillColor:'#00ff88', fillOpacity:.04, weight:1, dashArray:'3 4'}).addTo(map);
}

// ── IPv6 display (fetched client-side via api6.ipify.org) ─────────────────
async function fetchIPv6(){
  try {
    const res = await fetch('https://api6.ipify.org?format=json', {cache:'no-store'});
    if (!res.ok) return;
    const d = await res.json();
    if (!d.ip || !d.ip.includes(':')) return; // must be a real IPv6
    const sub  = document.getElementById('ipv6-sub');
    const addr = document.getElementById('ipv6-addr');
    if (sub && addr) {
      addr.textContent = d.ip;
      sub.style.display = 'block';
    }
  } catch(e) { /* no IPv6 available */ }
}

// ── IP Search ─────────────────────────────────────────────────────────────
async function doSearch(){
  const input = document.getElementById('search-input');
  const btn   = document.getElementById('search-btn');
  const result= document.getElementById('search-result');
  const errEl = document.getElementById('sr-error');
  const q = input.value.trim();
  if(!q) return;

  btn.disabled = true;
  btn.textContent = '[ ... ]';
  errEl.style.display = 'none';
  result.classList.remove('visible');

  try {
    const res = await fetch('/'+encodeURIComponent(q)+'/json');
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d = await res.json();
    if(d.error) throw new Error(d.error);

    document.getElementById('sr-ip').textContent      = d.ip || '—';
    document.getElementById('sr-country').textContent = (d.country_name||'—') + (d.country_code ? ' ('+d.country_code+')' : '');
    document.getElementById('sr-city').textContent    = [d.city, d.region].filter(Boolean).join(', ') || '—';
    document.getElementById('sr-org').textContent     = d.org || d.asn || '—';
    document.getElementById('sr-tz').textContent      = d.timezone || '—';
    document.getElementById('sr-host').textContent    = d.hostname || 'N/A';
    document.getElementById('sr-eu').textContent      = d.in_eu ? 'true ✓' : 'false';
    result.classList.add('visible');
  } catch(e) {
    errEl.textContent = '✗ ' + (e.message || 'Lookup fehlgeschlagen');
    errEl.style.display = 'block';
    result.classList.add('visible');
  }

  btn.disabled = false;
  btn.textContent = '[ lookup → ]';
}

// ── Client-side browser data ──────────────────────────────────────────────
function fill(){
  const s=id=>document.getElementById(id);
  const nav=navigator;
  if(s('d-ua'))s('d-ua').textContent=nav.userAgent||'—';
  if(s('d-lang'))s('d-lang').textContent=nav.language||'—';
  if(s('d-platform'))s('d-platform').textContent=nav.platform||'—';
  if(s('d-screen'))s('d-screen').textContent=screen.width+'×'+screen.height;
  if(s('d-color'))s('d-color').textContent=screen.colorDepth+' bit';
  if(s('d-cookies'))s('d-cookies').textContent=nav.cookieEnabled?'enabled':'disabled';
  if(s('d-touch'))s('d-touch').textContent=nav.maxTouchPoints||'0';
  if(s('d-online'))s('d-online').textContent=navigator.onLine?'✓ online':'✗ offline';
  const c=nav.connection||nav.mozConnection||nav.webkitConnection;
  if(s('d-conn'))s('d-conn').textContent=c?(c.effectiveType||'—')+(c.downlink?' '+c.downlink+' Mbps':''):'—';
  if(s('d-dnt'))s('d-dnt').textContent=nav.doNotTrack==='1'?'enabled':nav.doNotTrack==='0'?'disabled':'unset';
  tick();
}
function tick(){
  const now=new Date();
  const fmt=t=>t.toLocaleString('de-DE',{dateStyle:'short',timeStyle:'medium'});
  const off=-now.getTimezoneOffset();
  const sign=off>=0?'+':'-';
  const h=String(Math.floor(Math.abs(off)/60)).padStart(2,'0');
  const m=String(Math.abs(off)%60).padStart(2,'0');
  const el=document.getElementById('d-localtime');
  if(el)el.textContent=fmt(now);
  const eu=document.getElementById('d-utctime');
  if(eu)eu.textContent=new Date(now.getTime()+now.getTimezoneOffset()*60000).toLocaleString('de-DE',{dateStyle:'short',timeStyle:'medium'});
  const eo=document.getElementById('d-utcoffset');
  if(eo)eo.textContent='UTC'+sign+h+':'+m;
}
setInterval(tick,1000);

function copyIP(ip){
  navigator.clipboard.writeText(ip).then(()=>{
    const b=document.getElementById('copy-btn');
    b.textContent='[ copied! ]';b.classList.add('copied');
    setTimeout(()=>{b.textContent='[ copy ]';b.classList.remove('copied')},2000);
  });
}
function tab(name,el){
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
  el.classList.add('active');document.getElementById('tab-'+name).classList.add('active');
}

setTimeout(()=>{
  const d=document.getElementById('ip-display');
  if(d){d.classList.add('glitch-anim');setTimeout(()=>d.classList.remove('glitch-anim'),200);}
},1000);

fill();
loadThreat();
fetchIPv6();
initMiniMap();
</script>
</body>
</html>
<?php
    exit;
}
