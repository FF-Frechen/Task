<?php
/**
 * Gemeinsamer Einstieg für alle Seiten: Konfiguration, Sicherheits-Header,
 * Session, Datenbank und kleine Hilfsfunktionen.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Task benötigt PHP 8.1 oder neuer (in Plesk unter "PHP-Einstellungen" umstellen).');
}

// Dateien in src/ sind keine eigenen Seiten.
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('APP_DIR', dirname(__DIR__));
const STATUSES = ['idea', 'doing', 'done'];
const COLORS = [
    'red'    => 'Rot',
    'orange' => 'Orange',
    'yellow' => 'Gelb',
    'green'  => 'Grün',
    'teal'   => 'Türkis',
    'blue'   => 'Blau',
    'purple' => 'Lila',
    'pink'   => 'Pink',
    'gray'   => 'Grau',
];
const MIN_PASSWORD_LENGTH = 10;

/* ------------------------------------------------------------------ */
/* Konfiguration                                                       */
/* ------------------------------------------------------------------ */

function cfg(string $key): mixed
{
    static $config = null;
    if ($config === null) {
        $config = [
            'app_name'       => 'Task',
            'data_dir'       => null,          // null = automatisch (siehe data_dir())
            'force_https'    => true,
            'session_idle'   => 12 * 3600,   // ohne "angemeldet bleiben": 12 h Inaktivität
            'remember_days'  => 30,          // mit "angemeldet bleiben"
            'max_fails_user' => 5,           // Fehlversuche pro Benutzername ...
            'max_fails_ip'   => 20,          // ... bzw. pro IP-Adresse ...
            'lock_minutes'   => 15,          // ... innerhalb dieser Minuten => Sperre
        ];
        $local = APP_DIR . '/config.local.php';
        if (is_file($local)) {
            $override = require $local;
            if (is_array($override)) {
                $config = array_replace($config, $override);
            }
        }
    }
    return $config[$key] ?? null;
}

/* ------------------------------------------------------------------ */
/* HTTPS & Sicherheits-Header                                          */
/* ------------------------------------------------------------------ */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function is_local_request(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function enforce_https(): void
{
    if (!cfg('force_https') || is_https() || is_local_request() || PHP_SAPI === 'cli') {
        return;
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $host)) {
        http_response_code(400);
        exit('Bitte über HTTPS aufrufen.');
    }
    $host = preg_replace('/:\d+$/', '', $host);
    header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

/* ------------------------------------------------------------------ */
/* Datenbank (SQLite)                                                  */
/* ------------------------------------------------------------------ */

/**
 * Ordner für Datenbank, Sitzungen und Einrichtungscode.
 *
 * 1. "data_dir" aus config.local.php, falls gesetzt.
 * 2. Sonst "task-data" EINE EBENE ÜBER dem App-Ordner, also neben httpdocs.
 *    Dort ist er aus dem Internet grundsätzlich nicht erreichbar.
 * 3. Notlösung, falls PHP dort nicht schreiben darf: Unterordner "data-<zufall>"
 *    im App-Ordner, mit .htaccess gesperrt und nicht erratbarem Namen.
 *
 * Die Reihenfolge der Prüfungen sorgt dafür, dass ein einmal gewählter Ort
 * immer wieder gefunden wird.
 */
function data_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = resolve_data_dir();
        if (!is_writable($dir)) {
            fatal('Der Datenordner ist nicht beschreibbar. Bitte Schreibrechte prüfen.');
        }
    }
    return $dir;
}

function resolve_data_dir(): string
{
    $configured = cfg('data_dir');
    if (is_string($configured) && $configured !== '') {
        $configured = rtrim($configured, '/');
        if (!@is_dir($configured) && !@mkdir($configured, 0770, true) && !@is_dir($configured)) {
            fatal('Der in config.local.php eingestellte Datenordner kann nicht angelegt werden.');
        }
        return $configured;
    }

    $outside = outside_data_dir();
    if (@is_dir($outside)) {
        return $outside;
    }
    $fallback = (glob(APP_DIR . '/data-*', GLOB_ONLYDIR) ?: [])[0] ?? null;

    // Darf PHP außerhalb schreiben (z. B. nach Änderung von open_basedir in Plesk),
    // ziehen vorhandene Daten automatisch dorthin um.
    if (@mkdir($outside, 0770)) {
        if (move_data($fallback ?? APP_DIR . '/data', $outside)) {
            if ($fallback !== null) {
                remove_dir($fallback);
            }
            return $outside;
        }
        @rmdir($outside); // Umzug fehlgeschlagen: Daten bleiben, wo sie sind.
    }
    if ($fallback !== null) {
        return $fallback;
    }

    $dir = APP_DIR . '/data-' . bin2hex(random_bytes(16));
    if (!@mkdir($dir, 0770)) {
        fatal('Es kann kein Datenordner angelegt werden. Bitte Schreibrechte prüfen '
            . 'oder in config.local.php einen Ordner angeben (siehe config.example.php).');
    }
    file_put_contents($dir . '/.htaccess', "Require all denied\n");
    file_put_contents($dir . '/index.html', '');
    move_data(APP_DIR . '/data', $dir);
    return $dir;
}

function outside_data_dir(): string
{
    return dirname(APP_DIR) . '/task-data';
}

/**
 * Verschiebt Datenbank und Einrichtungscode (z. B. aus data/ der ersten Version
 * oder aus der Notlösung). false, wenn die Datenbank nicht verschoben werden konnte.
 */
function move_data(string $from, string $to): bool
{
    if (is_file($from . '/task.sqlite') && !@rename($from . '/task.sqlite', $to . '/task.sqlite')) {
        return false;
    }
    foreach (['task.sqlite-wal', 'task.sqlite-shm', 'SETUP-CODE'] as $file) {
        if (is_file($from . '/' . $file)) {
            @rename($from . '/' . $file, $to . '/' . $file);
        }
    }
    return true;
}

function remove_dir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            remove_dir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** Wo findet man die Datei SETUP-CODE? (Text für die Ersteinrichtung) */
function data_location_hint(): string
{
    if (cfg('data_dir')) {
        return 'im Datenordner, der in config.local.php eingestellt ist';
    }
    if (data_dir() === outside_data_dir()) {
        return 'im Ordner „task-data“, eine Ebene über dem App-Ordner, also direkt neben „httpdocs“ '
            . '(Plesk → Dateien → ganz oben in der Ordnerliste)';
    }
    return 'im App-Ordner (z. B. „httpdocs“) im Unterordner, dessen Name mit „data-“ beginnt';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite')) {
        fatal('Die PHP-Erweiterung "pdo_sqlite" fehlt (in Plesk unter "PHP-Einstellungen" aktivieren).');
    }
    $pdo = new PDO('sqlite:' . data_dir() . '/task.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        $pdo->beginTransaction();
        $pdo->exec(<<<SQL
            CREATE TABLE users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT    NOT NULL,
                color         TEXT    NOT NULL DEFAULT 'blue',
                is_admin      INTEGER NOT NULL DEFAULT 0,
                created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE tasks (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                title       TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                status      TEXT    NOT NULL DEFAULT 'idea' CHECK (status IN ('idea','doing','done')),
                color       TEXT,
                position    INTEGER NOT NULL DEFAULT 0,
                created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                done_at     TEXT
            );
            CREATE INDEX idx_tasks_status ON tasks(status, position);
            CREATE TABLE task_users (
                task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                PRIMARY KEY (task_id, user_id)
            );
            CREATE TABLE login_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ip           TEXT    NOT NULL,
                username     TEXT    NOT NULL,
                attempted_at INTEGER NOT NULL
            );
            CREATE INDEX idx_login_attempts_time ON login_attempts(attempted_at);
            PRAGMA user_version = 1;
            SQL);
        $pdo->commit();
    }
}

function user_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

/* ------------------------------------------------------------------ */
/* Session & Anmeldung                                                 */
/* ------------------------------------------------------------------ */

function session_cookie_options(int $expires = 0): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function start_session(): void
{
    $dir = data_dir() . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    session_save_path($dir);
    session_name(is_https() ? '__Host-task' : 'task_session');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string)((int)cfg('remember_days') * 86400));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    $opts = session_cookie_options();
    unset($opts['expires']);
    $opts['lifetime'] = 0;
    session_set_cookie_params($opts);
    session_start();

    if (!empty($_SESSION['uid'])) {
        $now = time();
        $remember = !empty($_SESSION['remember']);
        $limit = $remember ? (int)cfg('remember_days') * 86400 : (int)cfg('session_idle');
        if ($now - (int)($_SESSION['last_seen'] ?? 0) > $limit) {
            end_session();
            session_start();
            return;
        }
        $_SESSION['last_seen'] = $now;
        // Bei "angemeldet bleiben" das Cookie einmal täglich verlängern.
        if ($remember && $now - (int)($_SESSION['cookie_refreshed'] ?? 0) > 86400) {
            setcookie(session_name(), session_id(), session_cookie_options($now + $limit));
            $_SESSION['cookie_refreshed'] = $now;
        }
    }
}

function end_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        setcookie(session_name(), '', session_cookie_options(time() - 3600));
        session_destroy();
    }
}

/** Fingerabdruck des Passwort-Hashes: ändert sich das Passwort, fliegen andere Sessions raus. */
function password_fingerprint(string $hash): string
{
    return hash('sha256', $hash);
}

function login_user(array $user, bool $remember): void
{
    session_regenerate_id(true);
    $now = time();
    $_SESSION = [
        'uid'       => (int)$user['id'],
        'pw_fp'     => password_fingerprint($user['password_hash']),
        'last_seen' => $now,
        'remember'  => $remember,
        'csrf'      => bin2hex(random_bytes(32)),
    ];
    if ($remember) {
        setcookie(session_name(), session_id(),
            session_cookie_options($now + (int)cfg('remember_days') * 86400));
        $_SESSION['cookie_refreshed'] = $now;
    }
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, password_hash, color, is_admin FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['uid']]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string)($_SESSION['pw_fp'] ?? ''), password_fingerprint($row['password_hash']))) {
        end_session();
        session_start();
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['is_admin'] = (bool)$row['is_admin'];
    $user = $row;
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect(user_count() === 0 ? 'setup.php' : 'login.php');
    }
    return $user;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function login_is_blocked(string $username): bool
{
    $pdo = db();
    $since = time() - (int)cfg('lock_minutes') * 60;
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - 86400]);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at >= ?');
    $stmt->execute([mb_lower($username), $since]);
    if ((int)$stmt->fetchColumn() >= (int)cfg('max_fails_user')) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
    $stmt->execute([client_ip(), $since]);
    return (int)$stmt->fetchColumn() >= (int)cfg('max_fails_ip');
}

function record_login_failure(string $username): void
{
    db()->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)')
        ->execute([client_ip(), mb_lower($username), time()]);
}

function clear_login_failures(string $username): void
{
    db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([mb_lower($username)]);
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(mixed $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function require_csrf_post(): void
{
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Das Formular ist abgelaufen. Bitte Seite neu laden und erneut versuchen.');
    }
}

/* ------------------------------------------------------------------ */
/* Validierung                                                         */
/* ------------------------------------------------------------------ */

function mb_lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function str_length(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function valid_username(string $name): bool
{
    return (bool)preg_match('/^[\p{L}\p{N}._-]{2,32}$/u', $name);
}

function password_problem(string $password, string $repeat): ?string
{
    if (str_length($password) < MIN_PASSWORD_LENGTH) {
        return 'Das Passwort muss mindestens ' . MIN_PASSWORD_LENGTH . ' Zeichen lang sein.';
    }
    if (strlen($password) > 1024) {
        return 'Das Passwort ist zu lang.';
    }
    if ($password !== $repeat) {
        return 'Die beiden Passwörter stimmen nicht überein.';
    }
    return null;
}

function valid_color(mixed $color): bool
{
    return is_string($color) && array_key_exists($color, COLORS);
}

/* ------------------------------------------------------------------ */
/* Ausgabe                                                             */
/* ------------------------------------------------------------------ */

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function fatal(string $message): never
{
    error_log('[task] ' . $message);
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>'
        . '<p style="font-family:sans-serif;max-width:40rem;margin:3rem auto">' . e($message) . '</p>';
    exit;
}

/** Hinweis für Admins, wenn die Daten nur per Notlösung im Web-Ordner liegen. */
function data_warning(): ?string
{
    if (cfg('data_dir') || data_dir() === outside_data_dir()) {
        return null;
    }
    return 'Die Daten liegen in httpdocs in einem Ordner mit Zufallsnamen, gesperrt per .htaccess, '
        . 'weil PHP außerhalb von httpdocs nicht schreiben darf (open_basedir). Das ist sicher. '
        . 'Wer sie ganz aus dem Web-Ordner haben möchte: In Plesk → PHP-Einstellungen bei open_basedir '
        . '„{WEBSPACEROOT}{/}{:}{TMP}{/}{:}/var/lib/php/sessions“ wählen. Beim nächsten Aufruf ziehen '
        . 'die Daten dann automatisch in den Ordner „task-data“ neben httpdocs um.';
}

/* ------------------------------------------------------------------ */
/* Start                                                               */
/* ------------------------------------------------------------------ */

if (PHP_SAPI !== 'cli') {
    enforce_https();
    send_security_headers();
    start_session();
}
