<?php
// Estado público del pedido (protegido por token largo). GET ?n=&t=
require __DIR__ . '/_lib/orders.php';
method('GET');
rate_limit('order_view', 120, 300);
$o = find_order_by_token((string)($_GET['n'] ?? ''), (string)($_GET['t'] ?? ''));
if (!$o) { record_attempt('order_view', null, false); fail('Pedido no encontrado', 404); }
$paid = in_array($o['status'], PAID_STATUSES, true);
$esims = [];
if ($paid) foreach (order_esims((int)$o['id']) as $c) {
    $esims[] = ['id' => (int)$c['id'], 'product' => $c['product_name'], 'iccid' => $c['iccid'], 'activation_code' => $c['activation_code'],
        'smdp' => $c['smdp_address'], 'confirmation_code' => $c['confirmation_code'], 'phone_number' => $c['phone_number'],
        'qr_url' => 'api/qr.php?n=' . urlencode($o['order_number']) . '&t=' . urlencode((string)$_GET['t']) . '&c=' . (int)$c['id']];
}
$labels = ['pendiente_pago' => 'Pendiente de pago', 'pagado' => 'Pagado', 'en_proceso' => 'En proceso', 'enviado' => 'Enviado', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado', 'reembolsado' => 'Reembolsado'];
ok(['order' => [
    'order_number' => $o['order_number'], 'status' => $o['status'], 'status_label' => $labels[$o['status']] ?? $o['status'], 'paid' => $paid,
    'customer_name' => $o['customer_name'], 'customer_email' => $o['customer_email'], 'shipping_address' => $o['shipping_address'],
    'subtotal' => (float)$o['subtotal'], 'shipping' => (float)$o['shipping'], 'total' => (float)$o['total'], 'currency' => $o['currency'],
    'payment_method' => $o['payment_method'], 'tracking_number' => $o['tracking_number'], 'created_at' => $o['created_at'], 'paid_at' => $o['paid_at'],
    'has_esim' => (bool)$o['has_esim'], 'has_physical' => (bool)$o['has_physical'], 'esim_delivered' => (bool)$o['esim_delivered'],
    'items' => array_map(fn($i) => ['name' => $i['product_name'], 'type' => $i['type'], 'unit_price' => (float)$i['unit_price'], 'quantity' => (int)$i['quantity'], 'line_total' => (float)$i['line_total']], order_items((int)$o['id'])),
    'esims' => $esims,
    'manual_instructions' => ($o['payment_method'] === 'manual' && !$paid) ? setting('manual_payment_instructions') : null,
], 'csrf' => csrf_token()]);
