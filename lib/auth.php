<?php
// lib/auth.php — session-backed auth against the teams + settings tables.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dbx.php';
require_once __DIR__ . '/csrf.php';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 60;

function is_admin(): bool      { return !empty($_SESSION['is_admin']); }
function current_team(): ?string { return $_SESSION['team']    ?? null; }
function current_team_id(): ?int { return $_SESSION['team_id'] ?? null; }

function require_team(): void {
    if (!current_team()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: ' . BASE_URL . '/admin/index.php');
        exit;
    }
}

function normalize_team_name(string $raw): string {
    return strtolower(str_replace(' ', '_', trim($raw)));
}

// The login_attempts table is created by migrate.php. On a fresh DB it may not
// exist yet, in which case we degrade gracefully (no throttling) rather than
// fatal — otherwise the admin can't log in to run migrate.php.
function login_attempts_missing(PDOException $e): bool {
    return $e->getCode() === '42S02';
}

/** True when the bucket is currently locked out. */
function login_is_locked(string $bucket): bool {
    try {
        $st = db()->prepare('SELECT locked_until FROM login_attempts WHERE bucket = ?');
        $st->execute([$bucket]);
        $row = $st->fetch();
        return $row && $row['locked_until'] && strtotime($row['locked_until']) > time();
    } catch (PDOException $e) {
        if (login_attempts_missing($e)) return false;
        throw $e;
    }
}

function login_register_failure(string $bucket): void {
    try {
        $st = db()->prepare(
            'INSERT INTO login_attempts (bucket, attempts, locked_until)
             VALUES (?, 1, NULL)
             ON DUPLICATE KEY UPDATE
               attempts     = attempts + 1,
               locked_until = IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), locked_until)'
        );
        $st->execute([$bucket, LOGIN_MAX_ATTEMPTS, LOGIN_LOCK_SECONDS]);
    } catch (PDOException $e) {
        if (!login_attempts_missing($e)) throw $e;
    }
}

function login_clear(string $bucket): void {
    try {
        $st = db()->prepare('DELETE FROM login_attempts WHERE bucket = ?');
        $st->execute([$bucket]);
    } catch (PDOException $e) {
        if (!login_attempts_missing($e)) throw $e;
    }
}

function try_team_login(string $team, string $pin): bool {
    $name = normalize_team_name($team);
    if ($name === '' || $pin === '') return false;
    if (login_is_locked($name)) return false;

    $st = db()->prepare('SELECT id, name, pin_hash FROM teams WHERE name = ?');
    $st->execute([$name]);
    $row = $st->fetch();
    if (!$row || !password_verify($pin, $row['pin_hash'])) {
        login_register_failure($name);
        return false;
    }

    login_clear($name);
    session_regenerate_id(true);
    $_SESSION['team']    = $row['name'];
    $_SESSION['team_id'] = (int)$row['id'];
    return true;
}

function try_admin_login(string $password): bool {
    $bucket = '__admin_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (login_is_locked($bucket)) return false;

    $hash = setting('admin_password_hash');
    if (!$hash || !password_verify($password, $hash)) {
        login_register_failure($bucket);
        return false;
    }

    login_clear($bucket);
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    return true;
}
