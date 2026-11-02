<?php
// Crea un pedido. POST JSON + cabecera X-CSRF-Token
require __DIR__ . '/_lib/orders.php';
method('POST');
csrf_check();
rate_limit('checkout', 10, 600);
record_attempt('checkout', null, true);
release_expired_reservations();
try {
    $o = create_order(input());
} catch (UserError $e) {
    fail($e->getMessage(), 422, $e->fields ? ['fields' => $e->fields] : []);
}
ok(['order_number' => $o['order_number'], 'token' => $o['token'], 'total' => $o['total'],
    'url' => 'pedido-confirmado.html?n=' . urlencode($o['order_number']) . '&t=' . $o['token']]);
