# Deploying the frontend (Next.js 16) — CloudPanel reverse proxy

Frontend site (already cloned): `/home/faisalkhan-complaincedashboard/htdocs/complaincedashboard.faisalkhan.cloud`
Domain: `https://complaincedashboard.faisalkhan.cloud` · Site user: `faisalkhan-complaincedashboard`

> **APP DIR — pick the one that matches your clone:**
> - App files (`package.json`, `next.config.ts`, `src/`, `public/`) are **directly at the
>   site root** → `APP_DIR = /home/faisalkhan-complaincedashboard/htdocs/complaincedashboard.faisalkhan.cloud`
> - You cloned the **whole monorepo** → `APP_DIR = …/complaincedashboard.faisalkhan.cloud/frontend`
>
> Run `ls` in the site root to check, then use that `APP_DIR` everywhere below (the `cd`
> commands and the PM2 `cwd`). The examples show the `/frontend` form — **drop `/frontend`
> if your app is at the root.**

> Unlike Laravel (static files via PHP-FPM), Next.js **runs a Node server** (`next start`).
> nginx reverse-proxies the domain to that Node process (default port **3000**). So the
> flow is: **build → run with a process manager (PM2) → nginx proxies `/` → 127.0.0.1:3000**.

> **Easiest path:** if your CloudPanel version has **Create Site → Node.js**, use it — it
> wires up the reverse proxy + a systemd service for you (set App Port `3000`, Node 20,
> App Root `.../frontend`). The steps below are the manual equivalent that works on any
> CloudPanel.

---

## 0) Install Node.js 20 LTS + PM2 (as root, once per server)

Next 16 needs Node ≥ 18.18 (use 20 LTS):
```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
node -v        # v20.x
sudo npm install -g pm2
```

---

## 1) Set the build-time API URL

`NEXT_PUBLIC_*` variables are **baked into the build**, so this must exist *before*
`npm run build`. As the **site user**, create `frontend/.env.local`:

```bash
sudo su - faisalkhan-complaincedashboard
cd ~/htdocs/complaincedashboard.faisalkhan.cloud/frontend

cat > .env.local <<'EOF'
NEXT_PUBLIC_API_URL=https://apicomplaincedashboard.faisalkhan.cloud
NEXT_PUBLIC_APP_NAME=EDR Compliance Dashboard
EOF
```

---

## 2) Install dependencies & build

```bash
cd ~/htdocs/complaincedashboard.faisalkhan.cloud/frontend
npm ci                       # clean install from package-lock (use 'npm install' if no lock)
npm run build                # produces .next/
```
If the build runs out of memory on a small VPS:
```bash
NODE_OPTIONS=--max-old-space-size=2048 npm run build
```

---

## 3) Run the Node server with PM2 (port 3000)

Create `frontend/ecosystem.config.cjs`:
```js
module.exports = {
  apps: [{
    name: 'cd-frontend',
    cwd: '/home/faisalkhan-complaincedashboard/htdocs/complaincedashboard.faisalkhan.cloud/frontend',
    script: 'node_modules/next/dist/bin/next',
    args: 'start -p 3000',
    env: { NODE_ENV: 'production', PORT: '3000' },
    instances: 1,
    autorestart: true,
    max_memory_restart: '512M',
  }],
};
```

Start it (still as the **site user**):
```bash
pm2 start ecosystem.config.cjs
pm2 save
pm2 status                   # cd-frontend should be "online"
curl -I http://127.0.0.1:3000   # 200/307 from Next locally
```

Make PM2 survive reboots — it prints a `sudo env … pm2 startup systemd …` command; run that **as root**:
```bash
pm2 startup systemd -u faisalkhan-complaincedashboard --hp /home/faisalkhan-complaincedashboard
# copy/paste the command it outputs, run as root, then (as site user) 'pm2 save' again
```

---

## 4) Point nginx at the Node process (CloudPanel Vhost)

Open the **frontend** site → **Vhost** in CloudPanel. In the `listen 443` block:

1. Replace the body of `location / { … }` so it proxies to Node:
   ```nginx
   location / {
       proxy_pass http://127.0.0.1:3000;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_set_header Host $host;
       proxy_set_header X-Real-IP $remote_addr;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
       proxy_cache_bypass $http_upgrade;
       proxy_read_timeout 720s;
   }
   ```
2. **Delete the static-asset block** — this is critical. CloudPanel's template has:
   ```nginx
   location ~* ^.+\.(css|js|jpg|...|mjs)$ { ... expires max; ... }
   ```
   If you leave it, nginx tries to serve Next's `/_next/static/*.js` from disk and 404s,
   breaking the app. Remove that whole block so those requests flow through `location /`
   to Node.

You can also delete the `listen 8080` PHP server block (unused for Node). Leave the SSL
lines and the `location ~ /.well-known` block. **Save** (CloudPanel reloads nginx), then:
```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 5) Issue HTTPS

CloudPanel → frontend site → **SSL/TLS → Let's Encrypt → Issue**. Required: the API uses
secure cookies, so the frontend must be HTTPS too.

---

## 6) Confirm the backend allows this origin

On the **backend** (`apicomplaincedashboard…`) `.env`, these must point at the frontend
(then re-cache):
```dotenv
FRONTEND_URL=https://complaincedashboard.faisalkhan.cloud
SANCTUM_STATEFUL_DOMAINS=complaincedashboard.faisalkhan.cloud
SESSION_DOMAIN=.faisalkhan.cloud
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```
```bash
# on the backend server:
php8.3 artisan config:cache
```
Because both subdomains share `faisalkhan.cloud`, `SESSION_DOMAIN=.faisalkhan.cloud` +
SameSite=Lax lets the auth/XSRF cookies flow between them. CORS already allows
`FRONTEND_URL` with credentials.

---

## 7) Verify

```bash
curl -I https://complaincedashboard.faisalkhan.cloud           # 200/307 from Next (not nginx 404)
```
Then in a browser: open the site → log in. In DevTools → Network, the calls to
`apicomplaincedashboard.faisalkhan.cloud` should be **200** and you should see the
`XSRF-TOKEN` + session cookies set for `.faisalkhan.cloud`.

---

## 8) Redeploy after code changes

```bash
sudo su - faisalkhan-complaincedashboard
cd ~/htdocs/complaincedashboard.faisalkhan.cloud
git pull origin main
cd frontend
npm ci
npm run build
pm2 reload cd-frontend          # zero-downtime restart
```

---

## Troubleshooting

- **502 Bad Gateway** → Node isn't running. `pm2 status`, `pm2 logs cd-frontend`. Make sure it's on port 3000 and the proxy_pass matches.
- **Site loads but assets 404 / unstyled** → you didn't remove the static-asset `location ~* \.(css|js…)` block (step 4.2).
- **Login fails / CORS or cookie errors** → check backend `.env` (step 6), re-run `config:cache`, ensure both domains are HTTPS, and that `NEXT_PUBLIC_API_URL` was set **before** `npm run build` (rebuild if you changed it).
- **`pm2: command not found` for the site user** → install PM2 globally as root (`npm i -g pm2`); the binary is shared, each user runs its own daemon.
- **Build OOM** → `NODE_OPTIONS=--max-old-space-size=2048 npm run build`.
