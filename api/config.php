<?php
// Configuración pública de la tienda + token CSRF
require __DIR__ . '/_lib/orders.php';
method('GET');
$s = settings_all();
$pub = [];
foreach (['store_name','store_tagline','contact_email','contact_phone','contact_whatsapp','contact_address','banner_title','banner_subtitle','banner_cta','banner_badge',
          'shipping_cost','free_shipping_from','currency','footer_disclaimer','about_text','paypal_mode','manual_payment_instructions'] as $k) $pub[$k] = $s[$k] ?? '';
$methods = payment_methods_available();
$pub['payment_methods'] = $methods;
$pub['paypal_client_id'] = in_array('paypal', $methods, true) ? ($s['paypal_client_id'] ?? '') : '';
$cats = db()->query('SELECT id, name, slug FROM categories ORDER BY sort_order, name')->fetchAll();
$comps = db()->query('SELECT c.id, c.name FROM companies c WHERE EXISTS (SELECT 1 FROM products p WHERE p.company_id=c.id AND p.active=1) ORDER BY c.name')->fetchAll();
ok(['settings' => $pub, 'categories' => $cats, 'companies' => $comps, 'csrf' => csrf_token()]);
