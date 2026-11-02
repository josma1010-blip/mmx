<?php
// SOLO DESARROLLO: simula un pago aprobado. Deshabilitado salvo 'dev_simulated_payment' => true en config.php.
require __DIR__ . '/_lib/orders.php';
if (cfg('dev_simulated_payment') !== true) fail('No disponible', 404);
method('POST');
csrf_check();
$in = input();
$o = find_order_by_token(str_in($in, 'n', 30), str_in($in, 't', 64));
if (!$o || $o['payment_method'] !== 'simulado') fail('Pedido no encontrado', 404);
try { mark_paid((int)$o['id'], 'simulado', 'SIM-' . bin2hex(random_bytes(6)), (float)$o['total'], $o['currency'], 'simulado'); }
catch (UserError $e) { fail($e->getMessage(), 422); }
ok(['paid' => true]);
