<?php
/**
 * Instalador: crea el primer administrador. Se bloquea solo (config/install.lock) y trata de borrarse.
 * Web: abre https://tudominio/tienda/install.php   |   CLI: php install.php usuario correo [--temp]
 */
declare(strict_types=1);
require __DIR__ . '/api/_lib/config_loader.php';
date_default_timezone_set(tienda_env('APP_TIMEZONE', 'America/Mexico_City'));
$lock = __DIR__ . '/config/install.lock';
$cli = PHP_SAPI === 'cli';
function out(string $m): void { global $cli; echo $cli ? $m . "\n" : $m; }
function deny(int $code, string $msg): never { global $cli; if (!$cli) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); } exit($msg . ($cli ? "\n" : '')); }
if (is_file($lock)) deny(403, 'Instalación ya realizada. Elimina install.php del servidor.');
$cfg = tienda_load_config();
if ($cfg === null) deny(503, 'Falta config/config.php o las variables de entorno MYSQL* (ver docs/RAILWAY.md).');
$envMode = ($cfg['_source'] ?? '') === 'env';

// --- Protección web por token (obligatoria en modo variables de entorno / Railway) ---
if (!$cli) {
    $token = (string)(tienda_env('INSTALL_TOKEN') ?? '');
    if ($token === '' && $envMode) deny(403, 'Instalador deshabilitado. Define la variable INSTALL_TOKEN (32+ caracteres) para usarlo, o usa ADMIN_USER/ADMIN_PASSWORD.');
    if ($token !== '') {
        if (strlen($token) < 32) deny(403, 'INSTALL_TOKEN debe tener al menos 32 caracteres.');
        session_name('TIENDAINST');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => tienda_is_https(), 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        $given = (string)($_POST['install_token'] ?? $_GET['token'] ?? '');
        if ($given !== '' && hash_equals($token, $given)) { session_regenerate_id(true); $_SESSION['inst_ok'] = hash('sha256', $token); }
        if (!isset($_SESSION['inst_ok']) || !hash_equals(hash('sha256', $token), (string)$_SESSION['inst_ok'])) {
            usleep(500000);
            deny(403, 'Acceso denegado: falta o es incorrecto el token de instalación (?token=...).');
        }
    }
}
try { $pdo = tienda_pdo($cfg['db']); }
catch (Throwable $e) { deny(503, 'No se pudo conectar a MySQL. Revisa la configuración de la base de datos.'); }
$tables = $pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
if (!$tables) deny(503, 'No existen las tablas. Importa sql/schema.sql (o usa AUTO_MIGRATE=1) primero.');
// Bloqueo persistente en la BD (sobrevive a redeploys aunque config/ sea efímero)
$dbLocked = (string)($pdo->query("SELECT svalue FROM settings WHERE skey='install_locked'")->fetchColumn() ?: '') !== '';
if ($dbLocked || (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
    @file_put_contents($lock, date('c'));
    $pdo->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('install_locked', NOW())");
    deny(403, 'Ya existe un administrador. Instalador bloqueado.');
}
$appKey = (string)($cfg['app_key'] ?? '');
if (str_contains($appKey, 'CAMBIA_ESTA') || strlen($appKey) < 32) deny(503, $envMode ? 'Define la variable APP_KEY (32+ caracteres aleatorios) antes de instalar.' : 'Edita app_key en config/config.php con una clave aleatoria antes de instalar.');

function finish(PDO $pdo, string $user, string $email, string $pass, bool $temp): void {
    global $lock;
    $pdo->prepare('INSERT INTO admins (username, email, password_hash, must_change_password) VALUES (?,?,?,?)')->execute([$user, $email ?: null, password_hash($pass, PASSWORD_DEFAULT), $temp ? 1 : 0]);
    $pdo->prepare("INSERT INTO audit_log (actor, action, entity) VALUES ('instalador','admin_creado','admin')")->execute();
    $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES ('install_locked', ?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)")->execute([date('c')]);
    @file_put_contents($lock, date('c'));
    @unlink(__FILE__);
}

if ($cli && in_array('--from-env', $argv, true)) {
    // Uso en Docker/Railway: crea el admin desde ADMIN_USER / ADMIN_PASSWORD (un solo uso). No imprime la contraseña.
    $user = (string)tienda_env('ADMIN_USER', ''); $pass = (string)tienda_env('ADMIN_PASSWORD', ''); $email = (string)tienda_env('ADMIN_EMAIL', '');
    if ($user === '' || $pass === '') exit("ADMIN_USER/ADMIN_PASSWORD no definidas; no se crea admin.\n");
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $user)) exit("ADMIN_USER inválido\n");
    if (strlen($pass) < 10 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) exit("ADMIN_PASSWORD débil (mín. 10, letras y números)\n");
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    // must_change_password=1: el panel obliga a cambiarla en el primer acceso, así la variable deja de ser válida.
    finish($pdo, $user, $email, $pass, true);
    out("Admin '$user' creado desde variables de entorno (se pedirá cambiar la contraseña). BORRA ADMIN_PASSWORD de Railway.");
    exit;
}
if ($cli) {
    $user = $argv[1] ?? 'admin'; $email = $argv[2] ?? ''; $temp = in_array('--temp', $argv, true);
    $pass = $temp ? substr(strtr(base64_encode(random_bytes(18)), '+/=', 'xyz'), 0, 20) . '9a' : (function () { echo 'Contraseña (mín 10, letras y números): '; return trim((string)fgets(STDIN)); })();
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $user)) exit("Usuario inválido\n");
    if (strlen($pass) < 10 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) exit("Contraseña débil\n");
    if ($temp && $envMode) {
        // Contenedor: el disco es efímero; se muestra UNA sola vez en esta terminal (no se guarda en archivo).
        out("Admin '$user' creado. Contraseña temporal (se muestra una sola vez, cámbiala al entrar): $pass");
    } elseif ($temp) {
        $f = __DIR__ . '/config/ADMIN_PASSWORD_TEMPORAL.txt';
        file_put_contents($f, "Usuario: $user\nContraseña temporal: $pass\nSe te pedirá cambiarla al primer inicio de sesión. BORRA ESTE ARCHIVO después.\n");
        @chmod($f, 0600);
        out("Admin '$user' creado con contraseña temporal guardada en config/ADMIN_PASSWORD_TEMPORAL.txt (protegido por .htaccess).");
    } else out("Admin '$user' creado.");
    finish($pdo, $user, $email, $pass, $temp);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('X-Frame-Options: DENY'); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
if (empty($_SESSION['inst_csrf'])) $_SESSION['inst_csrf'] = bin2hex(random_bytes(16));
$err = ''; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['user'] ?? '')); $email = trim((string)($_POST['email'] ?? '')); $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
    if (!hash_equals($_SESSION['inst_csrf'], (string)($_POST['csrf'] ?? ''))) $err = 'Token inválido, recarga.';
    elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $user)) $err = 'Usuario inválido (3-60 letras, números, _ . -)';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Correo inválido';
    elseif (strlen($p1) < 10 || !preg_match('/[A-Za-z]/', $p1) || !preg_match('/\d/', $p1)) $err = 'La contraseña debe tener al menos 10 caracteres con letras y números';
    elseif ($p1 !== $p2) $err = 'Las contraseñas no coinciden';
    else { finish($pdo, $user, $email, $p1, false); $done = true; }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalación</title><meta name="robots" content="noindex">
<style>body{font-family:system-ui,sans-serif;background:#eef3fb;display:grid;place-items:center;min-height:100vh;margin:0}form,.box{background:#fff;padding:2rem;border-radius:14px;box-shadow:0 10px 30px #0a4ea322;width:min(420px,92vw)}h1{color:#0A4EA3;font-size:1.4rem}label{display:block;margin:.8rem 0 .3rem;font-weight:600}input{width:100%;padding:.7rem;border:1px solid #c9d6ea;border-radius:8px;box-sizing:border-box}button{margin-top:1.2rem;width:100%;padding:.8rem;background:#0A4EA3;color:#fff;border:0;border-radius:8px;font-weight:700;cursor:pointer}.err{background:#fde8e8;color:#a40000;padding:.6rem;border-radius:8px}</style></head><body>
<?php if ($done): ?><div class="box"><h1>✅ Administrador creado</h1><p>El instalador se bloqueó<?= is_file(__FILE__) ? ' pero <b>no pudo borrarse: elimina install.php por FTP</b>' : ' y se eliminó' ?>.</p><p><a href="admin/">Ir al panel</a></p></div>
<?php else: ?><form method="post"><h1>Crear administrador</h1><?php if ($err): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>
<input type="hidden" name="csrf" value="<?= $h($_SESSION['inst_csrf']) ?>">
<label for="user">Usuario</label><input id="user" name="user" required minlength="3" value="<?= $h($_POST['user'] ?? 'admin') ?>">
<label for="email">Correo (opcional)</label><input id="email" name="email" type="email" value="<?= $h($_POST['email'] ?? '') ?>">
<label for="p1">Contraseña (mín. 10, letras y números)</label><input id="p1" name="p1" type="password" required minlength="10" autocomplete="new-password">
<label for="p2">Repite la contraseña</label><input id="p2" name="p2" type="password" required minlength="10" autocomplete="new-password">
<button>Crear administrador</button></form><?php endif; ?></body></html>
