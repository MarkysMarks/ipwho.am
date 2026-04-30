<?php
$cfg = require __DIR__ . '/config.php';
$hostname = $cfg['hostname'];
$github   = $cfg['github_url'];
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ipwho.am — Datenschutzerklärung</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;700&display=swap" rel="stylesheet">
<style>
:root{--green:#00ff88;--green-dim:#00cc66;--green-dark:#007a3d;--green-faint:#003320;--orange:#ff6b35;--cyan:#00e5ff;--bg:#060c06;--bg2:#0a110a;--bg3:#0e180e;--border:#0f2a0f;--border2:#163a16;--text:#c8f0d4;--text-dim:#5a8a65;--text-faint:#2a4a30}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'JetBrains Mono',monospace;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.07) 2px,rgba(0,0,0,0.07) 4px);pointer-events:none;z-index:1000}
body::after{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at center,transparent 60%,rgba(0,0,0,.7) 100%);pointer-events:none;z-index:999}
.wrapper{max-width:760px;margin:0 auto;padding:2rem 1.5rem 5rem}
.top-bar{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border2);padding-bottom:.75rem;margin-bottom:2.5rem;flex-wrap:wrap;gap:.5rem}
.top-bar-left{display:flex;align-items:center;gap:.75rem}
.traffic-lights{display:flex;gap:6px}.dot{width:12px;height:12px;border-radius:50%}
.dot-red{background:#ff5f57}.dot-amber{background:#febc2e}.dot-green{background:#28c840}
.site-title{color:var(--green);font-size:1rem;font-weight:700;letter-spacing:.05em}.site-title span{color:var(--orange)}
.nav-link{font-size:.65rem;color:var(--text-dim);border:1px solid var(--border2);padding:2px 10px;border-radius:2px;letter-spacing:.1em;text-decoration:none;transition:all .2s}
.nav-link:hover{color:var(--green);border-color:var(--green-dark)}
h1{font-size:1.3rem;color:var(--green);margin-bottom:.4rem;letter-spacing:.04em}
.subtitle{font-size:.7rem;color:var(--text-dim);margin-bottom:2.5rem;padding-bottom:1rem;border-bottom:1px solid var(--border)}
.subtitle::before{content:'// '}
h2{font-size:.8rem;color:var(--cyan);letter-spacing:.15em;text-transform:uppercase;margin:2rem 0 .75rem}
h2::before{content:'## ';color:var(--text-faint)}
p{font-size:.78rem;color:var(--text-dim);line-height:1.9;margin-bottom:.75rem}
a{color:var(--green-dim);text-decoration:none}
a:hover{color:var(--green)}
.highlight-box{background:var(--bg2);border:1px solid var(--border2);border-left:3px solid var(--green-dark);border-radius:2px;padding:1rem 1.25rem;margin:1rem 0;font-size:.75rem;color:var(--text-dim);line-height:1.8}
.highlight-box strong{color:var(--green);display:block;margin-bottom:.3rem}
table{width:100%;border-collapse:collapse;margin:1rem 0;font-size:.72rem}
table th{color:var(--text-faint);font-weight:400;text-align:left;padding:.5rem .75rem;border-bottom:1px solid var(--border2);letter-spacing:.1em;text-transform:uppercase;font-size:.62rem}
table td{padding:.5rem .75rem;border-bottom:1px solid var(--border);color:var(--text-dim)}
table tr:hover td{background:var(--green-faint)}
table td:first-child{color:var(--text)}
code{background:var(--green-faint);color:var(--green);padding:1px 6px;border-radius:2px;font-family:inherit;font-size:.9em}
footer{text-align:center;padding-top:2rem;border-top:1px solid var(--border);font-size:.65rem;color:var(--text-faint);letter-spacing:.1em;margin-top:3rem}
footer a{color:var(--text-dim);text-decoration:none}footer a:hover{color:var(--green)}
</style>
</head>
<body>
<div class="wrapper">
<div class="top-bar">
  <div class="top-bar-left">
    <div class="traffic-lights"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
    <span class="site-title">ipwho<span>.am</span></span>
  </div>
  <a href="/" class="nav-link">[ ← zurück ]</a>
</div>

<h1>🔒 Datenschutzerklärung</h1>
<div class="subtitle">Zuletzt aktualisiert: <?= date('F Y') ?> — <?= $esc($hostname) ?></div>

<div class="highlight-box">
  <strong>TL;DR — Kurz &amp; klar</strong>
  Anonymisierte Zugriffsstatistiken werden in unserer eigenen MariaDB-Datenbank gespeichert (Zeitstempel, Land, Endpunkt, ob Browser oder CLI).
  Deine vollständige IP-Adresse wird <em>gehasht</em> für Unique-Visitor-Zählung und nach 30 Tagen gelöscht.
  Keine Werbe-Cookies, kein Fingerprinting, keine Datenweitergabe an Dritte.
</div>

<h2>1. Verantwortlicher</h2>
<p>Diese Website (<code><?= $esc($hostname) ?></code>) wird betrieben von MarkysMarks.
Kontakt über <a href="<?= $esc($github) ?>/issues" target="_blank">GitHub Issues</a>.</p>

<h2>2. Datenerhebung &amp; -verarbeitung</h2>
<table>
  <thead><tr><th>Datenkategorie</th><th>Zweck</th><th>Speicherdauer</th><th>Drittanbieter</th></tr></thead>
  <tbody>
    <tr><td>IP-Adresse (deine)</td><td>Anzeige auf deinem Bildschirm, Geo-Lookup</td><td>Nicht gespeichert</td><td>ipapi.co (kurzzeitig)</td></tr>
    <tr><td>Land / Stadt (Geo)</td><td>Anonyme Statistik</td><td>90 Tage</td><td>Keiner (eigene DB)</td></tr>
    <tr><td>Endpunkt &amp; Zeitstempel</td><td>Zugriffsstatistik</td><td>90 Tage</td><td>Keiner (eigene DB)</td></tr>
    <tr><td>User-Agent</td><td>Browser-/CLI-Erkennung für korrekte Antwort</td><td>90 Tage</td><td>Keiner (eigene DB)</td></tr>
    <tr><td>Geo-Cache (IP → Geo)</td><td>Reduktion von API-Anfragen</td><td>7 Tage</td><td>Keiner (eigene DB)</td></tr>
    <tr><td>Schriftarten</td><td>JetBrains Mono Darstellung</td><td>Nicht gespeichert</td><td>Google Fonts</td></tr>
  </tbody>
</table>

<h2>3. Externe Dienste</h2>
<p><strong style="color:var(--text)">ipapi.co</strong><br>
Zur Geo-Lokalisierung wird eine Anfrage an <a href="https://ipapi.co" target="_blank">ipapi.co</a> gestellt.
Ergebnisse werden 7 Tage in unserer eigenen DB gecacht — bei wiederholten Anfragen derselben IP wird kein externer API-Call gemacht.
Datenschutzerklärung: <a href="https://ipapi.co/privacy/" target="_blank">ipapi.co/privacy</a></p>

<p><strong style="color:var(--text)">Google Fonts</strong><br>
Schriftart JetBrains Mono via Google Fonts CDN. Deine IP wird dabei an Google-Server übertragen.
<a href="https://policies.google.com/privacy" target="_blank">Google Privacy Policy</a></p>

<p><strong style="color:var(--text)">Hosting</strong><br>
Diese Website wird auf einem eigenen Server betrieben. Der Hoster kann technische Zugriffsdaten in Server-Logs speichern.</p>

<h2>4. Cookies &amp; Tracking</h2>
<p>Diese Website setzt <strong style="color:var(--text)">keine Tracking-Cookies</strong> und keine Werbe-Cookies.
Session-Cookies werden nicht verwendet. Die Statistiken basieren ausschließlich auf serverseitigen Logs in unserer eigenen DB.</p>

<h2>5. Deine Rechte (DSGVO)</h2>
<p>Da vollständige IP-Adressen nicht dauerhaft gespeichert werden, ist eine Zuordnung zu deiner Person nicht möglich.
Für Fragen oder Löschanfragen: <a href="<?= $esc($github) ?>/issues" target="_blank">GitHub Issues</a>.</p>

<h2>6. Open Source</h2>
<p>Vollständiger Quellcode: <a href="<?= $esc($github) ?>" target="_blank"><?= $esc($github) ?></a>.
Jede Zeile Code — inkl. Datenbankschema und Tracking-Logik — ist öffentlich einsehbar. MIT Lizenz.</p>

<footer>
  <a href="/">ipwho.am</a> &nbsp;·&nbsp; <a href="/stats/">stats</a> &nbsp;·&nbsp; <a href="<?= $esc($github) ?>" target="_blank">github</a>
</footer>
</div>
</body>
</html>
