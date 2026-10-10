# 10. Administration

For the person who runs the Unseen server. All commands run in the `api/` folder.

## Manage user accounts

### Create a user
```bash
php artisan unseen:create-user colleague@example.com --name="Chris Colleague"
```
You'll be asked for a password of at least 12 characters. Give it to the person through a safe channel and ask them to
keep it in a password manager.

### Reset a user's password
There is no reset screen yet. An administrator can set a new password:
```bash
php artisan tinker
>>> $u = App\Models\User::where('email', 'colleague@example.com')->first();
>>> $u->password = 'a-new-long-password';   // stored hashed automatically
>>> $u->save();
>>> exit
```
Clear your terminal history afterwards if the password was typed in plain text.

### Remove someone's access
There is no "deactivate user" button yet. To remove access completely:
```bash
php artisan tinker
>>> $u = App\Models\User::where('email', 'leaver@example.com')->first();
>>> $u->tokens()->delete();                         // revoke all API tokens
>>> $u->password = Illuminate\Support\Str::random(64); $u->save();   // block sign-in
>>> exit
```
Then, on each project they belonged to, the owner removes them under **Settings → People**. Projects they
**own** stay; move ownership first if needed:
```bash
>>> App\Models\Project::where('owner_id', $u->id)->update(['owner_id' => NEW_OWNER_ID]);
```

## Run Unseen in production

Unseen is a standard Laravel 13 application. The essentials:

1. **Server:** PHP 8.3+ with `pdo_pgsql`, PostgreSQL 14+, a web server (Nginx or Apache) serving `api/public/`, and **HTTPS**.
2. **Install:**
   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env && php artisan key:generate
   ```
3. **Settings in `.env`:**
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://unseen.example.com
   DB_CONNECTION=pgsql
   DB_HOST=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=...
   ```
   Keep `.env` out of git and readable only by the web server user.
4. **Database and caches:**
   ```bash
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
5. **Scheduler** (deletes expired tokens daily). Add one cron entry:
   ```
   * * * * * cd /path/to/tracker/api && php artisan schedule:run >> /dev/null 2>&1
   ```
6. **Permissions:** the web server needs write access to `storage/` and `bootstrap/cache/`.

Before going live, ask the IT team (**it@visibleone.com**) to review the hosting, backups and access.

## Back up and restore

Everything lives in the PostgreSQL database.
```bash
# Back up (daily, kept off the server)
pg_dump --format=custom --file=unseen-$(date +%F).dump unseen

# Restore into an empty database
createdb unseen_restored
pg_restore --dbname=unseen_restored unseen-2026-10-09.dump
```
Backups contain staff names and emails, so store them encrypted, with limited access.

## Timezone

Unseen runs in **Asia/Bangkok (UTC+7)**: the web app shows times in it and the database stores them in it
(`APP_TIMEZONE` in `api/.env`; the database connection uses the same zone). API responses send times in UTC
ISO-8601 (`…Z`), the same instant, so clients anywhere read them correctly.

Changing the zone later: set `APP_TIMEZONE`, then convert stored timestamps the same way the migration
`convert_timestamps_to_app_timezone` did (it shifted the UTC rows written before the change by +7 hours).

## Offline use

Unseen needs no internet access: the web app loads nothing from outside your server (diagrams use a bundled copy
of Mermaid), and setup prompts install the CLI from your server (`/downloads/unseen-cli.tgz`, no other
packages). Computers running the CLI need Node.js 22.18 or newer installed already. Coding agents still need their
own connection to their AI service.

To update the bundled Mermaid: download the new `dist/mermaid.min.js` and `LICENSE` into
`api/public/vendor/mermaid/<version>/`, then update the path and the `integrity` hash
(`openssl dgst -sha384 -binary mermaid.min.js | openssl base64 -A`) in `feature-detail.blade.php`.

## Update Unseen

```bash
php artisan down
# get the new code
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```
Then rebuild the CLI download that setup prompts install from: `cd packages/cli && npm install && npm run pack:server`
(writes `api/public/downloads/unseen-cli.tgz`). Computers that already have the CLI update with
`npm install -g <Unseen URL>/downloads/unseen-cli.tgz`.

**On Vercel** the build does not run migrations. When a deploy brings new files in `api/database/migrations/`, run
them on the production database (Supabase) yourself, before or right after deploying, or pages and CLI requests that use
the new columns fail with a 500 error:

```bash
cd api && scripts/migrate-production.sh   # asks for the connection string (hidden), lists pending migrations, asks before running
```
When you repack the CLI, also set `Cli::LATEST` in `api/app/Support/Cli.php` to the new version, so the dashboard asks
people with older CLIs to update.

## Check that everything works

```bash
cd api && php artisan test                 # server: all tests should pass
cd ../packages/cli && npm test             # CLI: all tests should pass
```

## What's next

→ [11. Troubleshooting and FAQ](11-troubleshooting.md)
