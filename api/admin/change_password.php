<?php
require __DIR__ . '/../_lib/admin_auth.php';
method('POST');
$a = require_admin(true);
$in = input();
$cur = (string)($in['current'] ?? ''); $new = (string)($in['new'] ?? '');
$st = db()->prepare('SELECT password_hash FROM admins WHERE id=?'); $st->execute([$a['id']]);
if (!password_verify($cur, (string)$st->fetchColumn())) fail('La contraseña actual no es correcta', 422);
if (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) fail('La nueva contraseña debe tener al menos 10 caracteres, letras y números', 422);
if (hash_equals($cur, $new)) fail('La nueva contraseña debe ser distinta', 422);
db()->prepare('UPDATE admins SET password_hash=?, must_change_password=0 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $a['id']]);
session_regenerate_id(true);
audit(admin_name(), 'cambio_password', 'admin', $a['id']);
ok();
