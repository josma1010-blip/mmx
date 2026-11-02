<?php
require __DIR__ . '/../_lib/admin_auth.php';
require_admin();
const STORE_KEYS = ['store_name','store_tagline','contact_email','contact_phone','contact_whatsapp','contact_address','mail_from','mail_from_name','notify_email',
    'banner_title','banner_subtitle','banner_cta','banner_badge','shipping_cost','free_shipping_from','currency','esim_delivery_mode','reservation_hours','low_stock_threshold',
    'manual_payment_instructions','footer_disclaimer','about_text','manual_enabled'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = settings_all(); $out = [];
    foreach (STORE_KEYS as $k) $out[$k] = $s[$k] ?? '';
    ok(['settings' => $out, 'dev_simulated_payment' => cfg('dev_simulated_payment') === true]);
}
method('POST');
$in = input()['settings'] ?? [];
if (!is_array($in)) fail('Datos inválidos');
foreach (STORE_KEYS as $k) {
    if (!array_key_exists($k, $in)) continue;
    $v = is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
    if (in_array($k, ['shipping_cost', 'free_shipping_from'], true)) { if (!is_numeric($v) || $v < 0) fail("Valor inválido en $k", 422); $v = number_format((float)$v, 2, '.', ''); }
    if (in_array($k, ['reservation_hours', 'low_stock_threshold'], true)) $v = (string)max(1, (int)$v);
    if ($k === 'esim_delivery_mode' && !in_array($v, ['auto', 'manual'], true)) fail('Modo de entrega inválido', 422);
    if ($k === 'currency' && $v !== 'MXN') fail('Por ahora la tienda opera solo en MXN', 422);
    if (in_array($k, ['contact_email', 'mail_from', 'notify_email'], true) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) fail("Correo inválido en $k", 422);
    if ($k === 'manual_enabled') $v = $v === '1' ? '1' : '0';
    if ($k === 'store_name' && mb_strlen($v) < 2) fail('Nombre de tienda inválido', 422);
    set_setting($k, mb_substr($v, 0, 5000));
}
audit(admin_name(), 'configuracion_guardada');
ok();
