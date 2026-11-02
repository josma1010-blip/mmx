<?php
// Webhook de PayPal (opcional). Configura la URL https://tudominio/tienda/api/paypal_webhook.php y su Webhook ID en el panel.
require __DIR__ . '/_lib/paypal.php';
method('POST');
$raw = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!$headers) foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $headers[str_replace('_', '-', substr($k, 5))] = $v;
try {
    if (!paypal_verify_webhook($headers, $raw)) { audit('paypal', 'webhook_firma_invalida', null, null, substr($raw, 0, 500)); fail('Firma inválida', 400); }
} catch (Throwable $e) { fail('No verificable', 400); }
$ev = json_decode($raw, true) ?: [];
audit('paypal', 'webhook', null, $ev['id'] ?? null, $ev['event_type'] ?? '');
if (($ev['event_type'] ?? '') === 'PAYMENT.CAPTURE.COMPLETED') {
    $cap = $ev['resource'] ?? [];
    $num = $cap['custom_id'] ?? ($cap['invoice_id'] ?? '');
    $st = db()->prepare('SELECT * FROM orders WHERE order_number=?'); $st->execute([$num]);
    $o = $st->fetch();
    if ($o && ($cap['status'] ?? '') === 'COMPLETED') {
        try { mark_paid((int)$o['id'], 'paypal', $cap['id'] ?? null, (float)($cap['amount']['value'] ?? 0), (string)($cap['amount']['currency_code'] ?? ''), 'paypal-webhook', $raw); }
        catch (Throwable $e) { audit('paypal', 'webhook_error', 'order', $o['id'], $e->getMessage()); }
    }
}
ok();
