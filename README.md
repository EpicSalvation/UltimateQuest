# The Ultimate Quest

A self-hosted PHP web app for running a photo/video scavenger hunt — built for a church youth event, but usable for any team-based challenge night. Teams log in with a PIN from their phones, submit photo and video evidence for numbered tasks, and admins review submissions live from a dashboard while a leaderboard tracks scores.

There is no build step, no framework, and no package manager: it's plain PHP 8.1 + MySQL, designed to run on a cheap shared-hosting LAMP stack (cPanel-style).

## How the game works

- **Admins create tasks**, each worth a number of `points`, optionally with a `penalty` (points a team loses if they *don't* complete the task by the end), required photo/video counts, and a "Priority ⭐" highlight flag.
- **Teams** log in with a shared PIN, see the task list with a live countdown to the event start/end times, and upload photos or videos as evidence. Uploads show a real progress bar (works on mobile Safari) and phone formats like HEIC are accepted.
- **Admins review** from a live dashboard: pending submissions appear within seconds, previews load as lightweight thumbnails, and each can be approved or rejected (with a note the team sees). Bulk approve/reject is supported. Rejected teams can resubmit; prior files are archived rather than deleted.
- **Scores are always derived, never stored**: net score = sum of approved task points − penalties of every task not yet approved (so it can go negative until a team clears its priority tasks). A leaderboard page (optionally hidden from teams until the reveal) auto-refreshes every 30 seconds.
- **Ending a game** snapshots all results, settings, and uploads into a per-game archive (downloadable as a bundle), records it in a `past_games` table, and resets the live tables for the next event.

## Feature highlights

- **Team side:** PIN login, per-team task status, countdown timer, multi-file XHR uploads with progress overlay, resubmission after rejection, theme picker (saved per device).
- **Admin side:** password login, live-polling review dashboard, task CRUD + CSV bulk import (with column mapping), team management, event start/end and leaderboard-reveal settings, site theme default, end-game archive/reset.
- **Ops:** a read-only status page (`admin/status.php`) meant to stay open during the event — PHP/extension health, disk usage, upload volume, review latency, oldest pending age, thumb-failure rates, and the tail of the upload error log.
- **Media pipeline:** thumbnails generated at upload time (GD for JPEG/PNG/WebP, Imagick for HEIC when the delegate exists); admin previews load thumbs, not originals; videos lazy-load metadata only.
- **Security:** password/PIN hashes via `password_hash`, prepared statements everywhere, CSRF checks on mutations, login rate limiting (`login_attempts` table), session hardening (httponly, samesite=Lax, strict mode, ID regeneration), and all uploads stored **outside the webroot** and streamed only through admin-gated endpoints that validate path components against the database.

## Repository layout

```
index.php            Landing page → team login
login.php            Team PIN login
team.php             Team task list, countdown, score
task_submit.php      Multi-file upload endpoint (XHR, progress events)
leaderboard.php      Auto-refreshing leaderboard (team-visible when revealed)
logout.php
admin/
  index.php          Admin login
  dashboard.php      Live review dashboard + team/task management
  import_tasks.php   CSV task import (column mapping, append or replace)
  settings.php       Site appearance / theme default
  status.php         Read-only health & metrics page
api/                 JSON endpoints used by the dashboard and team pages
  get_pending.php    approve.php / reject.php / bulk_review.php
  get_teams.php      add_task.php / edit_task.php / delete_task.php
  get_tasks.php      get_submission.php / time.php
  file.php           Admin-gated full-file streaming
  thumb.php          Admin-gated thumbnail streaming
  end_game.php       Archive + reset for the next event
  download_game_archive.php
lib/
  config.php         Bootstrap: timezone, DATA_DIR, BASE_URL, session
  dbx.php            PDO singleton + settings helpers
  auth.php           Team/admin auth, login gates
  csrf.php           CSRF token issue/check
  thumbs.php         Thumbnail generation (GD / Imagick HEIC)
  theme_picker.php   Player-facing theme selector
  schema.sql         MySQL schema
style.css            Single stylesheet, themeable
.user.ini            PHP upload/memory/exec limits for large videos
dbconfig.sample.php  Template for the out-of-webroot DB config
```

## Architecture notes

- **URL prefix:** the app is served under `/UltimateQuest/` by default. The prefix is the `BASE_URL` constant in `lib/config.php` (overridable from `dbconfig.php`); every link, redirect, and JS path uses it — never hardcode the prefix.
- **Data lives outside the webroot:** `DATA_DIR` (default `/home/thestarv/UltimateQuestData`, set in `lib/config.php`) holds `dbconfig.php` (DB credentials), `uploads/<team>/<task_id>/` (with `thumbs/` subdirs), the per-team `rejections/` archive, and `games/<label>/` end-of-game snapshots.
- **Database:** MySQL/InnoDB, utf8mb4. Tables: `teams`, `tasks`, `submissions` (one row per team/task — resubmission updates it), `submission_files`, `settings` (key/value: event times, leaderboard reveal, admin password hash, theme), `past_games`, `login_attempts`. See `lib/schema.sql`.
- **Scoring:** derived in SQL at read time with a `SUM(CASE ...)` over approved/pending submissions minus the all-tasks penalty total. There are deliberately no stored score columns — don't add caching, the join is cheap and drift was the bug this design removed.
- **Timezone** is pinned to `America/New_York`; event times and timestamps assume Eastern.
- **Upload limits:** `.user.ini` raises PHP limits for video (300M upload / 320M post / 512M memory / 300s exec), but the shared host enforces a hard 128M POST cap — `MAX_POST_BYTES` in `lib/config.php` guards against it on both client and server.

## Setup

Requirements: PHP 8.1+ with `pdo_mysql`, `gd`, `fileinfo`, `mbstring`, `exif` (and optionally `imagick` with the HEIC delegate for iPhone photo thumbnails), plus a MySQL database.

1. **Serve the repo** under `/UltimateQuest/` (or set `BASE_URL` to wherever you serve it from — see step 3).
2. **Create the data directory** outside the webroot and make it writable by PHP. Adjust `DATA_DIR` in `lib/config.php` if you're not using the default path.
3. **Copy `dbconfig.sample.php`** to `<DATA_DIR>/dbconfig.php` and fill in `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`. Optionally `define('BASE_URL', ...)` there to override the URL prefix without touching the repo.
4. **Create the database and apply the schema** (`lib/schema.sql`) via phpMyAdmin or the `mysql` CLI.
5. **Set the admin password** by inserting its hash into settings:

   ```sql
   INSERT INTO settings (k, v) VALUES ('admin_password_hash', '<hash>');
   ```

   Generate `<hash>` with `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"`.
6. **Log in at `/UltimateQuest/admin/`** to set event start/end times, create teams (each gets a PIN) and tasks (or bulk-import them from CSV), and pick a theme.

During the event, keep `admin/status.php` open — "oldest pending age" and the thumb-failure rate are the leading indicators that something needs attention.

There is no test suite or lint config; see `CLAUDE.md` for detailed development conventions and things to watch out for.
