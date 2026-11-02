<?php
require __DIR__ . '/../_lib/admin_auth.php';
method('GET');
require_admin();
$pdo = db();
$paid = "'" . implode("','", PAID_STATUSES) . "'";
$q = trim((string)($_GET['q'] ?? ''));
$args = []; $w = '';
if ($q !== '') { $w = 'WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?'; $l = '%' . addcslashes($q, '%_\\') . '%'; $args = [$l, $l, $l]; }
$st = $pdo->prepare("SELECT c.id, c.name, c.email, c.phone, c.city, c.state, c.created_at,
    (SELECT COUNT(*) FROM orders o WHERE o.customer_id=c.id) orders,
    (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id=c.id AND o.status IN ($paid)) spent
    FROM customers c $w ORDER BY c.id DESC LIMIT 200");
$st->execute($args);
ok(['customers' => $st->fetchAll()]);
