<?php
require __DIR__ . '/../_lib/admin_auth.php';
require_admin();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') ok(['messages' => $pdo->query('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 300')->fetchAll()]);
method('POST');
$in = input(); $id = (int)($in['id'] ?? 0);
switch (str_in($in, 'action', 20)) {
    case 'read': $pdo->prepare('UPDATE contact_messages SET is_read=? WHERE id=?')->execute([empty($in['unread']) ? 1 : 0, $id]); ok();
    case 'delete': $pdo->prepare('DELETE FROM contact_messages WHERE id=?')->execute([$id]); audit(admin_name(), 'mensaje_eliminado', 'message', $id); ok();
}
fail('Acción no válida');
