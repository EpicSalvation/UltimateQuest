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
        if ($v < 6) {
            // Late-arrival penalty: minutes late are recorded, the point
            // deduction and disqualification are derived from them.
            $adds = [
                'late_minutes'    => "ALTER TABLE teams ADD COLUMN late_minutes INT UNSIGNED NULL DEFAULT NULL AFTER pin_hash",
                'late_void'       => "ALTER TABLE teams ADD COLUMN late_void TINYINT(1) NOT NULL DEFAULT 0 AFTER late_minutes",
                'dq_void'         => "ALTER TABLE teams ADD COLUMN dq_void TINYINT(1) NOT NULL DEFAULT 0 AFTER late_void",
                'late_note'       => "ALTER TABLE teams ADD COLUMN late_note VARCHAR(255) NULL AFTER dq_void",
                'late_updated_at' => "ALTER TABLE teams ADD COLUMN late_updated_at TIMESTAMP NULL AFTER late_note",
            ];
            foreach ($adds as $col => $sql) {
                if (!$pdo->query("SHOW COLUMNS FROM teams LIKE '$col'")->fetch()) $pdo->exec($sql);
            }
            $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_v','6') ON DUPLICATE KEY UPDATE v=VALUES(v)");
        }
        if ($v < 7) {
            // Ad-hoc point deductions for reported infractions — a log, so a
            // team can collect several and each stays individually reversible.
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS infractions (
                   id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                   team_id    INT UNSIGNED NOT NULL,
                   points     INT UNSIGNED NOT NULL DEFAULT 0,
                   reason     VARCHAR(255) NULL,
                   created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   PRIMARY KEY (id),
                   KEY idx_team (team_id),
                   CONSTRAINT fk_infractions_team FOREIGN KEY (team_id)
                     REFERENCES teams(id) ON DELETE CASCADE
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec("INSERT INTO settings (k,v) VALUES ('db_schema_v','7') ON DUPLICATE KEY UPDATE v=VALUES(v)");
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

// ── Late-arrival penalty ──────────────────────────────────────────────────
// Teams that get back to homebase after the deadline are docked. The tariff:
//   1–5 minutes late  → 5 points per minute (max 25)
//   6–10 minutes late → flat 75 points
//   over 10 minutes   → flat 75 points AND disqualification
// Only `teams.late_minutes` is stored; the deduction and the DQ are derived
// from it, so fixing the minutes re-scores the team with no other edits.
//
// Teams may challenge. `late_void` drops the point deduction, `dq_void` drops
// the disqualification; they are independent, so a challenge can succeed on
// the DQ while the points stand. Nothing is deleted — clearing a void puts
// the original ruling straight back.

const LATE_RATE_PER_MIN  = 5;   // points per minute inside the graduated band
const LATE_GRADUATED_MAX = 5;   // last minute still charged per-minute
const LATE_FLAT_PENALTY  = 75;  // flat charge past the graduated band
const LATE_DQ_MINUTES    = 10;  // strictly more than this = disqualified

/** Points to dock a team that arrived $minutes late. */
function late_deduction(?int $minutes, bool $void = false): int {
    if ($void || $minutes === null || $minutes <= 0) return 0;
    return $minutes <= LATE_GRADUATED_MAX
        ? $minutes * LATE_RATE_PER_MIN
        : LATE_FLAT_PENALTY;
}

/** True when $minutes late earns a disqualification (and it wasn't voided). */
function late_disqualified(?int $minutes, bool $void = false): bool {
    return !$void && $minutes !== null && $minutes > LATE_DQ_MINUTES;
}

/** Plain-language summary of the ruling, for admin UI and team-facing notices. */
function late_ruling_text(?int $minutes, bool $late_void = false, bool $dq_void = false): string {
    if ($minutes === null || $minutes <= 0) return 'On time';
    $m    = $minutes . ' min late';
    $bits = [];
    $ded  = late_deduction($minutes, $late_void);
    if ($ded > 0)                       $bits[] = "−$ded pts";
    elseif (late_deduction($minutes) > 0) $bits[] = 'points challenge upheld';
    if (late_disqualified($minutes, $dq_void))   $bits[] = 'DISQUALIFIED';
    elseif (late_disqualified($minutes))         $bits[] = 'DQ challenge upheld';
    return $m . ' (' . implode(', ', $bits) . ')';
}

// SQL mirrors of the two rules above, for queries that have to sort by the
// final score. $t is the alias of the `teams` table in the query.
// Keep these in step with late_deduction()/late_disqualified().

function sql_late_deduction(string $t = 't'): string {
    return "(CASE WHEN $t.late_void = 1 OR $t.late_minutes IS NULL OR $t.late_minutes <= 0 THEN 0
                  WHEN $t.late_minutes <= " . LATE_GRADUATED_MAX . " THEN $t.late_minutes * " . LATE_RATE_PER_MIN . "
                  ELSE " . LATE_FLAT_PENALTY . " END)";
}

function sql_late_dq(string $t = 't'): string {
    return "(CASE WHEN $t.dq_void = 1 OR $t.late_minutes IS NULL THEN 0
                  WHEN $t.late_minutes > " . LATE_DQ_MINUTES . " THEN 1 ELSE 0 END)";
}

/** Columns every score query needs from `teams` in its GROUP BY. */
function sql_late_group_by(string $t = 't'): string {
    return "$t.late_minutes, $t.late_void, $t.dq_void";
}

// ── Infraction penalties ──────────────────────────────────────────────────
// Ad-hoc deductions an admin records against a team for a reported infraction
// (leaving the group, unsafe driving, arguing with a judge…). Unlike the late
// tariff there is no formula — the admin names the points. Each infraction is
// its own row so several can accumulate and any one can be undone without
// disturbing the others; nothing is ever edited in place.
//
// Points are stored positive; scoring subtracts the SUM.

const INFRACTION_MAX_POINTS = 9999; // sanity cap on a single deduction

/** Total infraction points recorded against a team. */
function infraction_total(int $team_id): int {
    $st = db()->prepare('SELECT COALESCE(SUM(points), 0) FROM infractions WHERE team_id = ?');
    $st->execute([$team_id]);
    return (int)$st->fetchColumn();
}

/** Every infraction against a team, newest first. */
function infractions_for(int $team_id): array {
    $st = db()->prepare(
        'SELECT id, points, reason, created_at FROM infractions
          WHERE team_id = ? ORDER BY created_at DESC, id DESC'
    );
    $st->execute([$team_id]);
    return $st->fetchAll();
}

/** SQL mirror of infraction_total() for score queries. Correlated on purpose:
 *  a JOIN would multiply the submission rows the score SUMs over. $t is the
 *  alias of the `teams` table in the outer query. */
function sql_infraction_deduction(string $t = 't'): string {
    return "(SELECT COALESCE(SUM(i.points), 0) FROM infractions i WHERE i.team_id = $t.id)";
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
