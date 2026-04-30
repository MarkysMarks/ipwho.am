<?php
// ── ipwho.am — Stats Dashboard ─────────────────────────────────────────────
declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    $cfg   = array_replace_recursive($cfg, $local);
}

require __DIR__ . '/lib/DB.php';
DB::init($cfg['db']);

$dbOk = DB::ping() === true;
$hostname = $cfg['hostname'];
$github   = $cfg['github_url'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ipwho.am — Traffic Stats</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--green:#00ff88;--green-dim:#00cc66;--green-dark:#007a3d;--green-faint:#003320;--orange:#ff6b35;--cyan:#00e5ff;--amber:#ffb300;--purple:#c084fc;--bg:#060c06;--bg2:#0a110a;--bg3:#0e180e;--border:#0f2a0f;--border2:#163a16;--text:#c8f0d4;--text-dim:#5a8a65;--text-faint:#2a4a30}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'JetBrains Mono',monospace;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.07) 2px,rgba(0,0,0,0.07) 4px);pointer-events:none;z-index:1000}
body::after{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at center,transparent 55%,rgba(0,0,0,.75) 100%);pointer-events:none;z-index:999}
@keyframes fadeInUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
@keyframes blink{50%{opacity:0}}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(0,255,136,.25)}50%{box-shadow:0 0 0 8px rgba(0,255,136,0)}}
.wrapper{max-width:1100px;margin:0 auto;padding:2rem 1.5rem 5rem}
.top-bar{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border2);padding-bottom:.75rem;margin-bottom:2.5rem;animation:fadeInUp .5s ease both;flex-wrap:wrap;gap:.5rem}
.top-bar-left{display:flex;align-items:center;gap:.75rem}
.top-bar-right{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.traffic-lights{display:flex;gap:6px}.dot{width:12px;height:12px;border-radius:50%}
.dot-red{background:#ff5f57}.dot-amber{background:#febc2e}.dot-green{background:#28c840;animation:pulse 2s infinite}
.site-title{color:var(--green);font-size:1rem;font-weight:700;letter-spacing:.05em}.site-title span{color:var(--orange)}
.page-subtitle{color:var(--cyan);font-size:.65rem;letter-spacing:.2em;padding:2px 8px;border:1px solid #0a2a35;border-radius:2px}
.nav-link{font-size:.65rem;color:var(--text-dim);border:1px solid var(--border2);padding:2px 10px;border-radius:2px;letter-spacing:.1em;text-decoration:none;transition:all .2s}
.nav-link:hover{color:var(--green);border-color:var(--green-dark)}
.live-badge{font-size:.65rem;color:#28c840;border:1px solid #0d3a1a;padding:2px 10px;border-radius:2px;letter-spacing:.1em}
.live-badge::before{content:'● ';animation:blink 1s step-start infinite}
.page-header{margin-bottom:2rem;animation:fadeInUp .5s ease .1s both}
.page-header h1{font-size:1.5rem;color:var(--green);letter-spacing:.04em;font-weight:700;margin-bottom:.3rem}
.page-header h1 span{color:var(--text-dim);font-size:.9rem;font-weight:400}
.page-header p{font-size:.75rem;color:var(--text-dim);line-height:1.7;max-width:620px}
.page-header p strong{color:var(--cyan)}
.advert-banner{background:linear-gradient(135deg,#0a1a0a,#0d200d);border:1px solid var(--green-dark);border-left:3px solid var(--green);border-radius:4px;padding:1.2rem 1.5rem;margin-bottom:2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;animation:fadeInUp .5s ease .15s both}
.advert-text{font-size:.75rem;color:var(--text-dim);line-height:1.6}
.advert-text strong{color:var(--green);display:block;margin-bottom:.2rem;font-size:.8rem}
.advert-cta{background:var(--green-dark);color:var(--green);border:1px solid var(--green);font-family:inherit;font-size:.7rem;padding:8px 20px;cursor:pointer;letter-spacing:.1em;border-radius:2px;text-decoration:none;white-space:nowrap;transition:all .2s}
.advert-cta:hover{background:var(--green);color:var(--bg)}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem}
@media(max-width:700px){.kpi-grid{grid-template-columns:1fr 1fr}}
.kpi-card{background:var(--bg2);border:1px solid var(--border2);border-radius:4px;padding:1.2rem 1.4rem;position:relative;overflow:hidden;animation:fadeInUp .6s ease both}
.kpi-card:nth-child(1){animation-delay:.2s}.kpi-card:nth-child(2){animation-delay:.25s}.kpi-card:nth-child(3){animation-delay:.3s}.kpi-card:nth-child(4){animation-delay:.35s}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.kpi-card.green::before{background:linear-gradient(90deg,transparent,var(--green),transparent)}
.kpi-card.cyan::before{background:linear-gradient(90deg,transparent,var(--cyan),transparent)}
.kpi-card.orange::before{background:linear-gradient(90deg,transparent,var(--orange),transparent)}
.kpi-card.amber::before{background:linear-gradient(90deg,transparent,var(--amber),transparent)}
.kpi-label{font-size:.6rem;color:var(--text-dim);letter-spacing:.2em;text-transform:uppercase;margin-bottom:.6rem}
.kpi-value{font-size:2rem;font-weight:700;line-height:1;margin-bottom:.3rem}
.kpi-card.green .kpi-value{color:var(--green)}.kpi-card.cyan .kpi-value{color:var(--cyan)}.kpi-card.orange .kpi-value{color:var(--orange)}.kpi-card.amber .kpi-value{color:var(--amber)}
.kpi-sub{font-size:.65rem;color:var(--text-dim)}
.charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-bottom:2rem}
@media(max-width:800px){.charts-grid{grid-template-columns:1fr}}
.chart-card{background:var(--bg2);border:1px solid var(--border);border-radius:4px;overflow:hidden;animation:fadeInUp .6s ease both}
.chart-card:nth-child(1){animation-delay:.4s}.chart-card:nth-child(2){animation-delay:.45s}
.chart-header{background:var(--bg3);border-bottom:1px solid var(--border);padding:.6rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem}
.chart-title{font-size:.65rem;color:var(--text-dim);letter-spacing:.15em;text-transform:uppercase;display:flex;align-items:center;gap:.5rem}
.chart-legend{display:flex;gap:1rem;flex-wrap:wrap}
.legend-item{display:flex;align-items:center;gap:.35rem;font-size:.6rem;color:var(--text-dim)}
.legend-dot{width:8px;height:8px;border-radius:50%}
.chart-body{padding:1.2rem}
.charts-row2{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:2rem}
@media(max-width:800px){.charts-row2{grid-template-columns:1fr}}
.chart-card-sm{background:var(--bg2);border:1px solid var(--border);border-radius:4px;overflow:hidden;animation:fadeInUp .6s ease both}
.chart-card-sm:nth-child(1){animation-delay:.5s}.chart-card-sm:nth-child(2){animation-delay:.55s}.chart-card-sm:nth-child(3){animation-delay:.6s}
.geo-section{background:var(--bg2);border:1px solid var(--border);border-radius:4px;margin-bottom:2rem;overflow:hidden;animation:fadeInUp .6s ease .65s both}
.geo-header{background:var(--bg3);border-bottom:1px solid var(--border);padding:.6rem 1rem;font-size:.65rem;color:var(--text-dim);letter-spacing:.15em;text-transform:uppercase}
.geo-table{width:100%;border-collapse:collapse}
.geo-table tr{border-bottom:1px solid var(--border);transition:background .15s}
.geo-table tr:last-child{border-bottom:none}.geo-table tr:hover{background:var(--green-faint)}
.geo-table td,.geo-table th{padding:.5rem 1rem;font-size:.73rem}
.geo-table th{color:var(--text-faint);font-weight:400;letter-spacing:.1em;text-transform:uppercase;font-size:.6rem;border-bottom:1px solid var(--border2)}
.geo-table .bar-col{width:35%}
.geo-bar-track{height:4px;background:var(--border2);border-radius:2px;overflow:hidden}
.geo-bar-fill{height:100%;border-radius:2px;background:var(--green);transition:width 1.5s cubic-bezier(.22,1,.36,1)}
.geo-table .num-col{color:var(--cyan);text-align:right}.geo-table .pct-col{color:var(--text-dim);text-align:right}
.empty-state{padding:3rem;text-align:center;color:var(--text-faint);font-size:.75rem}
.empty-state strong{color:var(--text-dim);display:block;margin-bottom:.5rem}
footer{text-align:center;padding-top:2rem;border-top:1px solid var(--border);font-size:.65rem;color:var(--text-faint);letter-spacing:.1em;animation:fadeInUp .6s ease .8s both}
footer a{color:var(--text-dim);text-decoration:none;transition:color .2s}
footer a:hover{color:var(--green)}
.db-error{background:#1a0808;border:1px solid #4a1010;border-left:3px solid #ff4444;border-radius:4px;padding:1rem 1.25rem;margin-bottom:2rem;font-size:.75rem;color:#ff9999}
.db-error strong{color:#ff4444;display:block;margin-bottom:.3rem}
</style>
</head>
<body>
<div class="wrapper">

<div class="top-bar">
  <div class="top-bar-left">
    <div class="traffic-lights"><div class="dot dot-red"></div><div class="dot dot-amber"></div><div class="dot dot-green"></div></div>
    <span class="site-title">ipwho<span>.am</span></span>
    <span class="page-subtitle">TRAFFIC STATS</span>
  </div>
  <div class="top-bar-right">
    <span class="live-badge">LIVE</span>
    <a href="/" class="nav-link">[ ← main ]</a>
    <a href="<?= htmlspecialchars($github) ?>" target="_blank" class="nav-link">[ ⌥ github ]</a>
  </div>
</div>

<div class="page-header">
  <h1>Traffic Dashboard <span>/ public analytics</span></h1>
  <p>Öffentliche Echtzeit-Statistiken für <strong><?= htmlspecialchars($hostname) ?></strong> — 
  Seitenaufrufe, API-Nutzung und geografische Verteilung. Daten direkt aus der MariaDB-Datenbank.</p>
</div>

<?php if (!$dbOk): ?>
<div class="db-error">
  <strong>⚠ Datenbankverbindung fehlgeschlagen</strong>
  Statistiken können momentan nicht geladen werden. Bitte prüfe die DB-Konfiguration.
</div>
<?php endif; ?>

<div class="advert-banner">
  <div class="advert-text">
    <strong>🎯 Werben auf <?= htmlspecialchars($hostname) ?></strong>
    Technikaffine Zielgruppe: Entwickler, DevOps, Netzwerker weltweit.
    Täglich wachsende Reichweite — keine Streuverluste, 100% Nerd-Audience.
  </div>
  <a href="<?= htmlspecialchars($github) ?>/issues" target="_blank" class="advert-cta">[ Kontakt aufnehmen ]</a>
</div>

<!-- KPI CARDS -->
<div class="kpi-grid">
  <div class="kpi-card green">
    <div class="kpi-label">Gesamt Besuche</div>
    <div class="kpi-value" id="kpi-total">…</div>
    <div class="kpi-sub">seit Launch</div>
  </div>
  <div class="kpi-card cyan">
    <div class="kpi-label">Heute</div>
    <div class="kpi-value" id="kpi-today">…</div>
    <div class="kpi-sub">Seitenaufrufe</div>
  </div>
  <div class="kpi-card orange">
    <div class="kpi-label">API / CLI Hits</div>
    <div class="kpi-value" id="kpi-api">…</div>
    <div class="kpi-sub">curl &amp; fetch</div>
  </div>
  <div class="kpi-card amber">
    <div class="kpi-label">Unique IPs</div>
    <div class="kpi-value" id="kpi-unique">…</div>
    <div class="kpi-sub">gesamt</div>
  </div>
</div>

<!-- MAIN CHART + DONUT -->
<div class="charts-grid">
  <div class="chart-card">
    <div class="chart-header">
      <div class="chart-title">◈ Aufrufe — letzten 30 Tage</div>
      <div class="chart-legend">
        <div class="legend-item"><div class="legend-dot" style="background:var(--green)"></div>Web</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--cyan)"></div>API</div>
        <div class="legend-item"><div class="legend-dot" style="background:var(--orange)"></div>CLI</div>
      </div>
    </div>
    <div class="chart-body"><canvas id="chart-main"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-header"><div class="chart-title">◈ Traffic-Quelle</div></div>
    <div class="chart-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center">
      <canvas id="chart-donut" style="max-height:180px;max-width:180px"></canvas>
      <div id="donut-legend" style="margin-top:1rem;font-size:.65rem;color:var(--text-dim);text-align:center;line-height:2"></div>
    </div>
  </div>
</div>

<!-- BOTTOM ROW -->
<div class="charts-row2">
  <div class="chart-card-sm">
    <div class="chart-header"><div class="chart-title">◈ Stunden (30 Tage)</div></div>
    <div class="chart-body"><canvas id="chart-hourly"></canvas></div>
  </div>
  <div class="chart-card-sm">
    <div class="chart-header"><div class="chart-title">◈ Wochentage</div></div>
    <div class="chart-body"><canvas id="chart-weekday"></canvas></div>
  </div>
  <div class="chart-card-sm">
    <div class="chart-header"><div class="chart-title">◈ Top Endpunkte</div></div>
    <div class="chart-body"><canvas id="chart-endpoints"></canvas></div>
  </div>
</div>

<!-- GEO TABLE -->
<div class="geo-section">
  <div class="geo-header">◈ Top Länder — Besucher-Herkunft</div>
  <table class="geo-table">
    <thead><tr><th>#</th><th>Land</th><th class="bar-col">Anteil</th><th class="num-col">Besuche</th><th class="pct-col">%</th></tr></thead>
    <tbody id="geo-tbody"><tr><td colspan="5" class="empty-state">Lade Daten…</td></tr></tbody>
  </table>
</div>

<footer>
  <div style="margin-bottom:.4rem">
    <a href="/"><?= htmlspecialchars($hostname) ?></a> &nbsp;·&nbsp;
    <a href="/datenschutz/">datenschutz</a> &nbsp;·&nbsp;
    <a href="<?= htmlspecialchars($github) ?>" target="_blank">github</a>
  </div>
  <div>live-daten aus mariadb &nbsp;·&nbsp; refresh alle 60s</div>
</footer>
</div>

<script>
Chart.defaults.color='#5a8a65';
Chart.defaults.borderColor='#0f2a0f';
Chart.defaults.font.family="'JetBrains Mono',monospace";
Chart.defaults.font.size=10;

const tip={backgroundColor:'#0e180e',borderColor:'#163a16',borderWidth:1,titleColor:'#00ff88',bodyColor:'#c8f0d4',padding:10};

let charts={};

function fmt(n){if(n==null)return'—';n=parseInt(n);return n>=1000?(n/1000).toFixed(1)+'k':String(n)}

function animCount(id,target){
  const el=document.getElementById(id);if(!el)return;
  const t=parseInt(target)||0;const dur=1000;const start=performance.now();
  function step(now){const p=Math.min((now-start)/dur,1);const e=1-Math.pow(1-p,3);el.textContent=fmt(Math.round(e*t));if(p<1)requestAnimationFrame(step);}
  requestAnimationFrame(step);
}

async function loadStats(){
  try{
    const r=await fetch('/api/stats?days=30');
    const d=await r.json();
    if(!d.ok)throw new Error(d.error);

    // KPIs
    animCount('kpi-total',d.totals?.total||0);
    animCount('kpi-today',d.today?.total||0);
    animCount('kpi-api',(parseInt(d.totals?.api||0)+parseInt(d.totals?.cli||0)));
    animCount('kpi-unique',d.totals?.unique_ips||0);

    // Main line chart
    const days=d.daily||[];
    const labels=days.map(r=>r.day.slice(5)); // MM-DD
    const webData=days.map(r=>parseInt(r.web)||0);
    const apiData=days.map(r=>parseInt(r.api)||0);
    const cliData=days.map(r=>parseInt(r.cli)||0);

    if(charts.main)charts.main.destroy();
    charts.main=new Chart(document.getElementById('chart-main'),{
      type:'line',
      data:{labels,datasets:[
        {label:'Web',data:webData,borderColor:'#00ff88',backgroundColor:'rgba(0,255,136,.08)',borderWidth:2,pointRadius:0,pointHoverRadius:4,tension:.4,fill:true},
        {label:'API',data:apiData,borderColor:'#00e5ff',backgroundColor:'rgba(0,229,255,.05)',borderWidth:1.5,pointRadius:0,tension:.4,fill:true,borderDash:[4,3]},
        {label:'CLI',data:cliData,borderColor:'#ff6b35',backgroundColor:'rgba(255,107,53,.05)',borderWidth:1.5,pointRadius:0,tension:.4,fill:true,borderDash:[2,4]},
      ]},
      options:{responsive:true,maintainAspectRatio:true,
        plugins:{legend:{display:false},tooltip:{...tip,callbacks:{label:ctx=>` ${ctx.dataset.label}: ${ctx.parsed.y}`}}},
        scales:{x:{grid:{color:'#0f2a0f'},ticks:{maxRotation:0,maxTicksLimit:8}},y:{grid:{color:'#0f2a0f'},beginAtZero:true}}}
    });

    // Donut
    const tot=d.totals||{};
    const web=parseInt(tot.web)||0;const api=parseInt(tot.api)||0;const cli=parseInt(tot.cli)||0;const total=web+api+cli||1;
    const sourceData=[web,api,cli];
    const sourceLabels=['Browser / Web','API (JSON)','CLI / curl'];
    const sourceColors=['#00ff88','#00e5ff','#ff6b35'];
    if(charts.donut)charts.donut.destroy();
    charts.donut=new Chart(document.getElementById('chart-donut'),{
      type:'doughnut',
      data:{labels:sourceLabels,datasets:[{data:sourceData,backgroundColor:sourceColors,borderColor:'#060c06',borderWidth:3,hoverOffset:6}]},
      options:{responsive:true,cutout:'65%',plugins:{legend:{display:false},tooltip:{...tip}}}
    });
    const dl=document.getElementById('donut-legend');
    dl.innerHTML=sourceLabels.map((l,i)=>
      `<span style="color:${sourceColors[i]}">■</span> ${l}: ${Math.round(sourceData[i]/total*100)}%`
    ).join('<br>');

    // Hourly
    const hArr=Array(24).fill(0);
    (d.hourly||[]).forEach(r=>{hArr[parseInt(r.hour)]=parseInt(r.hits)||0});
    if(charts.hourly)charts.hourly.destroy();
    charts.hourly=new Chart(document.getElementById('chart-hourly'),{
      type:'bar',
      data:{labels:hArr.map((_,i)=>i+':00').filter((_,i)=>i%3===0),
            datasets:[{data:hArr.filter((_,i)=>i%3===0),backgroundColor:'rgba(0,255,136,.4)',borderColor:'#00ff88',borderWidth:1,borderRadius:2}]},
      options:{responsive:true,plugins:{legend:{display:false},tooltip:{...tip,callbacks:{label:ctx=>` ${ctx.parsed.y} hits`}}},
               scales:{x:{grid:{color:'#0f2a0f'},ticks:{maxRotation:0}},y:{grid:{color:'#0f2a0f'},beginAtZero:true}}}
    });

    // Weekday  (DAYOFWEEK: 1=Sun ... 7=Sat)
    const dowLabels=['So','Mo','Di','Mi','Do','Fr','Sa'];
    const dowData=Array(7).fill(0);
    (d.weekdays||[]).forEach(r=>{dowData[(parseInt(r.dow)-1)%7]=parseInt(r.hits)||0});
    if(charts.weekday)charts.weekday.destroy();
    charts.weekday=new Chart(document.getElementById('chart-weekday'),{
      type:'bar',
      data:{labels:dowLabels,datasets:[{data:dowData,backgroundColor:dowData.map(v=>v===Math.max(...dowData)?'rgba(0,255,136,.7)':'rgba(0,255,136,.3)'),borderColor:'#00ff88',borderWidth:1,borderRadius:2}]},
      options:{responsive:true,plugins:{legend:{display:false},tooltip:{...tip,callbacks:{label:ctx=>` ${ctx.parsed.y} hits`}}},
               scales:{x:{grid:{color:'#0f2a0f'}},y:{grid:{color:'#0f2a0f'},beginAtZero:true}}}
    });

    // Endpoints
    const epData=d.endpoints||[];
    if(charts.ep)charts.ep.destroy();
    charts.ep=new Chart(document.getElementById('chart-endpoints'),{
      type:'bar',
      data:{labels:epData.map(r=>r.endpoint),
            datasets:[{data:epData.map(r=>parseInt(r.hits)),backgroundColor:'rgba(192,132,252,.4)',borderColor:'#c084fc',borderWidth:1,borderRadius:2}]},
      options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false},tooltip:{...tip,callbacks:{label:ctx=>` ${ctx.parsed.x} hits`}}},
               scales:{x:{grid:{color:'#0f2a0f'},beginAtZero:true},y:{grid:{color:'#0f2a0f'}}}}
    });

    // Geo table
    const countries=d.countries||[];
    const tbody=document.getElementById('geo-tbody');
    if(countries.length===0){
      tbody.innerHTML='<tr><td colspan="5" class="empty-state"><strong>Noch keine Daten</strong>Besuche werden hier angezeigt sobald Requests eingehen.</td></tr>';
    }else{
      const maxH=countries[0]?.hits||1;
      tbody.innerHTML=countries.map((row,i)=>`
        <tr>
          <td style="color:var(--text-faint)">${i+1}</td>
          <td>${row.country_name||row.country_code||'—'}</td>
          <td class="bar-col"><div class="geo-bar-track"><div class="geo-bar-fill" style="width:0%" data-w="${Math.round(row.hits/maxH*100)}%"></div></div></td>
          <td class="num-col">${parseInt(row.hits).toLocaleString()}</td>
          <td class="pct-col">${row.pct}%</td>
        </tr>`).join('');
      setTimeout(()=>{document.querySelectorAll('.geo-bar-fill').forEach(el=>{el.style.width=el.dataset.w})},200);
    }

  }catch(e){
    console.error('Stats load failed:',e);
    document.getElementById('kpi-total').textContent='ERR';
  }
}

loadStats();
setInterval(loadStats,60000); // refresh every 60s
</script>
</body>
</html>
