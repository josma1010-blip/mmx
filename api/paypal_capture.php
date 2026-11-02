<?php
// Captura la orden de PayPal en el servidor y verifica monto/moneda/referencia. POST {n, t, paypal_order_id}
require __DIR__ . '/_lib/paypal.php';
method('POST');
csrf_check();
rate_limit('paypal', 30, 600); record_attempt('paypal', null, true);
$in = input();
$o = find_order_by_token(str_in($in, 'n', 30), str_in($in, 't', 64));
if (!$o) fail('Pedido no encontrado', 404);
if (in_array($o['status'], PAID_STATUSES, true)) ok(['paid' => true]);
try { ok(paypal_capture_order($o, str_in($in, 'paypal_order_id', 64))); } catch (UserError $e) { fail($e->getMessage(), 422); }
