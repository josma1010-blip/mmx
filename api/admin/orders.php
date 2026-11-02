<?php
require __DIR__ . '/../_lib/admin_auth.php';
$a = require_admin();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $o = get_order((int)$_GET['id']); if (!$o) fail('No encontrado', 404);
        unset($o['access_token_hash'], $o['access_token_enc']);
        $o['items'] = order_items((int)$o['id']);
        $e = $pdo->prepare('SELECT id, product_id, order_item_id, iccid, activation_code, smdp_address, confirmation_code, status, reserved_at, delivered_at, (qr_image IS NOT NULL) has_qr FROM esim_codes WHERE order_id=? ORDER BY id');
        $e->execute([$o['id']]); $o['esims'] = $e->fetchAll();
        $p = $pdo->prepare('SELECT id, method, provider_ref, amount, currency, status, created_by, created_at FROM payments WHERE order_id=? ORDER BY id'); $p->execute([$o['id']]); $o['payments'] = $p->fetchAll();
        $l = $pdo->prepare("SELECT actor, action, details, created_at FROM audit_log WHERE (entity='order' AND entity_id=?) OR (action='esim_entregada' AND details LIKE ?) ORDER BY id DESC LIMIT 100");
        $l->execute([$o['id'], '%"pedido":"' . $o['order_number'] . '"%']); $o['log'] = $l->fetchAll();
        $o['customer_link'] = order_link($o);
        ok(['order' => $o]);
    }
    $where = []; $args = [];
    if (!empty($_GET['status'])) { $where[] = 'status=?'; $args[] = (string)$_GET['status']; }
    if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $where[] = '(order_number LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?)'; $l = '%' . addcslashes($q, '%_\\') . '%'; array_push($args, $l, $l, $l); }
    if (!empty($_GET['customer_id'])) { $where[] = 'customer_id=?'; $args[] = (int)$_GET['customer_id']; }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $sql = 'FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $c = $pdo->prepare("SELECT COUNT(*) $sql"); $c->execute($args);
    $st = $pdo->prepare("SELECT id, order_number, customer_name, customer_email, total, status, payment_method, has_esim, has_physical, esim_delivered, created_at, paid_at $sql ORDER BY id DESC LIMIT 50 OFFSET " . (($page - 1) * 50));
    $st->execute($args);
    ok(['orders' => $st->fetchAll(), 'total' => (int)$c->fetchColumn(), 'page' => $page]);
}
method('POST');
$in = input();
$id = (int)($in['id'] ?? 0);
$o = get_order($id); if (!$o) fail('Pedido no encontrado', 404);
try {
    switch (str_in($in, 'action', 20)) {
        case 'mark_paid':
            $ref = str_in($in, 'reference', 100) ?: 'MANUAL-' . date('YmdHis');
            mark_paid($id, $o['payment_method'] === 'paypal' ? 'manual' : ($o['payment_method'] === 'simulado' ? 'manual' : 'manual'), $ref . '-' . $id, (float)$o['total'], $o['currency'], admin_name());
            ok(['order' => ['status' => get_order($id)['status']]]);
        case 'deliver':
            $n = deliver_esims($id, admin_name());
            ok(['delivered' => $n, 'message' => $n ? "Se entregaron $n eSIM" : 'Las eSIM ya estaban entregadas']);
        case 'resend':
            $r = email_esims($id) ;
            audit(admin_name(), 'reenvio_esim', 'order', $id);
            ok(['sent' => $r, 'message' => $r ? 'Correo reenviado' : 'No se pudo enviar el correo (revisa mail() del hosting) o no hay eSIM entregadas']);
        case 'cancel':
            cancel_order($id, admin_name(), str_in($in, 'reason', 200));
            ok();
        case 'status':
            $s = str_in($in, 'status', 20);
            $allowed = ['en_proceso', 'enviado', 'entregado', 'reembolsado'];
            if (!in_array($s, $allowed, true)) fail('Para pagado usa "Marcar pagado" y para cancelar usa "Cancelar"', 422);
            if (!in_array($o['status'], PAID_STATUSES, true) && $o['status'] !== 'reembolsado') fail('El pedido aún no está pagado', 422);
            $pdo->prepare('UPDATE orders SET status=?, tracking_number=COALESCE(?, tracking_number) WHERE id=?')->execute([$s, str_in($in, 'tracking', 100) ?: null, $id]);
            audit(admin_name(), 'estado_cambiado', 'order', $id, ['de' => $o['status'], 'a' => $s]);
            if ($s === 'enviado') send_mail($o['customer_email'], 'Tu pedido ' . $o['order_number'] . ' fue enviado', "Hola {$o['customer_name']},\n\nTu pedido fue enviado." . (str_in($in, 'tracking', 100) ? "\nGuía de rastreo: " . str_in($in, 'tracking', 100) : '') . "\n\n" . order_link($o));
            ok();
        case 'notes':
            $pdo->prepare('UPDATE orders SET admin_notes=?, tracking_number=? WHERE id=?')->execute([str_in($in, 'admin_notes', 5000), str_in($in, 'tracking', 100) ?: null, $id]);
            ok();
    }
} catch (UserError $e) { fail($e->getMessage(), 422); }
fail('Acción no válida');
