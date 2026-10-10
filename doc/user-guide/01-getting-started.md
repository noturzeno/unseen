# 1. Getting started

This guide takes you from nothing to signed in with an empty project list.

**Time:** about 15 minutes · **Who:** the person setting up Unseen (usually a developer)

## What you need

| Tool | Version | Check with |
|---|---|---|
| PHP | 8.3 or newer, with `pdo_pgsql` (or `pdo_sqlite` for a quick trial) | `php -v` and `php -m` |
| Composer | 2.x | `composer -V` |
| PostgreSQL | 14 or newer (16 tested) | `psql --version` |
| Node.js | 22.18 or newer (only for the CLI) | `node -v` |

> **Trying it quickly?** You can use SQLite instead of PostgreSQL for a local trial (see step 3, option B).
> Use PostgreSQL for anything shared with the team.

## Step 1: Open the project folder

```bash
cd ~/Desktop/VisibleOne/tracker/api
```

Everything server-related happens in `api/`. The CLI lives in `packages/cli/` (guide 5).

## Step 2: Install the PHP dependencies

```bash
composer install
```

You should see `Generating optimized autoload files` with no errors.

## Step 3: Configure the database

1. Create your settings file:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
2. Choose a database.

   **Option A: PostgreSQL (recommended)**
   ```bash
   brew services start postgresql@16      # macOS with Homebrew; skip if Postgres already runs
   createdb unseen
   ```
   `.env` already points at a local database called `unseen`. If your Postgres user or password
   is different, edit these lines in `.env`:
   ```
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=unseen
   DB_USERNAME=postgres
   DB_PASSWORD=
   ```

   **Option B: SQLite (quick local trial only)**
   ```bash
   touch database/database.sqlite
   ```
   Then in `.env` set `DB_CONNECTION=sqlite` and delete the other `DB_` lines.

3. Create the tables:
   ```bash
   php artisan migrate
   ```
   Every line should end in `DONE`.

> ⚠️ Never run `php artisan migrate:fresh` on a database that holds real data. It deletes everything.

## Step 4: Create your account

There is no public sign-up. Accounts are created from the command line:

```bash
php artisan unseen:create-user you@example.com --name="Your Name"
```

You'll be asked for a password, which must be **at least 12 characters**. Nothing appears on screen while you type.
You should see `User you@example.com created.`

Repeat this for each colleague who needs access (or see [guide 10](10-administration.md)).

## Step 5: Start Unseen

```bash
php artisan serve
```

Leave this terminal open. Unseen is now running at **http://127.0.0.1:8000**.

## Step 6: Sign in

1. Open http://127.0.0.1:8000 in your browser. You'll be sent to the sign-in page.
2. Enter your email and password, and tick **Keep me signed in** if you like.
3. Click **Sign in**.

You should now see **Projects** with the message *No projects yet*.

> After 5 wrong passwords, sign-in is paused for about a minute for that email and computer. This is intentional.

## Step 7 (optional): Run the tests

To confirm everything works on your machine:

```bash
php artisan test
```

All tests should pass.

## What's next

→ [2. Projects and team](02-projects-and-team.md): create your first project.
