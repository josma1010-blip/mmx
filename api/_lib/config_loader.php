<?php
declare(strict_types=1);
/**
 * Carga la configuración de la tienda.
 *  1) Si existe config/config.php se usa (instalación tradicional cPanel).
 *  2) Si no, se construye desde variables de entorno (Railway / Docker):
 *     MYSQL_URL  o  MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE
 *     APP_URL, APP_KEY, COOKIE_SECURE, DEV_SIMULATED_PAYMENT, APP_DEBUG, APP_TIMEZONE, MAX_UPLOAD_BYTES
 * Devuelve null si no hay ninguna configuración.
 */
function tienda_env(string $k, ?string $default = null): ?string {
    $v = getenv($k);
    if ($v === false) $v = $_SERVER[$k] ?? $_ENV[$k] ?? null;
    return ($v === null || $v === '') ? $default : (string)$v;
}
function tienda_env_bool(string $k, ?bool $default = null): ?bool {
    $v = tienda_env($k);
    if ($v === null) return $default;
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
}
function tienda_load_config(): ?array {
    $file = dirname(__DIR__, 2) . '/config/config.php';
    if (is_file($file)) { $c = require $file; $c['_source'] = 'file'; return $c; }

    $db = null;
    $url = tienda_env('MYSQL_URL') ?? tienda_env('DATABASE_URL');
    if ($url && ($p = parse_url($url)) && !empty($p['host'])) {
        $db = ['host' => $p['host'], 'port' => (int)($p['port'] ?? 3306), 'name' => ltrim((string)($p['path'] ?? ''), '/'),
               'user' => rawurldecode((string)($p['user'] ?? '')), 'pass' => rawurldecode((string)($p['pass'] ?? ''))];
    }
    if (!$db && tienda_env('MYSQLHOST')) {
        $db = ['host' => tienda_env('MYSQLHOST'), 'port' => (int)tienda_env('MYSQLPORT', '3306'),
               'name' => tienda_env('MYSQLDATABASE', tienda_env('MYSQL_DATABASE', 'railway')),
               'user' => tienda_env('MYSQLUSER', 'root'), 'pass' => tienda_env('MYSQLPASSWORD', tienda_env('MYSQL_ROOT_PASSWORD', ''))];
    }
    if (!$db) return null;
    $secure = tienda_env('COOKIE_SECURE');
    return [
        '_source' => 'env',
        'db' => $db,
        'base_url' => rtrim((string)tienda_env('APP_URL', ''), '/'),
        'cookie_secure' => $secure === null ? null : tienda_env_bool('COOKIE_SECURE'),
        'app_key' => (string)tienda_env('APP_KEY', ''),
        'dev_simulated_payment' => (bool)tienda_env_bool('DEV_SIMULATED_PAYMENT', false),
        'debug' => (bool)tienda_env_bool('APP_DEBUG', false),
        'timezone' => tienda_env('APP_TIMEZONE', 'America/Mexico_City'),
        'max_upload_bytes' => (int)tienda_env('MAX_UPLOAD_BYTES', (string)(3 * 1024 * 1024)),
        'trust_proxy' => (bool)tienda_env_bool('TRUST_PROXY', true),
    ];
}
function tienda_pdo(array $c): PDO {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
    return new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}
/** ¿Petición HTTPS? Respeta X-Forwarded-Proto del proxy (Railway termina TLS en su borde). */
function tienda_is_https(): bool {
    $xfp = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $xfp === 'https'
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}
