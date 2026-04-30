<?php
// ── ipwho.am — ASN Lookup Page ─────────────────────────────────────────────
declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/lib/DB.php';
DB::init($cfg['db']);

$esc      = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$hostname = $cfg['hostname'];
$github   = $cfg['github_url'];

// Parse ASN from URL: /asn/AS1234 or /asn/1234
$rawPath  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$parts    = explode('/', trim($rawPath, '/'));
$asnInput = strtoupper($parts[1] ?? '');
$asnNum   = preg_replace('/^AS/', '', $asnInput);

$isJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
       || str_ends_with($rawPath, '.json');

// Validate
if (!ctype_digit($asnNum) || (int)$asnNum < 1 || (int)$asnNum > 4294967295) {
    if ($isJson) { header('Content-Type: application/json'); echo json_encode(['error'=>'Invalid ASN']); exit; }
    $asnNum = null;
}

$data = null;
$error = null;

if ($asnNum) {
    // Try DB cache (24h)
    $cached = DB::one(
        "SELECT data FROM asn_cache WHERE asn = ? AND expires_at > NOW()",
        [(int)$asnNum]
    );

    if ($cached) {
        $data = json_decode($cached['data'], true);
    } else {
        // Fetch from bgpview.io
        $data = fetchASN((int)$asnNum);
        if ($data) {
            $expires = date('Y-m-d H:i:s', time() + 86400);
            DB::q(
                "INSERT INTO asn_cache (asn, data, expires_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)",
                [(int)$asnNum, json_encode($data), $expires]
            );
        } else {
            $error = "AS{$asnNum} konnte nicht gefunden werden.";
        }
    }
}

function fetchASN(int $asn): ?array
{
    $ctx = stream_context_create(['http'=>['timeout'=>5,'user_agent'=>'ipwho.am/1.0','ignore_errors'=>true]]);

    // Basic ASN info
    $raw = @file_get_contents("https://api.bgpview.io/asn/{$asn}", false, $ctx);
    if (!$raw) return null;
    $d = json_decode($raw, true);
    if (($d['status'] ?? '') !== 'ok') return null;
    $info = $d['data'] ?? [];

    // Prefixes
    $raw2 = @file_get_contents("https://api.bgpview.io/asn/{$asn}/prefixes", false, $ctx);
    $prefixes = ['ipv4'=>[],'ipv6'=>[]];
    if ($raw2) {
        $p = json_decode($raw2, true);
        if (($p['status'] ?? '') === 'ok') {
            $prefixes['ipv4'] = array_slice($p['data']['ipv4_prefixes'] ?? [], 0, 50);
            $prefixes['ipv6'] = array_slice($p['data']['ipv6_prefixes'] ?? [], 0, 20);
        }
    }

    return [
        'asn'         => $info['asn']            ?? $asn,
        'name'        => $info['name']            ?? null,
        'description' => $info['description_short'] ?? $info['description_full'][0] ?? null,
        'country'     => $info['country_code']    ?? null,
        'website'     => $info['website']         ?? null,
        'email'       => $info['abuse_contacts']['email'] ?? null,
        'type'        => $info['type']            ?? null,
        'prefixes_v4' => $prefixes['ipv4'],
        'prefixes_v6' => $prefixes['ipv6'],
        'prefix_count_v4' => count($prefixes['ipv4']),
        'prefix_count_v6' => count($prefixes['ipv6']),
        'fetched_at'  => date('Y-m-d H:i:s'),
    ];
}

if ($isJson && $data) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $asnNum ? "AS{$esc($asnNum)} — ipwho.am" : "ASN Lookup — ipwho.am" ?></title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;700&display=swap" rel="stylesheet">
<style>
:root{--green:#00ff88;--green-dim:#00cc66;--green-dark:#007a3d;--green-faint:#003320;--orange:#ff6b35;--cyan:#00e5ff;--amber:#ffb300;--bg:#060c06;--bg2:#0a110a;--bg3:#0e180e;--border:#0f2a0f;--border2:#163a16;--text:#c8f0d4;--text-dim:#5a8a65;--text-faint:#2a4a30}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'JetBrains Mono',monospace;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.07) 2px,rgba(0,0,0,0.07) 4px);pointer-events:none;z-index:1000}
body::after{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at center,transparent 60%,rgba(0,0,0,.7) 100%);pointer-events:none;z-index:999}
@keyframes fadeInUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.wrapper{max-width:960px;margin:0 auto;padding:2rem 1.5rem 5rem}
.top-bar{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border2);padding-bottom:.75rem;margin-bottom:2.5rem;flex-wrap:wrap;gap:.5rem;animation:fadeInUp .4s ease both}
.top-bar-left{display:flex;align-items:center;gap:.75rem}
.top-bar-right{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.dots{display:flex;gap:6px}.dot{width:12px;height:12px;border-radius:50%}
.dot-red{background:#ff5f57}.dot-amber{background:#febc2e}.dot-green{background:#28c840}
.site-title{color:var(--green);font-size:1rem;font-weight:700;letter-spacing:.05em}.site-title span{color:var(--orange)}
.page-sub{color:var(--cyan);font-size:.62rem;letter-spacing:.2em;padding:2px 8px;border:1px solid #0a2a35;border-radius:2px}
.nav-link{font-size:.65rem;color:var(--text-dim);border:1px solid var(--border2);padding:2px 10px;border-radius:2px;letter-spacing:.1em;text-decoration:none;transition:all .2s}
.nav-link:hover{color:var(--green);border-color:var(--green-dark)}

/* Search box */
.search-hero{background:var(--bg2);border:1px solid var(--border2);border-top:2px solid var(--green-dark);border-radius:4px;padding:1.75rem 2rem;margin-bottom:2rem;animation:fadeInUp .5s ease .1s both;position:relative;overflow:hidden}
.search-hero::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--green),var(--cyan),var(--green),transparent);animation:scan 4s linear infinite;opacity:.5}
@keyframes scan{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}
.search-label{font-size:.62rem;color:var(--text-dim);letter-spacing:.18em;text-transform:uppercase;margin-bottom:.6rem}
.search-label::before{content:'$ ';color:var(--green)}
.search-row{display:flex;gap:.5rem;flex-wrap:wrap}
.search-input{background:var(--bg3);border:1px solid var(--border2);color:var(--text);font-family:inherit;font-size:1rem;font-weight:700;padding:10px 16px;border-radius:2px;flex:1;min-width:200px;outline:none;letter-spacing:.04em;transition:border-color .2s}
.search-input::placeholder{color:var(--text-faint);font-weight:400;font-size:.85rem}
.search-input:focus{border-color:var(--green-dark)}
.search-btn{background:transparent;border:1px solid var(--green-dark);color:var(--green-dim);font-family:inherit;font-size:.75rem;padding:10px 20px;cursor:pointer;letter-spacing:.1em;border-radius:2px;transition:all .2s;white-space:nowrap}
.search-btn:hover{border-color:var(--green);color:var(--green);box-shadow:0 0 8px rgba(0,255,136,.15)}

/* ASN header */
.asn-header{background:var(--bg2);border:1px solid var(--border2);border-radius:4px;padding:1.5rem 2rem;margin-bottom:1.5rem;animation:fadeInUp .5s ease .15s both}
.asn-number{font-size:2rem;font-weight:700;color:var(--orange);letter-spacing:.04em;margin-bottom:.3rem}
.asn-name{font-size:1rem;color:var(--green);margin-bottom:.75rem}
.asn-meta{display:flex;gap:1.5rem;flex-wrap:wrap;font-size:.75rem}
.asn-meta-item .k{color:var(--text-faint);font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;margin-bottom:.15rem}
.asn-meta-item .v{color:var(--cyan)}
.asn-type-badge{display:inline-block;font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;padding:2px 8px;border-radius:2px;border:1px solid;margin-left:.75rem;vertical-align:middle}
.asn-type-badge.isp{color:var(--green);border-color:var(--green-dark)}
.asn-type-badge.hosting{color:var(--cyan);border-color:#0a2a35}
.asn-type-badge.enterprise{color:var(--amber);border-color:#3a2a00}
.asn-type-badge.other{color:var(--text-dim);border-color:var(--border2)}

/* Grid */
.grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem}
@media(max-width:640px){.grid{grid-template-columns:1fr}}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:4px;overflow:hidden;animation:fadeInUp .5s ease both}
.card:nth-child(1){animation-delay:.2s}.card:nth-child(2){animation-delay:.25s}
.card-hdr{background:var(--bg3);border-bottom:1px solid var(--border);padding:.55rem 1rem;font-size:.62rem;color:var(--text-dim);letter-spacing:.18em;text-transform:uppercase;display:flex;align-items:center;justify-content:space-between}
.card-hdr .count{color:var(--text-faint)}

/* Prefix list */
.prefix-list{max-height:320px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border2) transparent}
.prefix-row{display:flex;align-items:center;justify-content:space-between;padding:.45rem 1rem;border-bottom:1px solid var(--border);font-size:.72rem;transition:background .1s;gap:.75rem}
.prefix-row:last-child{border-bottom:none}
.prefix-row:hover{background:var(--green-faint)}
.prefix-cidr{color:var(--green);font-weight:500;flex-shrink:0}
.prefix-name{color:var(--text-dim);font-size:.65rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* Empty / error states */
.empty{padding:2rem;text-align:center;color:var(--text-faint);font-size:.75rem}
.error-box{background:#1a0808;border:1px solid #4a1010;border-left:3px solid #ff4444;border-radius:4px;padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:.78rem;color:#ff9999;animation:fadeInUp .4s ease both}

footer{text-align:center;padding-top:2rem;border-top:1px solid var(--border);font-size:.65rem;color:var(--text-faint);letter-spacing:.1em;margin-top:2rem}
footer a{color:var(--text-dim);text-decoration:none}
footer a:hover{color:var(--green)}
</style>
</head>
<body>
<div class="wrapper">

<div class="top-bar">
  <div class="top-bar-left">
    <div class="dots"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
    <span class="site-title">ipwho<span>.am</span></span>
    <span class="page-sub">ASN LOOKUP</span>
  </div>
  <div class="top-bar-right">
    <a href="/" class="nav-link">[ ← main ]</a>
    <a href="/stats/" class="nav-link">[ 📊 stats ]</a>
    <a href="<?= $esc($github) ?>" target="_blank" class="nav-link">[ ⌥ github ]</a>
  </div>
</div>

<!-- Search -->
<div class="search-hero">
  <div class="search-label">asn lookup</div>
  <div class="search-row">
    <input class="search-input" id="asn-input" type="text"
           value="<?= $asnNum ? 'AS'.$esc($asnNum) : '' ?>"
           placeholder="AS15169, AS13335, AS3320 ..."
           onkeydown="if(event.key==='Enter')goLookup()">
    <button class="search-btn" onclick="goLookup()">[ lookup → ]</button>
  </div>
</div>

<?php if ($error): ?>
<div class="error-box">✗ <?= $esc($error) ?></div>
<?php endif; ?>

<?php if ($data): ?>
<?php
  $typeClass = match(strtolower($data['type'] ?? '')) {
      'isp','transit'  => 'isp',
      'hosting','cloud' => 'hosting',
      'enterprise'     => 'enterprise',
      default          => 'other'
  };
?>

<div class="asn-header">
  <div class="asn-number">
    AS<?= $esc($data['asn']) ?>
    <?php if ($data['type']): ?>
    <span class="asn-type-badge <?= $esc($typeClass) ?>"><?= $esc($data['type']) ?></span>
    <?php endif; ?>
  </div>
  <div class="asn-name"><?= $esc($data['name']) ?></div>
  <div class="asn-meta">
    <?php if ($data['description']): ?>
    <div class="asn-meta-item"><div class="k">description</div><div class="v"><?= $esc($data['description']) ?></div></div>
    <?php endif; ?>
    <?php if ($data['country']): ?>
    <div class="asn-meta-item"><div class="k">country</div><div class="v"><?= $esc($data['country']) ?></div></div>
    <?php endif; ?>
    <?php if ($data['website']): ?>
    <div class="asn-meta-item"><div class="k">website</div><div class="v"><a href="<?= $esc($data['website']) ?>" target="_blank" style="color:var(--cyan);text-decoration:none"><?= $esc($data['website']) ?></a></div></div>
    <?php endif; ?>
    <div class="asn-meta-item"><div class="k">prefixes</div><div class="v"><?= $data['prefix_count_v4'] ?> IPv4 · <?= $data['prefix_count_v6'] ?> IPv6</div></div>
    <?php if ($data['email']): ?>
    <div class="asn-meta-item"><div class="k">abuse</div><div class="v"><?= $esc($data['email']) ?></div></div>
    <?php endif; ?>
  </div>
</div>

<div class="grid">
  <!-- IPv4 Prefixes -->
  <div class="card">
    <div class="card-hdr">
      <span>◈ IPv4 Prefixes</span>
      <span class="count"><?= $data['prefix_count_v4'] ?> total<?= $data['prefix_count_v4'] > 50 ? ' (top 50)' : '' ?></span>
    </div>
    <div class="prefix-list">
      <?php if (empty($data['prefixes_v4'])): ?>
      <div class="empty">Keine IPv4-Präfixe gefunden</div>
      <?php else: ?>
      <?php foreach ($data['prefixes_v4'] as $p): ?>
      <div class="prefix-row">
        <span class="prefix-cidr"><?= $esc($p['prefix']) ?></span>
        <span class="prefix-name"><?= $esc($p['name'] ?? $p['description'] ?? '') ?></span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- IPv6 Prefixes -->
  <div class="card">
    <div class="card-hdr">
      <span>◈ IPv6 Prefixes</span>
      <span class="count"><?= $data['prefix_count_v6'] ?> total<?= $data['prefix_count_v6'] > 20 ? ' (top 20)' : '' ?></span>
    </div>
    <div class="prefix-list">
      <?php if (empty($data['prefixes_v6'])): ?>
      <div class="empty">Keine IPv6-Präfixe gefunden</div>
      <?php else: ?>
      <?php foreach ($data['prefixes_v6'] as $p): ?>
      <div class="prefix-row">
        <span class="prefix-cidr"><?= $esc($p['prefix']) ?></span>
        <span class="prefix-name"><?= $esc($p['name'] ?? $p['description'] ?? '') ?></span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($data['asn']): ?>
<div style="font-size:.65rem;color:var(--text-faint);text-align:right;margin-top:-.5rem;margin-bottom:1.5rem">
  Daten via <a href="https://bgpview.io/asn/<?= $esc($data['asn']) ?>" target="_blank" style="color:var(--text-dim);text-decoration:none">bgpview.io</a>
  · gecacht <?= $esc($data['fetched_at']) ?>
</div>
<?php endif; ?>

<?php elseif (!$asnNum && !$error): ?>
<!-- Landing state -->
<div style="background:var(--bg2);border:1px solid var(--border);border-radius:4px;padding:2rem;text-align:center;animation:fadeInUp .5s ease .2s both">
  <div style="font-size:.75rem;color:var(--text-dim);line-height:2;margin-bottom:1rem">
    Gib eine AS-Nummer ein um Informationen, IPv4/IPv6-Präfixe und mehr zu sehen.<br>
    Auch direkt per API abrufbar:
  </div>
  <div style="display:flex;flex-direction:column;gap:.3rem;font-size:.75rem">
    <div><span style="color:var(--green)">$</span> curl <?= $esc($hostname) ?>/asn/AS15169</div>
    <div><span style="color:var(--green)">$</span> curl <?= $esc($hostname) ?>/asn/AS15169/json</div>
    <div><span style="color:var(--green)">$</span> curl -H <span style="color:var(--amber)">"Accept: application/json"</span> <?= $esc($hostname) ?>/asn/AS13335</div>
  </div>
</div>
<?php endif; ?>

<footer>
  <a href="/">ipwho.am</a> &nbsp;·&nbsp;
  <a href="/stats/">stats</a> &nbsp;·&nbsp;
  <a href="<?= $esc($github) ?>" target="_blank">github</a> &nbsp;·&nbsp;
  ASN-Daten: <a href="https://bgpview.io" target="_blank">bgpview.io</a>
</footer>
</div>
<script>
function goLookup(){
  const v = document.getElementById('asn-input').value.trim().toUpperCase().replace(/^AS/,'');
  if(!v || !/^\d+$/.test(v)) return;
  window.location.href = '/asn/AS'+v+'/';
}
</script>
</body>
</html>
