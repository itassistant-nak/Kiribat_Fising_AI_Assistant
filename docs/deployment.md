# Deployment Guide (Day 4)

Demo topology (local): Laravel `:8000` + Next.js `:3000` + Flutter web `:8080`,
started with `bash scripts/start.sh`, checked with `bash scripts/status.sh`,
stopped with `bash scripts/stop.sh`. Production notes below assume a single
Ubuntu 22.04+ host; everything runs behind HTTPS in production.

## 1. Laravel API (production)

Requirements: PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `sqlite3`
(for local demo), Composer 2, a web server (nginx + php-fpm recommended —
`php artisan serve` is dev-only).

```bash
cd /srv/kiribati-ai-fisheries
git pull                    # or fresh clone, branch master
cd backend
composer install --no-dev --optimize-autoloader
cp .env.production.example .env   # then fill every CHANGE_ME
php artisan key:generate
php artisan migrate --force       # add --seed ONLY for demo data
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache database
```

Serve `backend/public/` via nginx → php-fpm. Point a supervisor/systemd unit at
`php artisan serve` ONLY for staging demos, never production.

## 2. MySQL (production database)

Local demo and CI run SQLite. MySQL 8 is the production target. The schema was
statically reviewed for MySQL compatibility (native `json` columns, single
`dropColumn` calls, `->after()` modifiers — all valid on MySQL 8; no raw
SQLite-only SQL). A live MySQL migration run is still required on staging
(Day 5) — it could not run in the build sandbox (unix-socket creation blocked).

Secure setup on the DB host:

```bash
sudo mysql_secure_installation
sudo mysql -e "CREATE DATABASE kiribati_fisheries CHARACTER SET utf8mb4;"
sudo mysql -e "CREATE USER 'kiribati_app'@'localhost' IDENTIFIED BY '<32+ random chars>';"
sudo mysql -e "GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES ON kiribati_fisheries.* TO 'kiribati_app'@'localhost'; FLUSH PRIVILEGES;"
```

Keep `bind-address = 127.0.0.1` unless the app tier is separate (then use TLS
+ firewall allow-list, never `bind-address = 0.0.0.0` open). Mirror the values
into `.env` (`DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`), then `php artisan migrate --force`.

Backups: `mysqldump kiribati_fisheries | gzip > backup-$(date +%F).sql.gz`
(SQLite demo: copy `database/database.sqlite` while the server is stopped).

## 3. Next.js admin dashboard

```bash
cd frontend
npm ci
npm run build
npm start -- -p 3000        # or: standalone output behind nginx/pm2
```

API resolution (`src/lib/api.ts`): `NEXT_PUBLIC_API_URL` wins when set;
otherwise the browser uses same-origin `/api`, rewritten to
`http://127.0.0.1:8000/api` (`next.config.ts`). Deployment options:

- **Same host (recommended for demo):** keep defaults; proxy `/` (3000) and
  `/api` (8000) under one HTTPS vhost.
- **Split hosts:** set `NEXT_PUBLIC_API_URL=https://api-host/api` at *build*
  time (it is baked into the client bundle) AND add the dashboard origin to
  `backend/config/cors.php` (`allowed_origins`) — the shipped config only
  allows localhost patterns.

## 4. Flutter mobile app

`flutter run -d web-server --web-port 8080` (used by `start.sh`) serves the
debug web build for browser demos. For devices:

```bash
cd mobile
flutter pub get
flutter build apk --debug                 # demo install
flutter build apk --release               # store-ready (needs signing, below)
flutter install
```

Release signing: create a keystore (`keytool -genkey`), add
`android/key.properties` (gitignored, never commit), wire it in
`android/app/build.gradle.kts`. Status on Day 4: Android SDK 36.1.0 with
pre-accepted licenses is present, but **no JDK is installed and the sandbox
proxy blocks large downloads**, so no APK was produced here — run the build on
the demo machine after installing Temurin/OpenJDK 17 (`JAVA_HOME` set).

API base URL: `ApiClient` defaults to `http://127.0.0.1:8000` with no settings
override yet. On an emulator use `10.0.2.2:8000`, on a physical device the
host's LAN IP — currently requires a one-line change or `--dart-define`
plumbing (Day 5 hardening item).

## 5. Environment variables & secret handling (verified Day 4)

- `backend/.env` is gitignored (`git check-ignore` passes); only
  `.env.example` / `.env.production.example` (placeholder-only) are tracked.
- `MODEL_API_KEY` lives only in backend `.env`, read via
  `config('services.meta_ai.api_key')`; API responses expose only
  `has_api_key: bool`; exception logs mask credentials. No key is configured
  in any environment — Meta calls are honestly `unconfigured`, never mocked
  as live output.
- No `frontend/.env*` files exist; no secrets in `mobile/` (it stores only a
  user auth token at runtime).
- Seed logins (`admin@fisheries.gov.ki`, `teititeki@fisher.ki`, …) are
  **synthetic demo credentials** — rotate or remove them in production.

## 6. Rollback

- **Backend:** `git checkout <prev-sha> -- backend/`, `php artisan migrate:rollback
  --step=<n>`, `php artisan config:clear`, restore DB from backup, restart
  php-fpm. Keep the previous release directory for instant symlink rollback.
- **Frontend:** keep the previous `.next/` build (`cp -r .next .next.prev`
  before deploying); rollback = restore directory + restart `npm start`.
- **Mobile:** keep the previous APK (`app-debug.apk.<date>`); rollback =
  reinstall it (`adb install -r`).
- **Database:** restore the pre-deploy dump/file copy taken in §2. Never roll
  code back without restoring a same-vintage DB backup (migrations are
  one-directional per release).

## 7. Post-deploy health check

```bash
bash scripts/status.sh   # demo topology
curl -s https://HOST/api/health
curl -s -o /dev/null -w "%{http_code}\n" https://HOST/
```

Log in as admin, open the catch table + map, run one
`POST /api/ai/identify-fish` with a specimen preset URL, and confirm the
toxin disclaimer renders. Roll back on any failure (see §6).
