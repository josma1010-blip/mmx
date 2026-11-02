<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/orders.php';

function require_admin(bool $allowMustChange = false): array {
    start_session();
    if (empty($_SESSION['admin_id'])) fail('No autorizado', 401);
    if (time() - ($_SESSION['admin_last'] ?? 0) > 7200) { session_destroy(); fail('Sesión expirada', 401); }
    $_SESSION['admin_last'] = time();
    $st = db()->prepare('SELECT id, username, email, must_change_password FROM admins WHERE id=?');
    $st->execute([$_SESSION['admin_id']]);
    $a = $st->fetch();
    if (!$a) fail('No autorizado', 401);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') csrf_check();
    if ($a['must_change_password'] && !$allowMustChange) fail('Debes cambiar tu contraseña antes de continuar', 403, ['must_change_password' => true]);
    return $a;
}
function admin_name(): string { return 'admin:' . ($_SESSION['admin_user'] ?? '?'); }
