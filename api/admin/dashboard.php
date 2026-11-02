<?php
require __DIR__ . '/../_lib/admin_auth.php';
method('GET');
require_admin();
$pdo = db();
$paid = "'" . implode("','", PAID_STATUSES) . "'";
$days = min(90, max(7, (int)($_GET['days'] ?? 30)));
$k = $pdo->query("SELECT COALESCE(SUM(total),0) revenue, COUNT(*) orders, COALESCE(AVG(total),0) avg_ticket FROM orders WHERE status IN ($paid)")->fetch();
$k30 = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ($paid) AND paid_at >= (CURDATE() - INTERVAL ? DAY)"); $k30->execute([$days]);
$today = $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ($paid) AND DATE(paid_at)=CURDATE()")->fetchColumn();
$byStatus = $pdo->query('SELECT status, COUNT(*) n FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $pdo->prepare("SELECT DATE(paid_at) d, SUM(total) total, COUNT(*) n FROM orders WHERE status IN ($paid) AND paid_at >= (CURDATE() - INTERVAL ? DAY) GROUP BY DATE(paid_at) ORDER BY d");
$st->execute([$days - 1]);
$map = []; foreach ($st->fetchAll() as $r) $map[$r['d']] = $r;
$series = [];
for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $series[] = ['date' => $d, 'total' => (float)($map[$d]['total'] ?? 0), 'orders' => (int)($map[$d]['n'] ?? 0)]; }
$top = $pdo->query("SELECT oi.product_name name, SUM(oi.quantity) qty, SUM(oi.line_total) total FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status IN ($paid) GROUP BY oi.product_name ORDER BY total DESC LIMIT 8")->fetchAll();
$th = (int)setting('low_stock_threshold', '5');
$low = $pdo->prepare('SELECT * FROM (SELECT p.id, p.name, p.type, ' . stock_sql() . ' stock FROM products p WHERE p.active=1) t WHERE stock <= ? ORDER BY stock ASC, name LIMIT 20');
$low->execute([$th]);
$pendingEsim = $pdo->query("SELECT COUNT(*) FROM orders WHERE has_esim=1 AND esim_delivered=0 AND status IN ($paid)")->fetchColumn();
$recent = $pdo->query('SELECT id, order_number, customer_name, total, status, created_at FROM orders ORDER BY id DESC LIMIT 8')->fetchAll();
$unread = $pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read=0')->fetchColumn();
ok(['kpis' => ['revenue' => (float)$k['revenue'], 'orders_paid' => (int)$k['orders'], 'avg_ticket' => round((float)$k['avg_ticket'], 2), 'revenue_period' => (float)$k30->fetchColumn(), 'revenue_today' => (float)$today,
    'pending_payment' => (int)($byStatus['pendiente_pago'] ?? 0), 'pending_esim' => (int)$pendingEsim, 'unread_messages' => (int)$unread, 'customers' => (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn()],
    'by_status' => $byStatus, 'series' => $series, 'top_products' => $top, 'low_stock' => $low->fetchAll(), 'recent' => $recent, 'days' => $days]);
