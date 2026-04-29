# ipwho.am 🖥️

> **"What is my IP address?"** — A self-hostable, nerdy, terminal-aesthetic IP info page.  
> Inspired by [ifconfig.co](https://ifconfig.co), built with pure HTML/CSS/JS. No dependencies, no build step.

![Screenshot](https://img.shields.io/badge/style-terminal%20cyberpunk-00ff88?style=flat-square&labelColor=060c06)
![License](https://img.shields.io/badge/license-MIT-00cc66?style=flat-square&labelColor=060c06)
![No deps](https://img.shields.io/badge/dependencies-none-ff6b35?style=flat-square&labelColor=060c06)

![ipwho.am screenshot](screenshot.png)

---

## ✨ Features

- **Live IP detection** via [ipapi.co](https://ipapi.co) (free, no key needed)
- **Geo data** — Country, Region, City, Postal Code, Coordinates, Timezone
- **Network info** — ASN, ISP/Org, Hostname, IPv4/IPv6 detection
- **Browser fingerprint** — User-Agent, Language, Screen resolution, Touch points, DNT
- **Live clock** — Local time + UTC with offset, updates every second
- **Copy to clipboard** — One click to copy your IP
- **API reference** — curl examples + endpoint docs, tab-based UI
- **FAQ** — Collapsible, German-localized
- **Pure HTML** — Single file, zero build tools, zero npm, zero frameworks
- **CRT aesthetics** — Phosphor green on near-black, scanlines, vignette, glitch effect

---

## 🚀 Quick Start

### Option A — Open locally (simplest)

```bash
# Just download and open — no server needed
curl -O https://raw.githubusercontent.com/MarkysMarks/ipwho.am/main/index.html
open index.html          # macOS
xdg-open index.html      # Linux
start index.html         # Windows
```

> ⚠️ Geo/IP data requires an internet connection (API call to ipapi.co).  
> Browser data (UA, screen, etc.) works fully offline.

---

### Option B — Serve locally with Python

```bash
git clone https://github.com/MarkysMarks/ipwho.am.git
cd ipwho.am
python3 -m http.server 8080
# → open http://localhost:8080
```

---

### Option C — Deploy on GitHub Pages (free hosting)

1. Fork or push this repo to your GitHub account
2. Go to **Settings → Pages**
3. Under **Source**, select `main` branch, root folder `/`
4. Click **Save**
5. Your site is live at `https://YOUR_USERNAME.github.io/ipwho.am/`

---

### Option D — Deploy with Docker

```dockerfile
FROM nginx:alpine
COPY index.html /usr/share/nginx/html/index.html
EXPOSE 80
```

```bash
docker build -t ipwho.am .
docker run -p 8080:80 ipwho.am
# → open http://localhost:8080
```

---

### Option E — Deploy on Netlify / Vercel (one click)

Drag and drop `index.html` into [Netlify Drop](https://app.netlify.com/drop) — done.  
Or connect this GitHub repo directly in the Netlify/Vercel dashboard.

---

## 🏗️ Self-Hosting with your own backend

The frontend calls `https://ipapi.co/json/` to resolve IP data.  
To run fully self-contained (no third-party API), replace the fetch call in `index.html`:

```js
// In index.html, find:
const res = await fetch('https://ipapi.co/json/');

// Replace with your own backend endpoint:
const res = await fetch('/api/ip');
```

---

## 📁 Project Structure

```
ipwho.am/
├── index.html     # Everything — HTML, CSS, JS in one file
├── screenshot.png # Preview image
└── README.md      # This file
```

No `node_modules`. No `package.json`. No webpack. Just one HTML file.

---

## 🔌 API Data Source

By default this project uses the free tier of [ipapi.co](https://ipapi.co):

| Field | Endpoint |
|---|---|
| IP, Country, City, Region | `ipapi.co/json/` |
| ASN, ISP/Org | `ipapi.co/json/` |
| Latitude / Longitude | `ipapi.co/json/` |
| Timezone | `ipapi.co/json/` |

Free tier allows **30,000 requests/month** — enough for personal use.  
For higher traffic, swap in any alternative: `ip-api.com`, `ipinfo.io`, `abstractapi.com`.

---

## 🎨 Design

**Style:** Terminal Cyberpunk — phosphor green on near-black, CRT scanlines, ASCII art, monospace typography throughout.

**Fonts:** [JetBrains Mono](https://fonts.google.com/specimen/JetBrains+Mono) (Google Fonts CDN, graceful fallback to system monospace)

**Colors:**

| Role | Value |
|---|---|
| Background | `#060c06` |
| Primary green | `#00ff88` |
| Accent cyan | `#00e5ff` |
| Accent orange | `#ff6b35` |
| Text dim | `#5a8a65` |

---

## 🛠️ Customization

All styles are CSS variables at the top of `index.html`:

```css
:root {
  --green: #00ff88;   /* primary accent */
  --cyan:  #00e5ff;   /* secondary accent */
  --orange: #ff6b35;  /* highlight */
  --bg:    #060c06;   /* background */
}
```

---

## 📄 License

MIT — do whatever you want, attribution appreciated but not required.

---

## 🙏 Credits

- IP geolocation: [ipapi.co](https://ipapi.co) / [MaxMind GeoLite2](https://www.maxmind.com)
- Inspired by: [ifconfig.co](https://ifconfig.co) by [@mpolden](https://github.com/mpolden)
- Font: [JetBrains Mono](https://www.jetbrains.com/lp/mono/) by JetBrains
