<?php
// Crea la orden de PayPal en el servidor con el total del pedido. POST {n, t}
require __DIR__ . '/_lib/paypal.php';
method('POST');
csrf_check();
rate_limit('paypal', 30, 600); record_attempt('paypal', null, true);
$in = input();
$o = find_order_by_token(str_in($in, 'n', 30), str_in($in, 't', 64));
if (!$o) fail('Pedido no encontrado', 404);
try { ok(['id' => paypal_create_order($o)]); } catch (UserError $e) { fail($e->getMessage(), 422); }
