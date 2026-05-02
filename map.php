<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/GeoLookup.php';

DB::init($cfg['db']);
GeoLookup::init($cfg);

function clientIp(): string {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
    foreach ($keys as $k) {
        $val = $_SERVER[$k] ?? '';
        if ($val === '') continue;
        $ip = trim(explode(',', $val)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

$ip      = clientIp();
$geo     = GeoLookup::lookup($ip);
$esc     = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$lat     = $geo['latitude']  ?? 20;
$lon     = $geo['longitude'] ?? 0;
$hasGeo  = $geo['latitude'] !== null && $geo['longitude'] !== null;
$hostname = $cfg['hostname'];
$github   = $cfg['github_url'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ipwho.am — IP Map</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;700&display=swap" rel="stylesheet">
<style>
:root{--green:#00ff88;--green-dim:#00cc66;--green-dark:#007a3d;--green-faint:#003320;--orange:#ff6b35;--cyan:#00e5ff;--amber:#ffb300;--bg:#060c06;--bg2:#0a110a;--bg3:#0e180e;--border:#0f2a0f;--border2:#163a16;--text:#c8f0d4;--text-dim:#5a8a65;--text-faint:#2a4a30}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{background:var(--bg);color:var(--text);font-family:'JetBrains Mono',monospace;min-height:100vh;display:flex;flex-direction:column}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.06) 2px,rgba(0,0,0,0.06) 4px);pointer-events:none;z-index:1000}
@keyframes blink{50%{opacity:0}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.top-bar{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border2);padding:.75rem 1.5rem;flex-wrap:wrap;gap:.5rem;position:relative;z-index:10;flex-shrink:0}
.top-bar-left{display:flex;align-items:center;gap:.75rem}
.top-bar-right{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.traffic-lights{display:flex;gap:6px}.dot{width:12px;height:12px;border-radius:50%}
.dot-red{background:#ff5f57}.dot-amber{background:#febc2e}.dot-green{background:#28c840}
.site-title{color:var(--green);font-size:1rem;font-weight:700;letter-spacing:.05em}.site-title span{color:var(--orange)}
.page-sub{color:var(--cyan);font-size:.62rem;letter-spacing:.2em;padding:2px 8px;border:1px solid #0a2a35;border-radius:2px}
.nav-link{font-size:.65rem;color:var(--text-dim);border:1px solid var(--border2);padding:2px 10px;border-radius:2px;letter-spacing:.1em;text-decoration:none;transition:all .2s}
.nav-link:hover{color:var(--green);border-color:var(--green-dark)}
.info-bar{display:flex;align-items:center;gap:1.5rem;padding:.8rem 1.5rem;background:var(--bg2);border-bottom:1px solid var(--border2);flex-wrap:wrap;font-size:.75rem;position:relative;z-index:10;animation:fadeInUp .4s ease both;flex-shrink:0}
.info-ip{color:var(--green);font-size:1.1rem;font-weight:700;letter-spacing:.04em}
.info-tag{display:flex;align-items:center;gap:.35rem}
.info-tag .lbl{color:var(--text-faint);font-size:.62rem;letter-spacing:.1em;text-transform:uppercase}
.info-tag .val{color:var(--cyan)}
.info-tag .val.amber{color:var(--amber)}
.accuracy-note{margin-left:auto;font-size:.62rem;color:var(--text-faint)}
.map-wrap{position:relative;flex:1;display:flex;flex-direction:column}
#map{flex:1;min-height:400px}
.map-search{position:absolute;top:1rem;right:1rem;z-index:500;display:flex;gap:.4rem;flex-wrap:wrap}
.map-search-input{background:var(--bg2);border:1px solid var(--border2);color:var(--text);font-family:inherit;font-size:.72rem;padding:6px 12px;border-radius:2px;outline:none;width:210px;transition:border-color .2s}
.map-search-input:focus{border-color:var(--green-dark)}
.map-search-btn{background:transparent;border:1px solid var(--green-dark);color:var(--green-dim);font-family:inherit;font-size:.65rem;padding:6px 12px;cursor:pointer;letter-spacing:.08em;border-radius:2px;transition:all .2s}
.map-search-btn:hover{border-color:var(--green);color:var(--green)}
.no-geo{display:flex;align-items:center;justify-content:center;flex:1;flex-direction:column;gap:.75rem;font-size:.8rem;color:var(--text-dim);padding:4rem}
.no-geo strong{color:var(--orange)}
.leaflet-popup-content-wrapper{background:var(--bg2);color:var(--text);border:1px solid var(--border2);border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:.75rem;box-shadow:none}
.leaflet-popup-tip{background:var(--bg2)}
.leaflet-popup-content{margin:.75rem 1rem;line-height:1.8}
.leaflet-popup-content strong{color:var(--green)}
.leaflet-control-zoom a{background:var(--bg2)!important;color:var(--text)!important;border-color:var(--border2)!important}
.leaflet-control-zoom a:hover{background:var(--bg3)!important}
.leaflet-control-attribution{background:rgba(6,12,6,.8)!important;color:var(--text-faint)!important;font-size:.55rem!important}
.leaflet-control-attribution a{color:var(--text-dim)!important}
footer{text-align:center;padding:.75rem;font-size:.6rem;color:var(--text-faint);border-top:1px solid var(--border);position:relative;z-index:10;flex-shrink:0}
footer a{color:var(--text-dim);text-decoration:none}
footer a:hover{color:var(--green)}
</style>
</head>
<body>

<div class="top-bar">
  <div class="top-bar-left">
    <div class="traffic-lights"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
    <span class="site-title">ipwho<span>.am</span></span>
    <span class="page-sub">MAP</span>
  </div>
  <div class="top-bar-right">
    <a href="/" class="nav-link">[ ← main ]</a>
    <a href="/stats/" class="nav-link">[ 📊 stats ]</a>
    <a href="<?= $esc($github) ?>" target="_blank" class="nav-link">[ ⌥ github ]</a>
  </div>
</div>

<div class="info-bar">
  <div class="info-ip"><?= $esc($ip) ?></div>
  <?php if ($geo['country_name']): ?>
  <div class="info-tag">
    <span class="lbl">country</span>
    <span class="val"><?= $esc($geo['country_name']) ?><?= $geo['country_code'] ? ' ('.$esc($geo['country_code']).')' : '' ?></span>
  </div>
  <?php endif; ?>
  <?php if ($geo['city']): ?>
  <div class="info-tag">
    <span class="lbl">city</span>
    <span class="val amber"><?= $esc($geo['city']) ?><?= $geo['region'] ? ', '.$esc($geo['region']) : '' ?></span>
  </div>
  <?php endif; ?>
  <?php if ($geo['org']): ?>
  <div class="info-tag">
    <span class="lbl">org</span>
    <span class="val"><?= $esc($geo['org']) ?></span>
  </div>
  <?php endif; ?>
  <div class="accuracy-note">⚠ Geo-Koordinaten sind Schätzungen, nicht exakt</div>
</div>

<?php if ($hasGeo): ?>
<div class="map-wrap">
  <div class="map-search">
    <input class="map-search-input" id="map-search" type="text"
           placeholder="IP oder Domain suchen..."
           onkeydown="if(event.key==='Enter')mapSearch()">
    <button class="map-search-btn" onclick="mapSearch()">[ → ]</button>
  </div>
  <div id="map"></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
const lat  = <?= json_encode((float)$lat) ?>;
const lon  = <?= json_encode((float)$lon) ?>;
const ip   = <?= json_encode($ip) ?>;
const city = <?= json_encode($geo['city'] ?? '') ?>;
const org  = <?= json_encode($geo['org']  ?? '') ?>;
const cc   = <?= json_encode($geo['country_name'] ?? '') ?>;

const map = L.map('map').setView([lat, lon], 10);

// CartoDB Dark Matter tiles — natively dark, no CSS filter needed
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
  subdomains: 'abcd',
  maxZoom: 20
}).addTo(map);

// Custom pin marker
const markerHtml = `<div style="width:18px;height:18px;background:#00ff88;border:2px solid #060c06;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 0 0 2px #00ff88,0 0 14px rgba(0,255,136,.5)"></div>`;
const icon = L.divIcon({className:'', html:markerHtml, iconSize:[18,18], iconAnchor:[9,18]});

const popupContent = `<strong>${ip}</strong><br>${city ? city + (cc ? ', ' + cc : '') + '<br>' : ''}<span style="color:var(--text-dim)">${org}</span>`;

L.marker([lat, lon], {icon})
  .addTo(map)
  .bindPopup(popupContent, {closeButton:false, offset:[0,-10]})
  .openPopup();

// Accuracy circle
L.circle([lat, lon], {
  radius: 25000,
  color: '#00ff88',
  fillColor: '#00ff88',
  fillOpacity: 0.05,
  weight: 1,
  dashArray: '4 4'
}).addTo(map);

// IP search on map
async function mapSearch() {
  const q = document.getElementById('map-search').value.trim();
  if (!q) return;
  try {
    const res = await fetch('/' + encodeURIComponent(q) + '/json');
    const d   = await res.json();
    if (d.error || !d.latitude) { alert('Kein Geo-Ergebnis für: ' + q); return; }
    map.setView([d.latitude, d.longitude], 10);
    const html2 = markerHtml.replace(/#00ff88/g,'#00e5ff').replace(/#060c06/g,'#060c06');
    const icon2 = L.divIcon({className:'', html:html2, iconSize:[18,18], iconAnchor:[9,18]});
    L.marker([d.latitude, d.longitude], {icon:icon2})
      .addTo(map)
      .bindPopup(`<strong>${d.ip}</strong><br>${d.city||''}${d.country_name ? ', '+d.country_name : ''}<br><span style="color:var(--text-dim)">${d.org||''}</span>`, {closeButton:false})
      .openPopup();
  } catch(e) { alert('Fehler: ' + e.message); }
}
</script>

<?php else: ?>
<div class="no-geo">
  <strong>Kein Geo-Standort verfügbar</strong>
  <span>Für die IP <code style="background:var(--green-faint);color:var(--green);padding:1px 6px;border-radius:2px"><?= $esc($ip) ?></code> konnten keine Koordinaten ermittelt werden.</span>
</div>
<?php endif; ?>

<footer>
  <a href="/">ipwho.am</a> &nbsp;·&nbsp;
  <a href="/stats/">stats</a> &nbsp;·&nbsp;
  map: <a href="https://carto.com" target="_blank">CartoDB</a> + <a href="https://www.openstreetmap.org" target="_blank">OpenStreetMap</a> &nbsp;·&nbsp;
  geo: ipapi.co / MaxMind GeoLite2
</footer>
</body>
</html>
