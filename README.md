# ipwho.am 🖥️

> **"What is my IP?"** — Self-hostable IP info service with PHP backend + MariaDB.  
> `curl ipwho.am` returns your IP. Browser gets a terminal-aesthetic dashboard. All tracked in a real database.

![PHP](https://img.shields.io/badge/PHP-8.1+-777bb4?style=flat-square&labelColor=060c06)
![MariaDB](https://img.shields.io/badge/MariaDB-10.6+-003545?style=flat-square&labelColor=060c06&color=00cc66)
![License](https://img.shields.io/badge/license-MIT-00cc66?style=flat-square&labelColor=060c06)

![ipwho.am screenshot](screenshot.png)

---

## ✨ Features

- **`curl ipwho.am`** → plain IP · **browser** → full HTML dashboard · **`/json`** → JSON
- **`/ping`** → latency check, returns `pong` + `X-Response-Time` header
- **`/map/`** → interactive map (Leaflet + OpenStreetMap) with IP location pin
- **IP lookup** → search any IP directly in the hero section, inline result via AJAX
- **All API endpoints** — `/country`, `/city`, `/asn`, `/timezone`, `/{ip}/json` etc.
- **Real database** — every request tracked in MariaDB (country, endpoint, UA, timestamp)
- **IP hashing** — IPs stored as HMAC-SHA256, never in plaintext
- **Geo caching** — ipapi.co results cached 7 days in DB → no repeated external calls
- **Live stats dashboard** — `/stats/` with Chart.js, real data from DB, refreshes every 60s
- **Smart UA detection** — curl/wget/HTTPie get plain text, browsers get HTML
- **Animated SVG favicon** — hex network node with pulsing nodes
- **DSGVO-konform** — full Datenschutzerklärung, IP hashing, no ad tracking, open source

---

## 🖥️ Install (Apache + PHP)

**Requirements:** PHP 8.1+, Apache with `mod_rewrite`, MariaDB/MySQL 10.6+

```bash
# 1. Clone
git clone https://github.com/MarkysMarks/ipwho.am.git /var/www/ipwhoam

# 2. Create database
mysql -u root -p << 'SQL'
CREATE DATABASE ipwhoam CHARACTER SET utf8mb4;
CREATE USER 'ipwhoam'@'localhost' IDENTIFIED BY 'yourpassword';
GRANT ALL ON ipwhoam.* TO 'ipwhoam'@'localhost';
SQL

# 3. Import schema
mysql -u ipwhoam -p ipwhoam < /var/www/ipwhoam/schema.sql

# 4. Configure
cp /var/www/ipwhoam/config.example.php /var/www/ipwhoam/config.php
nano /var/www/ipwhoam/config.php
# → set db.host, db.user, db.pass, hostname, ip_salt

# 5. Generate a secure ip_salt
openssl rand -hex 32

# 6. Apache vhost
cat > /etc/apache2/sites-available/ipwhoam.conf << 'CONF'
<VirtualHost *:80>
    ServerName ipwho.am
    DocumentRoot /var/www/ipwhoam
    <Directory /var/www/ipwhoam>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
CONF

a2ensite ipwhoam
a2enmod rewrite
systemctl reload apache2
```

---

## 🔌 API Endpoints

All endpoints support `Accept: application/json` and work with curl/wget/HTTPie.

| Method | Endpoint | Response |
|--------|----------|----------|
| GET | `/` | Plain IP (CLI) or HTML page (browser) |
| GET | `/json` | Full JSON with all fields |
| GET | `/country` | Country name |
| GET | `/country-iso` | ISO 3166 country code |
| GET | `/city` | City name |
| GET | `/region` | Region / state |
| GET | `/asn` | ASN number |
| GET | `/org` | Organization / ISP |
| GET | `/timezone` | Timezone string |
| GET | `/hostname` | Reverse DNS hostname |
| GET | `/ping` | Returns `pong` + `X-Response-Time` header |
| GET | `/map/` | Interactive map with IP location |
| GET | `/{ip}/json` | Look up any IP address |
| GET | `/api/stats` | Raw stats JSON (used by dashboard) |

```bash
curl ipwho.am                      # your IP
curl ipwho.am/json                 # full JSON
curl ipwho.am/country              # "Germany"
curl ipwho.am/ping                 # pong
curl ipwho.am/8.8.8.8/json        # look up any IP
curl -H "Accept: application/json" ipwho.am
```

---

## 📁 Project Structure

```
ipwho.am/
├── index.php            # Main router — all API + HTML logic
├── stats.php            # Live traffic dashboard
├── map.php              # Interactive IP map (Leaflet)
├── datenschutz.php      # GDPR / Datenschutzerklärung
├── config.php           # Your config — gitignored, never committed
├── config.example.php   # Template — copy to config.php
├── schema.sql           # MariaDB schema
├── migrate_hash_ips.php # One-time migration: hash existing plaintext IPs
├── favicon.svg          # Animated hex network favicon
├── lib/
│   ├── DB.php           # PDO wrapper
│   ├── GeoLookup.php    # IP geo lookup with 7-day DB cache
│   └── Tracker.php      # Request logger → DB (hashes IP before storing)
└── .htaccess            # Apache URL rewriting + clean URLs
```

---

## 🗄️ Database Schema

Three tables in `schema.sql`:

- **`visits`** — every request: hashed IP (HMAC-SHA256), country, endpoint, UA, timestamp, response_ms
- **`geo_cache`** — ipapi.co results cached by IP (7 days TTL, then auto-deleted)
- **`daily_stats`** — daily counters (web / api / cli hits)

---

## 🔒 Privacy & IP Hashing

IPs are **hashed with HMAC-SHA256** before being stored in `visits` — the raw address is never written to the database and cannot be recovered without the `ip_salt` from your `config.php`.

The `geo_cache` table temporarily stores the real IP as a cache key (max 7 days TTL) to avoid repeated external API calls. Full details in `/datenschutz/`.

---

## 🎨 Design

Terminal Cyberpunk — phosphor green on near-black, CRT scanlines, ASCII art header, animated SVG favicon, JetBrains Mono throughout.

---

## 📄 License

MIT — use freely, attribution appreciated.

## 🙏 Credits

- Inspired by [ifconfig.co](https://ifconfig.co) by [@mpolden](https://github.com/mpolden)
- Geo data: [ipapi.co](https://ipapi.co) / MaxMind GeoLite2
- Map: [Leaflet](https://leafletjs.com) + [OpenStreetMap](https://www.openstreetmap.org)
- Font: [JetBrains Mono](https://www.jetbrains.com/lp/mono/)
