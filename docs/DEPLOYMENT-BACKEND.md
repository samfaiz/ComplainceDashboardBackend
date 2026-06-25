# Deploying the backend (Laravel) — CloudPanel / Nginx + PHP-FPM

Target server path (already created):
`/home/faisalkhan-apidashboard/htdocs/apicomplaincedashboard.faisalkhan.cloud`

API domain: `https://apicomplaincedashboard.faisalkhan.cloud`
Site user: `faisalkhan-apidashboard` · Repo: **public** (clone over HTTPS, no key needed)

> The repo is a monorepo: the Laravel app lives in **`backend/`**. The web server's
> document root must point at **`backend/public`** (never the repo root — that would
> expose `.env`).

---

## 0) Prerequisites (run over SSH **as the site user**, not root)

CloudPanel runs each site's PHP-FPM as the site user, so all files must be owned by
that user. Either SSH in as `faisalkhan-apidashboard`, or:

```bash
sudo su - faisalkhan-apidashboard
cd ~/htdocs/apicomplaincedashboard.faisalkhan.cloud
```

Check the toolchain (use your site's PHP version — examples use `php8.3`):

```bash
php8.3 -v                      # PHP 8.3.x
php8.3 -m | grep -E 'openssl|pdo_mysql|mbstring|tokenizer|xml|ctype|bcmath|curl|fileinfo'
composer -V                    # if missing, see note below
git --version
```

Required PHP extensions: `openssl, pdo_mysql, mbstring, tokenizer, xml, ctype, json,
bcmath, curl, fileinfo`. Install any missing: `sudo apt install php8.3-<ext>`.

If Composer is missing:
```bash
cd ~ && php8.3 -r "copy('https://getcomposer.org/installer','composer-setup.php');"
php8.3 composer-setup.php && sudo mv composer.phar /usr/local/bin/composer
```

---

## 1) Create the MySQL database

**CloudPanel UI:** *Databases → Add Database* — note the **name, user, password**.

**Or CLI:**
```bash
sudo clpctl db:add \
  --domainName=apicomplaincedashboard.faisalkhan.cloud \
  --databaseName=apicompliance \
  --databaseUserName=apicompliance \
  --databaseUserPassword='CHANGE_ME_strong'
```

---

## 2) Get the code onto the server

The site folder may contain a default `index.html`/`index.php` — remove it, then clone
the repo **into** the folder (note the trailing `.`):

```bash
cd ~/htdocs/apicomplaincedashboard.faisalkhan.cloud
rm -f index.html index.php
git clone https://github.com/<YOUR-USERNAME>/<YOUR-REPO>.git .
```

You should now have `./backend` and `./frontend`. (If git refuses because the dir
isn't empty, run `ls -a`, remove the stray default files, and retry.)

---

## 3) Install backend dependencies

```bash
cd ~/htdocs/apicomplaincedashboard.faisalkhan.cloud/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php8.3 artisan key:generate
# generate the connector-secret key and copy its output into .env:
php8.3 -r "echo 'DATA_ENCRYPTION_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

---

## 4) Configure `.env` for production

Edit `backend/.env`:

```dotenv
APP_NAME="EDR Compliance Dashboard"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://apicomplaincedashboard.faisalkhan.cloud
APP_KEY=base64:...                 # set by key:generate

# Database (from step 1)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=apicompliance
DB_USERNAME=apicompliance
DB_PASSWORD=CHANGE_ME_strong

# Connector-secret encryption (from the command above)
DATA_ENCRYPTION_KEY=base64:...

# Password hashing
HASH_DRIVER=argon2id               # if argon2 is unavailable, set to: bcrypt
ARGON_MEMORY=65536
ARGON_THREADS=1
ARGON_TIME=4

# --- SPA / cross-subdomain cookie auth (Sanctum) ---
# Point these at the FRONTEND domain once it's deployed.
FRONTEND_URL=https://dashboard.faisalkhan.cloud
SANCTUM_STATEFUL_DOMAINS=dashboard.faisalkhan.cloud
SESSION_DOMAIN=.faisalkhan.cloud   # leading dot → cookie shared across subdomains
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
SESSION_DRIVER=database

CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_LEVEL=warning
```

Notes:
- **Argon2id:** confirm support with
  `php8.3 -r "var_dump(defined('PASSWORD_ARGON2ID'));"`. If it prints `false`, set
  `HASH_DRIVER=bcrypt` (the app supports both).
- **Cross-subdomain cookies:** with the API on `apicomplaincedashboard.faisalkhan.cloud`
  and the frontend on, say, `dashboard.faisalkhan.cloud`, both are sub-domains of
  `faisalkhan.cloud`, so `SESSION_DOMAIN=.faisalkhan.cloud` + `SESSION_SAME_SITE=lax`
  works. If your frontend ends up on a *different* root domain, use
  `SESSION_SAME_SITE=none` (requires HTTPS on both).
- `config/cors.php` already allows `FRONTEND_URL` with credentials — no edit needed.

---

## 5) Migrate the database + create an admin user

```bash
php8.3 artisan migrate --force
```

Create **one admin** (production — no demo data):
```bash
php8.3 artisan tinker --execute="\App\Models\User::create([
 'name'=>'Admin','email'=>'you@faisalkhan.cloud','role'=>'admin','is_active'=>true,
 'password'=>\Illuminate\Support\Facades\Hash::make('ChangeThisStrong!1')]);"
```

*(Alternative — to also load the 3 demo users + sample sites/data for a quick look:
`php8.3 artisan db:seed --force`. Skip this for a clean production instance.)*

---

## 6) Permissions + cache for production

```bash
chmod -R ug+rwx storage bootstrap/cache
php8.3 artisan storage:link            # optional
php8.3 artisan config:cache
php8.3 artisan route:cache
php8.3 artisan event:cache             # optional
```

If you ever ran a command as root by mistake, fix ownership:
```bash
sudo chown -R faisalkhan-apidashboard:faisalkhan-apidashboard ~/htdocs/apicomplaincedashboard.faisalkhan.cloud
```

> After **any** `.env` change, re-run: `php8.3 artisan config:clear && php8.3 artisan config:cache`.

---

## 7) Point the web root at `backend/public`

**CloudPanel UI:** open the site → **Vhost** editor. CloudPanel's PHP vhost is a
**two-block reverse proxy**: the `listen 443` block proxies to a `listen 8080` block
that actually runs PHP. You must set the root to `backend/public` in **both** places:

1. **Front block (`listen 443`)** — set the literal `root`:
   ```nginx
   root /home/faisalkhan-apidashboard/htdocs/apicomplaincedashboard.faisalkhan.cloud/backend/public;
   ```
2. **PHP block (`listen 8080`)** — replace the `{{root}}` macro with the same explicit
   path (this is the one that resolves `index.php`; missing this = **404**):
   ```nginx
   root /home/faisalkhan-apidashboard/htdocs/apicomplaincedashboard.faisalkhan.cloud/backend/public;
   ```

Leave all other `{{...}}` macros and the existing `try_files $uri $uri/ /index.php?$args;`
and `location ~ \.php$ { … }` blocks intact. **Save** in the CloudPanel editor (it
expands the macros and reloads Nginx). Do **not** hand-edit the raw `/etc/nginx`
files with `{{...}}` macros in them — nginx can't parse macros and will fail to start
(`sudo nginx -t` will report `unknown directive "{{...}}"`).

**Plain Nginx (no panel)** — minimal server block:
```nginx
server {
    listen 443 ssl http2;
    server_name apicomplaincedashboard.faisalkhan.cloud;
    root /home/faisalkhan-apidashboard/htdocs/apicomplaincedashboard.faisalkhan.cloud/backend/public;
    index index.php;

    # ssl_certificate ... ; ssl_certificate_key ... ;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # match your FPM socket
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }        # hide .env etc.
}
```
Then: `sudo nginx -t && sudo systemctl reload nginx`.

---

## 8) Enable HTTPS

**CloudPanel:** site → **SSL/TLS → Let's Encrypt → Issue**. (Required — secure cookies
need HTTPS.) For plain Nginx, use `certbot --nginx -d apicomplaincedashboard.faisalkhan.cloud`.

---

## 9) Scheduler (auto-refresh + health heartbeat)

The app refreshes due sources and writes the System-Health heartbeat via Laravel's
scheduler. Add **one cron job** running every minute.

**CloudPanel UI:** site → **Cron Jobs → Add** (select PHP 8.3), command:
```
* * * * * /usr/bin/php8.3 /home/faisalkhan-apidashboard/htdocs/apicomplaincedashboard.faisalkhan.cloud/backend/artisan schedule:run >> /dev/null 2>&1
```
(Confirm the PHP path with `which php8.3`.)

> A separate queue worker is **not required** — the refresh command runs inline. If you
> later move ingestion to queued jobs, add a Supervisor/systemd worker for
> `php artisan queue:work`.

---

## 10) Verify

```bash
# health route (no auth) should return HTTP 200:
curl -I https://apicomplaincedashboard.faisalkhan.cloud/up

# environment sanity (production, DB connected):
php8.3 artisan about

# confirm .env is NOT web-exposed — must be 404/403:
curl -I https://apicomplaincedashboard.faisalkhan.cloud/.env
```

Full login works once the **frontend** is deployed and its domain is in
`FRONTEND_URL` / `SANCTUM_STATEFUL_DOMAINS` (re-cache config after setting them).

---

## 11) Redeploy / update later

```bash
cd ~/htdocs/apicomplaincedashboard.faisalkhan.cloud
git pull origin main
cd backend
composer install --no-dev --optimize-autoloader
php8.3 artisan migrate --force
php8.3 artisan optimize          # rebuilds config+route+event cache
```
Use `php8.3 artisan optimize:clear` if you need to flush caches.

---

## Security checklist
- [ ] Document root is `backend/public` (verify `/.env` returns 404).
- [ ] `APP_DEBUG=false`, `APP_ENV=production`.
- [ ] `APP_KEY` and `DATA_ENCRYPTION_KEY` generated **on the server** (never committed; `.env` is git-ignored).
- [ ] HTTPS issued; `SESSION_SECURE_COOKIE=true`.
- [ ] DB user limited to its own database; strong password.
- [ ] Files owned by `faisalkhan-apidashboard`; `storage/` + `bootstrap/cache` writable.
- [ ] Cron `schedule:run` active (check System Health → Scheduler shows "Healthy").
