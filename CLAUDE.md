# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

"The Ultimate Quest" — a PHP web app for a church youth scavenger-hunt event. Teams log in with a PIN, submit photo/video evidence for numbered tasks, and admins approve or reject submissions from a dashboard. There is no build step and no package manager; this is plain PHP that runs on a shared-hosting LAMP stack (A2/hosting.com "Drive Web Hosting", PHP 8.1, MySQL).

## Runtime & deployment shape

- **Deployed under a URL prefix.** The repo root is served at `<host>/UltimateQuest/`. The prefix is centralized as the `BASE_URL` constant in `lib/config.php`; every internal link, redirect, `<form action>`, `<link>`, and inline JS path uses `<?=BASE_URL?>` or the JS `BASE` constant. Never hardcode `/UltimateQuest/` in new code.
- **PHP configuration** lives in `.user.ini` (300M upload / 320M post / 512M memory, 300s exec) — needed for video uploads.
- **Data directory is outside the webroot.** `DATA_DIR` defaults to `/home/thestarv/UltimateQuestData` in `lib/config.php`. All uploads, the rejection archive, and `dbconfig.php` live there — not in the repo.
- **Database credentials** live in `DATA_DIR/dbconfig.php` (outside webroot). It defines `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and may override `BASE_URL`. `lib/config.php` requires it; absence will fatal.
- **Timezone is pinned** to `America/New_York` in `lib/config.php` and re-asserted on user-facing pages. Event start/end and all timestamps assume Eastern.

## Storage model — MySQL

State lives in MySQL (InnoDB, utf8mb4). Schema is in `lib/schema.sql` and gets applied by `migrate.php`. Tables:

- `teams(id, name UNIQUE, display_name, pin_hash, late_minutes, late_void, dq_void, late_note, late_updated_at, created_at)` — `name` is the lowercased-underscored slug; `display_name` is what users see; `pin_hash` is `password_hash`'d.
- `tasks(id, task_no, title, description, points, penalty, photos_required, videos_required, mandatory, bonus, sort_order, created_at)` — `penalty` is the points deducted when a team does NOT complete the task; it is **always stored positive** (inputs like `-25` are normalized) and the scoring queries subtract it. `mandatory` is the "Priority ⭐" flag: it only drives highlighting/labeling in the UI — there is no qualification rule attached to it (admins express the stakes via `penalty`). `task_no` (VARCHAR, nullable) is the **human-facing task label** shown to teams (e.g. `1a` main / `1b` bonus), kept separate from the auto-increment `id`; display only, falls back to `#id` when blank. `bonus` is the "Bonus 🎁" flag — a second UI highlight (violet) alongside Priority (amber); it too is display-only (a bonus task is just a normal points-bearing task).
- `submissions(id, team_id FK CASCADE, task_id FK CASCADE, status ENUM('pending','approved','rejected'), note, submitted_at, reviewed_at)` with `UNIQUE(team_id, task_id)` and `KEY(status, submitted_at)`. One row per team/task — resubmission updates the existing row.
- `submission_files(id, submission_id FK CASCADE, filename, mime_type, byte_size, has_thumb, slideshow, created_at)` — `slideshow` is the admin-curated "use in end-of-event slideshow" flag (schema v4).
- `infractions(id, team_id FK CASCADE, points, reason, created_at)` — ad-hoc point deductions an admin records against a team for a reported infraction (schema v7). A **log, not a column**: a team can collect several, and each is undone individually rather than edited. `points` is stored positive (inputs like `-25` are normalized with `abs()`) and scoring subtracts the SUM. Helpers in `lib/dbx.php`: `infraction_total($team_id)`, `infractions_for($team_id)`, and `sql_infraction_deduction($t)` for score queries. The SQL helper is a **correlated subquery on purpose** — a JOIN would multiply the submission rows the score SUMs over, so it needs no GROUP BY changes. Admins manage these from the Teams table on the dashboard (⚠️ Penalty button → modal that adds one and lists/undoes existing ones) via `api/add_infraction.php`, `api/delete_infraction.php`, `api/get_infractions.php`. `api/end_game.php` archives the log then clears it. The `reason` is shown to the team on `team.php`.
- `settings(k VARCHAR(64) PK, v TEXT)` — holds `event_start_time`, `event_end_time`, `reveal_leaderboard`, `admin_password_hash`, `starter_gate_count`, `db_schema_v`. Read with `setting($k, $default)`, write with `set_setting($k, $v)` (both in `lib/dbx.php`).

**Starter-task unlock gate.** `starter_gate_count` (default `0` = off) makes the first N tasks in list order (`ORDER BY sort_order, id`) *gate* the rest: a team must have an active submission (status `pending` OR `approved`) for all N before any other task opens. A rejected or not-yet-started starter leaves the gate closed. Helpers live in `lib/dbx.php`: `starter_gate_count()`, `starter_task_ids($n)`, `starter_gate_open($team_id, $starter_ids=null)`. The gate is **enforced server-side in `task_submit.php`** (locked tasks 403 on POST and hide the upload form), not just by hiding the "Open" link on `team.php`. Scoring is unaffected — the gate only controls submittability.

**Late arrival back to homebase.** `teams.late_minutes` (nullable) is the only stored fact; the deduction and the disqualification are derived from it (schema v6). Tariff, in `lib/dbx.php`: 1–5 min → `LATE_RATE_PER_MIN` (5) per minute; over 5 min → flat `LATE_FLAT_PENALTY` (75); over `LATE_DQ_MINUTES` (10) → that same flat 75 **plus** disqualification. A disqualified team's score displays as `0` and sorts last; `score_before_dq` carries the pre-DQ number for admins judging a challenge.

Teams can challenge a ruling, so it is reversible: `late_void` drops the point deduction, `dq_void` drops the disqualification, and the two are **independent** — a challenge can succeed on the DQ while the points stand. Nothing is deleted; clearing a void reinstates the original ruling. Admins set all of this from the Teams table on the dashboard (⏰ Late button → modal with a live preview) via `api/set_late.php`. `api/end_game.php` clears the rulings from any teams that survive into the next game.

The rule is implemented three times — `late_deduction()`/`late_disqualified()` in PHP, `sql_late_deduction()`/`sql_late_dq()` for queries that must sort by final score, and a `LATE_TARIFF()` preview mirror in `admin/dashboard.php`. **Change all three together.** Every score query must also carry `sql_late_group_by()` in its GROUP BY.

**Scores are derived, never stored.** The old `score_awarded`/`score_pending` columns are gone. Every page that needs a score computes it inline. A task's `penalty` counts against a team until that task is approved, so the net score is:

```sql
COALESCE(SUM(CASE WHEN s.status='approved' THEN tk.points + tk.penalty ELSE 0 END), 0)
  - (SELECT COALESCE(SUM(penalty), 0) FROM tasks) AS score  -- net
SUM(CASE WHEN s.status='approved' THEN tk.points ELSE 0 END) AS awarded  -- raw approved points
SUM(CASE WHEN s.status='pending'  THEN tk.points ELSE 0 END) AS pending
```

…then subtract the late-arrival deduction (`sql_late_deduction('t')`) and the infraction deduction (`sql_infraction_deduction('t')`) from the net, and force the net to `0` when `sql_late_dq('t')` is 1.

The five places that derive a score and must stay in step: `team.php`, `leaderboard.php`, `api/get_teams.php`, `api/end_game.php`, `admin/status.php`.

(Approving a task both awards its points and clears its penalty, hence `points + penalty` minus the constant all-tasks penalty total.) Net score can be negative. If you find yourself caching a score, stop — the join is cheap and drift is the bug we deliberately removed.

**DB access** is `db()` from `lib/dbx.php` — singleton PDO with `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`. Always use prepared statements; never string-interpolate user input.

**Resubmission flow** (`task_submit.php`) inside one transaction:
1. Move existing files from `uploads/<team>/<task_id>/` to `uploads/<team>/rejections/<task_id>_<ts>/` (including the `thumbs/` subdir).
2. `DELETE FROM submission_files WHERE submission_id = ?`.
3. `UPDATE submissions SET status='pending', note=NULL, submitted_at=NOW(), reviewed_at=NULL`.
4. Insert new `submission_files` rows.

## Thumbnails

Admin previews load thumbnails, not full files — this is the main perceived-speed lever. `lib/thumbs.php` provides `generate_thumb($src, $dest, $mime)`:

- JPEG/PNG/WebP via GD, resized to 400px wide, JPEG q80, with EXIF orientation handling.
- HEIC/HEIF via Imagick when the HEIC delegate is present (`thumbs_can_heic()` static-cached). When unavailable, `has_thumb=false` is recorded; admin UI shows an "Open full file" link for that file.
- Videos are not thumbnailed; admin modal lazy-loads `<video preload="metadata">` only after the user expands a tile.

Thumbs are written to `uploads/<team>/<task_id>/thumbs/<filename>.jpg` (extension always replaced with `.jpg`, see `thumb_path_for()`). Generation is synchronous in `task_submit.php` after `move_uploaded_file`; failures log to `upload_errors.log` and are non-fatal. `api/thumb.php` is admin-gated and serves them with a 1h private cache.

## Request flow

- **Team:** `index.php` → `login.php` (PIN → `password_verify` against `teams.pin_hash`) → `team.php` (lists tasks with per-team status; derives score; renders countdown from `settings.event_start_time`/`event_end_time`) → `task_submit.php` (multi-file upload via XHR with `upload.onprogress` updating a `<progress>` overlay).
- **Admin:** `admin/index.php` (password vs `settings.admin_password_hash`) → `admin/dashboard.php`. Dashboard polls `api/get_pending.php` (10s), `api/get_teams.php` (15s), `api/get_tasks.php` (20s); mutations go to `api/approve.php`, `api/reject.php`, `api/add_task.php`, `api/edit_task.php`, `api/delete_task.php`. Each file in the review modal has a "☆ Slideshow" toggle (`api/toggle_slideshow.php`); `admin/slideshow.php` shows the curated set and `api/download_slideshow.php` streams it as one ZIP (one folder per team, skips files that vanished via resubmission). `admin/import_tasks.php` bulk-imports tasks from a CSV (upload → column-mapping form with auto-guessed headers → append or purge-and-replace; parsed rows staged in the session). `admin/status.php` is the read-only health/metrics page (auto-refresh 15s).
- **Media:** uploads under `DATA_DIR/uploads/<team>/<task_id>/` are outside the webroot. `api/file.php` (admin-only) streams full files; `api/thumb.php` (admin-only) streams thumbs. New media UI must use one of these.
- **Auth gates:** every page/endpoint starts with `require_once lib/auth.php` and calls `require_team()` or `require_admin()`. These redirect (via `BASE_URL`) on failure rather than returning errors. Match this pattern for new endpoints.

## Auth specifics

- Admin password is hashed once and stored in `settings.admin_password_hash`. `try_admin_login` reads it and `password_verify`s — no per-request hashing.
- Team PINs are hashed with `password_hash` at team-creation time in the admin UI; `try_team_login` `password_verify`s.
- Sessions use `samesite=Lax`, `httponly`, `path=BASE_URL.'/'`. Login regenerates the session ID.

## Conventions worth preserving

- Team `name` is the canonical lowercased-underscored slug (`normalize_team_name()` in `lib/auth.php`); `display_name` is what users see. URLs and disk paths use `name`.
- The `tasks.mandatory` column (and the `mandatory` key in JSON/POST payloads) is presented to users as **"Priority"** — keep the internal name for upgrade compatibility, but never surface the word "mandatory"/"required" (or any qualification language) in UI text.
- `tasks.penalty` is always stored as a positive integer; normalize inputs with `abs(intval(...))` and let the SUM/CASE scoring pattern do the subtraction.
- Task IDs are auto-increment integers from `tasks.id`.
- Cache-bust `<link rel=stylesheet>` with `?v=<?=@filemtime(__DIR__.'/style.css')?>` (relative to the file itself — no DOCUMENT_ROOT lookups).
- Upload MIME allowlist + extension fallback for `application/octet-stream` lives in `task_submit.php`. Phones send HEIC or octet-stream often; both the allowlist and the extension fallback are load-bearing — keep them.
- Rejection archive layout is `uploads/<team>/rejections/<task_id>_<YmdHis>/` (and includes the prior `thumbs/` subdir). Admins know this path.

## Running locally

No test suite, lint config, or build. To iterate:

1. Make the repo serve under `/UltimateQuest/` (Apache alias, or set `BASE_URL` in `dbconfig.php` to match wherever you serve it from).
2. Create the `DATA_DIR` (or edit `lib/config.php`) and put a `dbconfig.php` in it with `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` constants.
3. Create the MySQL database, then visit `migrate.php` once. It applies `lib/schema.sql`, prompts for an admin password, seeds default `settings`, and archives any pre-existing JSON files into `archive/<ts>/`. The page can self-gate (first-run anonymous, then admin-only).
4. Log in as admin and use the dashboard to set event start/end times and create teams + tasks.

## Status / diagnostics

`admin/status.php` is the read-only health page intended to stay open during the event:

- Environment: PHP version, upload/post/memory/exec limits, extension pills (pdo_mysql, gd, imagick, fileinfo, mbstring, exif), Imagick HEIC delegate presence, writability of `DATA_DIR`/`uploads/`.
- Storage: `disk_free_space`/`disk_total_space`, recursive total bytes under `uploads/` (cached 60s in `.uploads_size_cache` to avoid hammering shared-host IO), top-5 largest `submission_files`.
- Database: server version, row counts, submission status breakdown.
- Live game metrics: median review latency (`TIMESTAMPDIFF(SECOND, submitted_at, reviewed_at)`), oldest pending age (computed in SQL, not PHP — `TIMESTAMPDIFF` against `MIN(submitted_at) WHERE status='pending'`), approval rate, thumb-failure rate by mime.
- Per-team and per-task tables; tail of `upload_errors.log`.

The "oldest pending age" and "thumb failure rate" tiles are the leading indicators during the event.

## Things to watch out for

- `task_submit.php`'s `xhr.upload.onprogress` is the only reliable mobile-Safari upload-progress path; don't rewrite it as `fetch` streams.
- Don't denormalize scores. If you need them in a query result, derive them via the SUM/CASE pattern above.
- `api/file.php` and `api/thumb.php` validate the team/task path components against the DB — preserve that when modifying. Path traversal here would expose the entire data dir.
- `migrate.php` should be deletable (or env-gated) after first run; it runs DDL and sets the admin password.
