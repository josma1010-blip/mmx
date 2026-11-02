<?php
require __DIR__ . '/../_lib/admin_auth.php';
method('POST');
start_session();
csrf_check();
rate_limit('admin_login', 10, 900, null, true);
$in = input();
$u = str_in($in, 'username', 60); $p = (string)($in['password'] ?? '');
$st = db()->prepare('SELECT * FROM admins WHERE username=?'); $st->execute([$u]);
$a = $st->fetch();
if ($a && $a['locked_until'] && strtotime($a['locked_until']) > time()) {
    record_attempt('admin_login', $u, false);
    fail('Cuenta bloqueada temporalmente por intentos fallidos. Intenta más tarde.', 423);
}
if (!$a || !password_verify($p, $a['password_hash'])) {
    record_attempt('admin_login', $u, false);
    if ($a) {
        $fails = (int)$a['failed_attempts'] + 1;
        $lock = $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        db()->prepare('UPDATE admins SET failed_attempts=?, locked_until=? WHERE id=?')->execute([$lock ? 0 : $fails, $lock, $a['id']]);
        if ($lock) audit('sistema', 'admin_bloqueado', 'admin', $a['id']);
    }
    usleep(300000);
    fail('Usuario o contraseña incorrectos', 401);
}
record_attempt('admin_login', $u, true);
if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($p, PASSWORD_DEFAULT), $a['id']]);
db()->prepare('UPDATE admins SET failed_attempts=0, locked_until=NULL, last_login=NOW() WHERE id=?')->execute([$a['id']]);
session_regenerate_id(true);
$_SESSION['admin_id'] = (int)$a['id']; $_SESSION['admin_user'] = $a['username']; $_SESSION['admin_last'] = time();
$_SESSION['csrf'] = bin2hex(random_bytes(32));
audit('admin:' . $a['username'], 'login');
ok(['csrf' => $_SESSION['csrf'], 'must_change_password' => (bool)$a['must_change_password'], 'username' => $a['username']]);
