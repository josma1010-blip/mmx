<?php
// Cotiza el carrito con precios y stock del servidor. POST {items:[{id,qty}]}
require __DIR__ . '/_lib/orders.php';
method('POST');
try { $c = price_cart(is_array(input()['items'] ?? null) ? input()['items'] : [], false); }
catch (UserError $e) { fail($e->getMessage()); }
ok($c);
