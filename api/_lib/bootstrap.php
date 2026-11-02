<?php
declare(strict_types=1);

require_once __DIR__ . '/config_loader.php';
$GLOBALS['CFG'] = tienda_load_config();
if ($GLOBALS['CFG'] === null) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Tienda no configurada: crea config/config.php o define las variables de entorno MYSQL* (ver docs/RAILWAY.md)']);
    exit;
}
if (($GLOBALS['CFG']['_source'] ?? '') === 'env' && (strlen((string)$GLOBALS['CFG']['app_key']) < 32)) {
    error_log('[tienda] APP_KEY no definida o demasiado corta (mínimo 32 caracteres).');
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Falta la variable APP_KEY (32+ caracteres)']);
    exit;
}
date_default_timezone_set($GLOBALS['CFG']['timezone'] ?? 'America/Mexico_City');
if (!empty($GLOBALS['CFG']['debug'])) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
else { ini_set('display_errors', '0'); }

function cfg(string $k, $default = null) { return $GLOBALS['CFG'][$k] ?? $default; }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = tienda_pdo(cfg('db'));
    $off = (new DateTime())->format('P');
    $pdo->exec("SET time_zone = '$off'");
    return $pdo;
}

function is_https(): bool { return tienda_is_https(); }

function send_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Cache-Control: no-store');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = cfg('cookie_secure');
    if ($secure === null) $secure = is_https();
    session_name('TIENDASESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => (bool)$secure, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ok(array $data = []): never { json_out(['ok' => true] + $data); }
function fail(string $msg, int $code = 400, array $extra = []): never { json_out(['ok' => false, 'error' => $msg] + $extra, $code); }

function input(): array {
    static $in = null;
    if ($in !== null) return $in;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $in = json_decode($raw ?: '[]', true);
        if (!is_array($in)) fail('JSON inválido');
    } else { $in = $_POST; }
    return $in;
}
function str_in(array $a, string $k, int $max = 255): string {
    $v = isset($a[$k]) && is_scalar($a[$k]) ? trim((string)$a[$k]) : '';
    return mb_substr($v, 0, $max);
}

function method(string ...$m): void {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $m, true)) fail('Método no permitido', 405);
}

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    start_session();
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (input()['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !is_string($t) || !hash_equals($_SESSION['csrf'], $t)) fail('Token de seguridad inválido. Recarga la página.', 419);
}

function client_ip(): string {
    // Detrás del proxy de Railway REMOTE_ADDR es el proxy: usamos la última IP de X-Forwarded-For (la que añade el proxy).
    if (cfg('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']));
        $ip = end($parts);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return substr($ip, 0, 45);
    }
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}
/** Carpeta de uploads (por defecto <raíz>/uploads; configurable con UPLOADS_PATH). */
function uploads_dir(string $sub = ''): string {
    $base = rtrim((string)(tienda_env('UPLOADS_PATH') ?? (dirname(__DIR__, 2) . '/uploads')), '/');
    return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
}

/** Rate limit básico: lanza 429 si hay >= $max intentos en $windowSec */
function rate_limit(string $action, int $max, int $windowSec, ?string $identifier = null, bool $onlyFailures = false): void {
    $sql = 'SELECT COUNT(*) FROM login_attempts WHERE action=? AND ip=? AND created_at > (NOW() - INTERVAL ? SECOND)' . ($onlyFailures ? ' AND success=0' : '');
    $st = db()->prepare($sql);
    $st->execute([$action, client_ip(), $windowSec]);
    if ((int)$st->fetchColumn() >= $max) fail('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.', 429);
}
function record_attempt(string $action, ?string $identifier, bool $success): void {
    db()->prepare('INSERT INTO login_attempts (action, ip, identifier, success) VALUES (?,?,?,?)')
        ->execute([$action, client_ip(), $identifier ? mb_substr($identifier, 0, 190) : null, $success ? 1 : 0]);
    if (random_int(1, 50) === 1) db()->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 7 DAY)');
}

function settings_all(): array {
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach (db()->query('SELECT skey, svalue FROM settings') as $r) $s[$r['skey']] = $r['svalue'];
    }
    return $s;
}
function setting(string $k, string $default = ''): string { $s = settings_all(); return isset($s[$k]) ? (string)$s[$k] : $default; }
function set_setting(string $k, ?string $v): void {
    db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute([$k, $v]);
}

function encrypt_secret(string $plain): string {
    if ($plain === '') return '';
    $key = hash('sha256', (string)cfg('app_key'), true);
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $ct);
}
function decrypt_secret(string $stored): string {
    if ($stored === '' || !str_starts_with($stored, 'enc:')) return $stored;
    $raw = base64_decode(substr($stored, 4));
    $key = hash('sha256', (string)cfg('app_key'), true);
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pt === false ? '' : $pt;
}

function audit(string $actor, string $action, ?string $entity = null, $entityId = null, $details = null): void {
    db()->prepare('INSERT INTO audit_log (actor, action, entity, entity_id, details, ip) VALUES (?,?,?,?,?,?)')
        ->execute([mb_substr($actor, 0, 60), $action, $entity, $entityId !== null ? (string)$entityId : null,
            is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details, client_ip()]);
}

function base_url(): string {
    $b = rtrim((string)cfg('base_url', ''), '/');
    if ($b !== '') return $b;
    $scheme = is_https() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Envío de correo con mail() del hosting. Devuelve true/false y registra fallos. */
function send_mail(string $to, string $subject, string $body): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = setting('mail_from', 'no-reply@localhost');
    $fromName = setting('mail_from_name', setting('store_name'));
    $headers = [
        'From' => mb_encode_mimeheader($fromName, 'UTF-8') . " <$from>",
        'Reply-To' => setting('contact_email', $from),
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];
    $okm = false;
    try { $okm = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers, '-f' . $from); } catch (Throwable $e) { $okm = false; }
    if (!$okm) {
        @file_put_contents(uploads_dir('private/mail.log'), date('c') . " FALLO mail() a $to: $subject\n", FILE_APPEND);
    }
    return $okm;
}

function money(float $v): string { return '$' . number_format($v, 2, '.', ',') . ' ' . setting('currency', 'MXN'); }

set_exception_handler(function (Throwable $e) {
    error_log('[tienda] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = cfg('debug') ? $e->getMessage() : 'Error interno del servidor';
    if (!headers_sent()) json_out(['ok' => false, 'error' => $msg], 500);
});

send_security_headers();
