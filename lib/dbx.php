<?php
// lib/dbx.php — MySQL/PDO singleton + small helpers.
// Credentials come from dbconfig.php inside DATA_DIR (loaded by lib/config.php).

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        throw new RuntimeException('DB credentials not loaded. Expected dbconfig.php at DATA_DIR.');
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    ensure_schema_upgrades($pdo);
    return $pdo;
}

function ensure_schema_upgrades(PDO $pdo): void {
    try {
        $v = (int)($pdo->query("SELECT v FROM settings WHERE k='db_schema_v'")->fetchColumn() ?: 1);
        if ($v < 2) {
            $col = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'mandatory'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE tasks ADD COLUMN mandatory TINYINT(1) NOT NULL DEFAULT 0 AFTER videos_required");
            }
            $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_v','2') ON DUPLICATE KEY UPDATE v=VALUES(v)");
        }
        if ($v < 3) {
            // Per-task non-completion penalty, stored positive; scoring subtracts it.
            $col = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'penalty'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE tasks ADD COLUMN penalty INT UNSIGNED NOT NULL DEFAULT 0 AFTER points");
            }
            $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_v','3') ON DUPLICATE KEY UPDATE v=VALUES(v)");
        }
        if ($v < 4) {
            // Per-file "use in end-of-event slideshow" flag, set by admins during review.
            $col = $pdo->query("SHOW COLUMNS FROM submission_files LIKE 'slideshow'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE submission_files ADD COLUMN slideshow TINYINT(1) NOT NULL DEFAULT 0 AFTER has_thumb");
            }
            $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_v','4') ON DUPLICATE KEY UPDATE v=VALUES(v)");
        }
        if ($v < 5) {
            // Human task number ("1a"/"3b"), separate from the auto-increment id.
            $col = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'task_no'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE tasks ADD COLUMN task_no VARCHAR(16) NULL AFTER id");
            }
            // "Bonus challenge" flag — highlighted in the UI like Priority.
            $col = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'bonus'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE tasks ADD COLUMN bonus TINYINT(1) NOT NULL DEFAULT 0 AFTER mandatory");
            }
            $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_v','5') ON DUPLICATE KEY UPDATE v=VALUES(v)");
        }
    } catch (Throwable $e) {
        // Non-fatal: app continues; admin can re-run migrate.php if needed.
    }
}

function setting(string $k, ?string $default = null): ?string {
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $row = $st->fetch();
    return $row !== false ? $row['v'] : $default;
}

function set_setting(string $k, ?string $v): void {
    $st = db()->prepare(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $st->execute([$k, $v]);
}

// ── Starter-task gate ─────────────────────────────────────────────────────
// Optionally require teams to submit the first N tasks (a test task, prop
// collection, etc.) before the remaining tasks unlock. "Submitted" means an
// active submission exists (pending or approved); a rejected or not-yet-started
// starter leaves the gate closed. N = 0 disables the gate entirely.

/** Number of leading tasks (by list order) that gate the rest. 0 = no gate. */
function starter_gate_count(): int {
    return max(0, (int)setting('starter_gate_count', '0'));
}

/** Ids of the first $n tasks in list order — the "starter" tasks that gate
 *  the rest. $n is cast to a plain int and inlined (LIMIT can't be bound). */
function starter_task_ids(int $n): array {
    if ($n <= 0) return [];
    $n = (int)$n;
    $rows = db()->query("SELECT id FROM tasks ORDER BY sort_order, id LIMIT $n")
                ->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/** True once $team_id has an active (pending/approved) submission for every
 *  starter task — i.e. the gate is open and the remaining tasks unlock.
 *  Pass $starter_ids to reuse an already-computed list. */
function starter_gate_open(int $team_id, ?array $starter_ids = null): bool {
    $starter_ids ??= starter_task_ids(starter_gate_count());
    if (!$starter_ids) return true;
    $in = implode(',', array_fill(0, count($starter_ids), '?'));
    $st = db()->prepare(
        "SELECT COUNT(*) FROM submissions
          WHERE team_id = ? AND status IN ('pending','approved') AND task_id IN ($in)"
    );
    $st->execute(array_merge([$team_id], $starter_ids));
    return (int)$st->fetchColumn() >= count($starter_ids);
}

/** Slugs of themes the user can pick. The CSS keys off these. */
const THEMES = ['quest', 'sunset', 'ocean', 'meadow', 'carnival', 'light', 'dark'];
const DEFAULT_THEME = 'quest';

/** Returns the active theme slug.
 *  Order: per-device cookie → site-wide setting → DEFAULT_THEME.
 *  Cookie lets each player pick their own colour scheme without affecting
 *  others. Admin's "Site settings" page sets the default for users with
 *  no cookie, plus echoes the value into the admin's own cookie so they
 *  see what they picked.
 */
function current_theme(): string {
    $cookie = $_COOKIE['theme'] ?? null;
    if (is_string($cookie) && in_array($cookie, THEMES, true)) {
        return $cookie;
    }
    try {
        $v = setting('theme', DEFAULT_THEME);
    } catch (PDOException $e) {
        return DEFAULT_THEME;
    }
    return in_array($v, THEMES, true) ? $v : DEFAULT_THEME;
}

/** Renders ` data-theme="..."` (with leading space) for an <html> tag. */
function theme_html_attr(): string {
    return ' data-theme="' . htmlspecialchars(current_theme(), ENT_QUOTES) . '"';
}
