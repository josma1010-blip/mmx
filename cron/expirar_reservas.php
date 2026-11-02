<?php
// Uso (cPanel > Cron Jobs, cada 15 min):  php /home/USUARIO/public_html/tienda/cron/expirar_reservas.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../api/_lib/orders.php';
echo 'Reservas liberadas: ' . release_expired_reservations() . PHP_EOL;
