<?php
declare(strict_types=1);
require_once __DIR__ . '/orders.php';

function paypal_base(): string {
    return setting('paypal_mode', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}
function paypal_request(string $method, string $path, ?array $body = null, ?string $token = null, array $extraHeaders = []): array {
    $ch = curl_init(paypal_base() . $path);
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $extraHeaders);
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Error de conexión con PayPal: ' . $err);
    return ['code' => $code, 'body' => json_decode((string)$res, true) ?? [], 'raw' => (string)$res];
}
function paypal_token(): string {
    $id = setting('paypal_client_id'); $secret = decrypt_secret(setting('paypal_secret'));
    if ($id === '' || $secret === '') throw new UserError('PayPal no está configurado');
    $ch = curl_init(paypal_base() . '/v1/oauth2/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => $id . ':' . $secret, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_TIMEOUT => 30]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$res, true);
    if ($code !== 200 || empty($j['access_token'])) throw new UserError('No se pudo autenticar con PayPal (revisa Client ID/Secret y modo)');
    return $j['access_token'];
}

/** Crea la orden en PayPal a partir del pedido (montos del servidor) */
function paypal_create_order(array $o): string {
    if ($o['status'] !== 'pendiente_pago' || $o['payment_method'] !== 'paypal') throw new UserError('El pedido no admite pago con PayPal');
    $tok = paypal_token();
    $items = [];
    foreach (order_items((int)$o['id']) as $it) {
        $items[] = ['name' => mb_substr($it['product_name'], 0, 127), 'quantity' => (string)$it['quantity'], 'category' => $it['type'] === 'esim' ? 'DIGITAL_GOODS' : 'PHYSICAL_GOODS',
            'unit_amount' => ['currency_code' => $o['currency'], 'value' => number_format((float)$it['unit_price'], 2, '.', '')]];
    }
    $body = ['intent' => 'CAPTURE', 'purchase_units' => [[
        'reference_id' => $o['order_number'], 'custom_id' => $o['order_number'], 'invoice_id' => $o['order_number'],
        'description' => 'Pedido ' . $o['order_number'],
        'amount' => ['currency_code' => $o['currency'], 'value' => number_format((float)$o['total'], 2, '.', ''),
            'breakdown' => ['item_total' => ['currency_code' => $o['currency'], 'value' => number_format((float)$o['subtotal'], 2, '.', '')],
                            'shipping' => ['currency_code' => $o['currency'], 'value' => number_format((float)$o['shipping'], 2, '.', '')]]],
        'items' => $items]],
        'application_context' => ['brand_name' => mb_substr(setting('store_name'), 0, 127), 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW']];
    $r = paypal_request('POST', '/v2/checkout/orders', $body, $tok, ['PayPal-Request-Id: create-' . $o['order_number'] . '-' . time()]);
    if ($r['code'] < 200 || $r['code'] >= 300 || empty($r['body']['id'])) { audit('sistema', 'paypal_create_error', 'order', $o['id'], $r['raw']); throw new UserError('PayPal rechazó la creación de la orden'); }
    db()->prepare('UPDATE orders SET paypal_order_id=? WHERE id=?')->execute([$r['body']['id'], $o['id']]);
    return $r['body']['id'];
}

/** Captura y VERIFICA monto, moneda y referencia antes de marcar pagado */
function paypal_capture_order(array $o, string $ppId): array {
    if ($o['paypal_order_id'] !== $ppId) throw new UserError('La orden de PayPal no corresponde al pedido');
    $tok = paypal_token();
    $r = paypal_request('POST', '/v2/checkout/orders/' . rawurlencode($ppId) . '/capture', null, $tok, ['PayPal-Request-Id: capture-' . $ppId]);
    $b = $r['body'];
    if ($r['code'] === 422 && (($b['details'][0]['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED')) {
        $r = paypal_request('GET', '/v2/checkout/orders/' . rawurlencode($ppId), null, $tok); $b = $r['body'];
    }
    return paypal_verify_and_mark($o, $b, $r['raw']);
}
function paypal_verify_and_mark(array $o, array $b, string $raw): array {
    $cap = $b['purchase_units'][0]['payments']['captures'][0] ?? null;
    $ref = $b['purchase_units'][0]['custom_id'] ?? ($cap['custom_id'] ?? ($b['purchase_units'][0]['reference_id'] ?? ''));
    if (($b['status'] ?? '') !== 'COMPLETED' || !$cap || ($cap['status'] ?? '') !== 'COMPLETED') {
        audit('sistema', 'paypal_capture_no_completada', 'order', $o['id'], $raw);
        return ['paid' => false, 'status' => $cap['status'] ?? ($b['status'] ?? 'desconocido')];
    }
    if ($ref !== $o['order_number']) throw new UserError('Referencia de PayPal no coincide');
    $amount = (float)$cap['amount']['value']; $cur = $cap['amount']['currency_code'];
    mark_paid((int)$o['id'], 'paypal', $cap['id'], $amount, $cur, 'paypal', $raw);
    return ['paid' => true, 'status' => 'COMPLETED'];
}

/** Verifica firma del webhook con la API de PayPal */
function paypal_verify_webhook(array $headers, string $rawBody): bool {
    $wid = setting('paypal_webhook_id');
    if ($wid === '') return false;
    $h = array_change_key_case($headers, CASE_LOWER);
    $body = ['auth_algo' => $h['paypal-auth-algo'] ?? '', 'cert_url' => $h['paypal-cert-url'] ?? '', 'transmission_id' => $h['paypal-transmission-id'] ?? '',
        'transmission_sig' => $h['paypal-transmission-sig'] ?? '', 'transmission_time' => $h['paypal-transmission-time'] ?? '', 'webhook_id' => $wid,
        'webhook_event' => json_decode($rawBody, true)];
    $r = paypal_request('POST', '/v1/notifications/verify-webhook-signature', $body, paypal_token());
    return ($r['body']['verification_status'] ?? '') === 'SUCCESS';
}
