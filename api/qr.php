<?php
// Sirve la imagen QR de una eSIM SOLO si el token del pedido es válido y el pedido está pagado.
require __DIR__ . '/_lib/orders.php';
require __DIR__ . '/_lib/qr.php';
method('GET');
rate_limit('order_view', 120, 300);
$o = find_order_by_token((string)($_GET['n'] ?? ''), (string)($_GET['t'] ?? ''));
if (!$o || !in_array($o['status'], PAID_STATUSES, true)) { http_response_code(404); exit; }
$st = db()->prepare("SELECT activation_code, qr_image FROM esim_codes WHERE id=? AND order_id=? AND status='entregado'");
$st->execute([(int)($_GET['c'] ?? 0), $o['id']]);
$c = $st->fetch();
if (!$c) { http_response_code(404); exit; }
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
$file = $c['qr_image'] ? realpath(uploads_dir('private/qr/') . basename($c['qr_image'])) : false;
if ($file && is_file($file)) {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) { header('Content-Type: ' . $mime); readfile($file); exit; }
}
header('Content-Type: image/svg+xml');
echo QrCode::svg($c['activation_code'], 8, 4);
