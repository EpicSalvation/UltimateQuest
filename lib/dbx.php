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

/** Slugs of themes the user can pick. The CSS keys off these. */
const THEMES = ['quest', 'sunset', 'ocean', 'meadow', 'light', 'dark'];
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
