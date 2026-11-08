<?php
declare(strict_types=1);

const STATUS_LABELS = [
    'new'       => 'Neu',
    'queued'    => 'Warteschlange',
    'paused'    => 'Pausiert / Delay',
    'rejected'  => 'Abgelehnt',
    'available' => 'Verfügbar',
];

// cron startet Prozesse ohne die Container-Umgebung – der Entrypoint legt sie hier ab
if (PHP_SAPI === 'cli' && is_readable('/etc/wishvault/env.json')) {
    foreach ((array)json_decode((string)file_get_contents('/etc/wishvault/env.json'), true) as $k => $v) {
        if (is_string($k) && is_string($v) && getenv($k) === false) {
            putenv($k . '=' . $v);
        }
    }
}

$GLOBALS['config'] = require __DIR__ . '/config.php';
date_default_timezone_set(cfg('timezone', 'Europe/Berlin'));

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/lib/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/** Konfigurationswert per Punkt-Pfad lesen, z. B. cfg('smtp.host') */
function cfg(string $path, mixed $default = null): mixed
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = cfg('data_dir');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $dir . '/app.db', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        Schema::migrate($pdo);
    }
    return $pdo;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

/** Titel für Abgleich normalisieren: Kleinbuchstaben, nur Buchstaben/Ziffern */
function normalize_title(string $title): string
{
    $t = mb_strtolower($title, 'UTF-8');
    $t = str_replace(['&'], ['and'], $t);
    $t = preg_replace('/[™®©]/u', '', $t);
    if (class_exists('Transliterator')) {
        $tr = Transliterator::create('Any-Latin; Latin-ASCII');
        if ($tr) {
            $t = $tr->transliterate($t);
        }
    }
    return preg_replace('/[^a-z0-9]+/', '', $t) ?? '';
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('wl_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(bool $json = false): void
{
    start_session();
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        if ($json) {
            json_out(['result' => 'error', 'message' => 'Sitzung abgelaufen – bitte Seite neu laden.'], 400);
        }
        http_response_code(400);
        exit('Ungültige Anfrage (CSRF). Bitte Seite neu laden.');
    }
}

function flash(?string $type = null, ?string $msg = null): array
{
    start_session();
    if ($type !== null) {
        $_SESSION['flash'][] = [$type, $msg];
        return [];
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function steam_cover(int $appid): string
{
    return "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appid}/library_600x900.jpg";
}

function steam_header(int $appid): string
{
    return "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$appid}/header.jpg";
}

/** URL eines (gecachten) GameVault-Bildes */
function gv_image(?int $mediaId): ?string
{
    return $mediaId ? '/img.php?m=' . $mediaId : null;
}

function fmt_date(?string $dt): string
{
    if (!$dt) {
        return '–';
    }
    $ts = is_numeric($dt) ? (int)$dt : strtotime($dt);
    return $ts ? date('d.m.Y H:i', $ts) : '–';
}

function log_msg(string $msg): void
{
    @file_put_contents(cfg('data_dir') . '/app.log', '[' . now() . '] ' . $msg . "\n", FILE_APPEND);
}

require __DIR__ . '/views/layout.php';
