<?php
require __DIR__ . '/../_lib/admin_auth.php';
require __DIR__ . '/../_lib/paypal.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sec = decrypt_secret(setting('paypal_secret'));
    ok(['paypal' => ['enabled' => setting('paypal_enabled') === '1', 'mode' => setting('paypal_mode', 'sandbox'), 'client_id' => setting('paypal_client_id'),
        'secret_set' => $sec !== '', 'secret_hint' => $sec !== '' ? '••••' . substr($sec, -4) : '', 'webhook_id' => setting('paypal_webhook_id'),
        'webhook_url' => base_url() . '/api/paypal_webhook.php']]);
}
method('POST');
$in = input();
if (str_in($in, 'action', 20) === 'test') {
    try { paypal_token(); ok(['message' => 'Conexión correcta con PayPal (' . setting('paypal_mode') . ')']); }
    catch (Throwable $e) { fail($e instanceof UserError ? $e->getMessage() : 'No se pudo conectar con PayPal', 422); }
}
$mode = str_in($in, 'mode', 10);
if (!in_array($mode, ['sandbox', 'live'], true)) fail('Modo inválido', 422);
$cid = str_in($in, 'client_id', 200);
if ($cid !== '' && !preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $cid)) fail('Client ID con formato inválido', 422);
set_setting('paypal_mode', $mode);
set_setting('paypal_client_id', $cid);
set_setting('paypal_webhook_id', str_in($in, 'webhook_id', 100));
$secret = (string)($in['secret'] ?? '');
if ($secret !== '') set_setting('paypal_secret', encrypt_secret(trim($secret)));
if (!empty($in['clear_secret'])) set_setting('paypal_secret', '');
set_setting('paypal_enabled', !empty($in['enabled']) ? '1' : '0');
audit(admin_name(), 'paypal_configurado', null, null, ['modo' => $mode, 'habilitado' => !empty($in['enabled'])]);
ok();
