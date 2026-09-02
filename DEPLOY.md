# Deploy — Phius Order

Two independent artifacts: the Laravel API (`pos-backend`) and the Flutter web
build (`pos-frontend`). They talk over HTTPS; the browser enforces CORS, so the
API must whitelist the exact frontend origin(s).

---

## 1. Backend (Laravel API)

### Requirements
- PHP 8.3 (ext: `openssl`, `curl`, `mbstring`, `fileinfo`)
- Composer
- Redis (recommended for cache/lock/auth-token; file cache works but is slower and
  does not share the micro-cache across PHP-FPM workers)
- Google Service Account JSON with the target spreadsheet shared to its email

### Steps
```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# edit .env (see below), then:
php artisan config:cache
php artisan route:cache
```

### `.env` (prod)
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.phius.example

# One origin, or a comma-separated list (apex, www, staging). NO trailing slash.
POS_FRONTEND_ORIGIN=https://order.phius.example,https://www.order.phius.example

# Cache/lock/token — Redis in prod
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Google Sheets (absolute path recommended; a relative path breaks when the
# process cwd differs from the project root)
GOOGLE_SA_KEY_PATH=/var/www/pos-backend/storage/google/service-account.json
GOOGLE_SPREADSHEET_ID=<spreadsheet id>

# Auth: set a long random salt ONCE and never change it (changing it invalidates
# every seeded PIN hash). Must match the salt used by `pos:setup`.
POS_AUTH_SALT=<random 32+ char string>
POS_ORDER_BASE_URL=https://order.phius.example
```

> First-time DB setup: `php artisan pos:setup` seeds the 14 sheets and the initial
> staff PINs (default `zaq1234`, forced change on first login). It hashes PINs with
> `POS_AUTH_SALT`, so set the salt **before** running it.

### nginx (API) — reverse proxy to PHP-FPM
```nginx
server {
    listen 443 ssl http2;
    server_name api.phius.example;

    ssl_certificate     /etc/letsencrypt/live/api.phius.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.phius.example/privkey.pem;

    root /var/www/pos-backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```
Redirect port 80 → 443. Let CORS (not nginx) handle cross-origin — the app already
emits the right headers from `POS_FRONTEND_ORIGIN`.

### Realtime (Reverb) — optional but recommended
The app broadcasts order/call/session changes over WebSocket (Pusher protocol)
so staff/customer screens update without waiting for the poll. If you skip this,
everything still works via polling (5–10s) — realtime is purely additive.

Run the Reverb daemon under a process supervisor (systemd/supervisor):
```ini
# /etc/supervisor/conf.d/reverb.conf
[program:phius-reverb]
command=php /var/www/pos-backend/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
```
`.env` already holds `REVERB_APP_ID/KEY/SECRET` (set by `reverb:install`) and
`BROADCAST_CONNECTION=reverb`. Broadcasting is synchronous (`ShouldBroadcastNow`),
so **no queue worker is required**. Expose the WS port over TLS via nginx:
```nginx
# under the API server block, or a dedicated ws.phius.example host
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
}
```
The Flutter build must be pointed at it (see below): `REVERB_HOST`, `REVERB_PORT`,
`REVERB_SCHEME=https`.

---

## 2. Frontend (Flutter web)

The API base URL is compiled in at build time via `--dart-define`.

```bash
cd pos-frontend
flutter build web --release \
  --dart-define=POS_API_BASE_URL=https://api.phius.example \
  --dart-define=REVERB_APP_KEY=<REVERB_APP_KEY from backend .env> \
  --dart-define=REVERB_HOST=ws.phius.example \
  --dart-define=REVERB_PORT=443 \
  --dart-define=REVERB_SCHEME=https
# output: build/web
# Omit the REVERB_* defines to ship polling-only (realtime disabled at runtime).
```

### nginx (frontend) — static + SPA fallback
The router uses path URLs (`/kitchen`, `/cashier`, …), so unknown paths must fall
back to `index.html` or a deep link 404s (as seen locally with `python -m http.server`).

```nginx
server {
    listen 443 ssl http2;
    server_name order.phius.example;

    ssl_certificate     /etc/letsencrypt/live/order.phius.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/order.phius.example/privkey.pem;

    root /var/www/pos-frontend/build/web;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;   # SPA fallback
    }
    # Flutter build assets are content-hashed → safe to cache hard
    location ~* \.(js|wasm|png|jpg|svg|woff2?)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
    # never cache the shell, or clients pin an old main.dart.js
    location = /index.html { add_header Cache-Control "no-cache"; }
    location = /flutter_bootstrap.js { add_header Cache-Control "no-cache"; }
}
```

> The `no-cache` on the shell matters: a stale `main.dart.js` served from cache is
> exactly what made the menu render "0 เมนู" during local testing.

---

## 3. Post-deploy checklist
- [ ] `POS_FRONTEND_ORIGIN` matches the deployed frontend origin(s) exactly (scheme + host, no trailing slash)
- [ ] `GOOGLE_SA_KEY_PATH` is absolute and readable by the PHP-FPM user
- [ ] Spreadsheet shared to the service-account email as **Editor**
- [ ] `POS_AUTH_SALT` set before `pos:setup`; identical on every app instance
- [ ] `CACHE_STORE=redis` and Redis reachable
- [ ] `php artisan config:cache && route:cache` run after the final `.env`
- [ ] HTTPS on both origins; HTTP → HTTPS redirect
- [ ] Smoke: `curl -X POST https://api.phius.example/api/bootstrap` returns `{"ok":true,...}`
