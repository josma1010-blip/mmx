<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const PAID_STATUSES = ['pagado', 'en_proceso', 'enviado', 'entregado'];

/** Ejecuta una transacción con reintento ante deadlock */
function tx(callable $fn) {
    $pdo = db();
    for ($i = 0; ; $i++) {
        $pdo->beginTransaction();
        try { $r = $fn($pdo); $pdo->commit(); return $r; }
        catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $code = $e->errorInfo[1] ?? 0;
            if (($code == 1213 || $code == 1205) && $i < 4) { usleep(random_int(20000, 120000)); continue; }
            throw $e;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}

class UserError extends RuntimeException { public array $fields = []; }

function stock_sql(string $alias = 'p'): string {
    return "(CASE WHEN $alias.type='esim' THEN (SELECT COUNT(*) FROM esim_codes ec WHERE ec.product_id=$alias.id AND ec.status='disponible') ELSE $alias.stock END)";
}

function generate_order_number(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 6; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return 'TE-' . date('Ymd') . '-' . $s;
}

/** Cancela pedidos pendientes cuya reserva expiró, liberando stock y códigos */
function release_expired_reservations(): int {
    $h = max(1, (int)setting('reservation_hours', '48'));
    $st = db()->prepare("SELECT id FROM orders WHERE status='pendiente_pago' AND created_at < (NOW() - INTERVAL ? HOUR) LIMIT 50");
    $st->execute([$h]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { if (cancel_order((int)$id, 'sistema', 'Reserva expirada')) $n++; }
    return $n;
}

function calc_shipping(float $physicalSubtotal, bool $hasPhysical): float {
    if (!$hasPhysical) return 0.0;
    $free = (float)setting('free_shipping_from', '0');
    if ($free > 0 && $physicalSubtotal >= $free) return 0.0;
    return round((float)setting('shipping_cost', '0'), 2);
}

/**
 * Valida carrito contra BD (precios del servidor). Devuelve líneas y totales.
 * @param array $items [{id, qty}]
 */
function price_cart(array $items, bool $strictStock = true): array {
    if (!$items || count($items) > 30) throw new UserError('El carrito está vacío o es demasiado grande');
    $qtys = [];
    foreach ($items as $it) {
        $id = (int)($it['id'] ?? 0); $q = (int)($it['qty'] ?? 0);
        if ($id <= 0 || $q <= 0) continue;
        if ($q > 20) throw new UserError('Cantidad máxima por producto: 20');
        $qtys[$id] = ($qtys[$id] ?? 0) + $q;
    }
    if (!$qtys) throw new UserError('El carrito está vacío');
    $in = implode(',', array_fill(0, count($qtys), '?'));
    $st = db()->prepare('SELECT p.id, p.name, p.price, p.type, p.active, p.available, ' . stock_sql() . " AS stock FROM products p WHERE p.id IN ($in)");
    $st->execute(array_keys($qtys));
    $rows = []; foreach ($st->fetchAll() as $r) $rows[(int)$r['id']] = $r;
    $lines = []; $subtotal = 0.0; $physSub = 0.0; $hasPhys = false; $hasEsim = false; $errors = [];
    foreach ($qtys as $id => $q) {
        $p = $rows[$id] ?? null;
        if (!$p || !$p['active'] || !$p['available']) { $errors[] = ['id' => $id, 'error' => 'Producto no disponible']; continue; }
        if ((int)$p['stock'] < $q) { $errors[] = ['id' => $id, 'error' => 'Stock insuficiente para ' . $p['name'] . ' (disponibles: ' . (int)$p['stock'] . ')', 'stock' => (int)$p['stock']]; if ($strictStock) continue; }
        $price = round((float)$p['price'], 2);
        $line = round($price * $q, 2);
        $subtotal += $line;
        if ($p['type'] === 'fisico') { $hasPhys = true; $physSub += $line; } else { $hasEsim = true; }
        $lines[] = ['product_id' => $id, 'name' => $p['name'], 'type' => $p['type'], 'unit_price' => $price, 'quantity' => $q, 'line_total' => $line, 'stock' => (int)$p['stock']];
    }
    $shipping = calc_shipping($physSub, $hasPhys);
    return ['lines' => $lines, 'errors' => $errors, 'subtotal' => round($subtotal, 2), 'shipping' => $shipping,
        'total' => round($subtotal + $shipping, 2), 'has_physical' => $hasPhys, 'has_esim' => $hasEsim];
}

function payment_methods_available(): array {
    $m = [];
    if (setting('paypal_enabled') === '1' && setting('paypal_client_id') !== '' && setting('paypal_secret') !== '') $m[] = 'paypal';
    if (setting('manual_enabled', '1') === '1') $m[] = 'manual';
    if (cfg('dev_simulated_payment') === true) $m[] = 'simulado';
    return $m;
}

function create_order(array $in): array {
    $name = str_in($in, 'name', 150); $email = mb_strtolower(str_in($in, 'email', 190));
    $phone = preg_replace('/\D+/', '', str_in($in, 'phone', 30));
    $method = str_in($in, 'payment_method', 20);
    $errs = [];
    if (mb_strlen($name) < 3) $errs['name'] = 'Escribe tu nombre completo';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs['email'] = 'Correo electrónico inválido';
    if (strlen($phone) < 10 || strlen($phone) > 13) $errs['phone'] = 'Teléfono a 10 dígitos';
    if (!in_array($method, payment_methods_available(), true)) $errs['payment_method'] = 'Método de pago no disponible';
    $cart = price_cart(is_array($in['items'] ?? null) ? $in['items'] : []);
    if ($cart['errors']) throw new UserError($cart['errors'][0]['error']);
    $addr = null;
    if ($cart['has_physical']) {
        $a = [];
        foreach (['street' => 200, 'ext_number' => 30, 'int_number' => 30, 'neighborhood' => 120, 'city' => 120, 'state' => 80, 'zip' => 10, 'references' => 255] as $k => $max) $a[$k] = str_in($in, $k, $max);
        foreach (['street' => 'Calle', 'ext_number' => 'Número exterior', 'neighborhood' => 'Colonia', 'city' => 'Ciudad / Municipio', 'state' => 'Estado'] as $k => $lbl) if (mb_strlen($a[$k]) < 1) $errs[$k] = "$lbl es obligatorio";
        if (!preg_match('/^\d{5}$/', $a['zip'])) $errs['zip'] = 'Código postal de 5 dígitos';
        $addr = $a;
    }
    if ($errs) throw_validation($errs);
    $notes = str_in($in, 'notes', 500);
    $token = bin2hex(random_bytes(32));

    $order = tx(function (PDO $pdo) use ($cart, $name, $email, $phone, $addr, $method, $notes, $token) {
        // 1) Descontar stock físico de forma condicional (evita sobreventa)
        foreach ($cart['lines'] as $l) if ($l['type'] === 'fisico') {
            $u = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id=? AND stock >= ?');
            $u->execute([$l['quantity'], $l['product_id'], $l['quantity']]);
            if ($u->rowCount() !== 1) throw new UserError('Stock insuficiente para ' . $l['name']);
        }
        // 2) Cliente
        $pdo->prepare('INSERT INTO customers (name,email,phone,street,ext_number,int_number,neighborhood,city,state,zip,references_text) VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), name=VALUES(name), phone=VALUES(phone),
            street=COALESCE(VALUES(street),street), ext_number=COALESCE(VALUES(ext_number),ext_number), int_number=COALESCE(VALUES(int_number),int_number),
            neighborhood=COALESCE(VALUES(neighborhood),neighborhood), city=COALESCE(VALUES(city),city), state=COALESCE(VALUES(state),state), zip=COALESCE(VALUES(zip),zip), references_text=COALESCE(VALUES(references_text),references_text)')
            ->execute([$name, $email, $phone, $addr['street'] ?? null, $addr['ext_number'] ?? null, $addr['int_number'] ?? null, $addr['neighborhood'] ?? null, $addr['city'] ?? null, $addr['state'] ?? null, $addr['zip'] ?? null, $addr['references'] ?? null]);
        $customerId = (int)$pdo->lastInsertId();
        $addrText = $addr ? sprintf("%s %s%s, Col. %s, %s, %s, C.P. %s%s", $addr['street'], $addr['ext_number'], $addr['int_number'] ? ' Int. ' . $addr['int_number'] : '', $addr['neighborhood'], $addr['city'], $addr['state'], $addr['zip'], $addr['references'] ? '. Ref: ' . $addr['references'] : '') : null;
        // 3) Pedido
        for ($i = 0; $i < 5; $i++) {
            $num = generate_order_number();
            try {
                $pdo->prepare('INSERT INTO orders (order_number, access_token_hash, access_token_enc, customer_id, customer_name, customer_email, customer_phone, shipping_address, has_physical, has_esim, subtotal, shipping, total, currency, payment_method, notes, ip)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$num, hash('sha256', $token), encrypt_secret($token), $customerId, $name, $email, $phone, $addrText, $cart['has_physical'] ? 1 : 0, $cart['has_esim'] ? 1 : 0,
                        $cart['subtotal'], $cart['shipping'], $cart['total'], setting('currency', 'MXN'), $method, $notes ?: null, client_ip()]);
                break;
            } catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) != 1062 || $i === 4) throw $e; }
        }
        $orderId = (int)$pdo->lastInsertId();
        // 4) Partidas + reserva de códigos eSIM (bloqueo de filas)
        foreach ($cart['lines'] as $l) {
            $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, type, unit_price, quantity, line_total) VALUES (?,?,?,?,?,?,?)')
                ->execute([$orderId, $l['product_id'], $l['name'], $l['type'], $l['unit_price'], $l['quantity'], $l['line_total']]);
            $itemId = (int)$pdo->lastInsertId();
            if ($l['type'] === 'esim') {
                $q = (int)$l['quantity'];
                $s = $pdo->prepare("SELECT id FROM esim_codes WHERE product_id=? AND status='disponible' ORDER BY id LIMIT $q FOR UPDATE");
                $s->execute([$l['product_id']]);
                $ids = $s->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) < $q) throw new UserError('Stock insuficiente para ' . $l['name']);
                $inq = implode(',', array_map('intval', $ids));
                $u = $pdo->prepare("UPDATE esim_codes SET status='reservado', order_id=?, order_item_id=?, reserved_at=NOW() WHERE id IN ($inq) AND status='disponible'");
                $u->execute([$orderId, $itemId]);
                if ($u->rowCount() !== $q) throw new UserError('Stock insuficiente para ' . $l['name']);
            }
        }
        return ['id' => $orderId, 'order_number' => $num];
    });
    audit('cliente', 'pedido_creado', 'order', $order['id'], ['n' => $order['order_number'], 'total' => $cart['total'], 'metodo' => $method]);
    $link = base_url() . '/pedido-confirmado.html?n=' . urlencode($order['order_number']) . '&t=' . $token;
    $body = "Hola $name,\n\nRecibimos tu pedido {$order['order_number']} por " . money($cart['total']) . ".\n";
    $body .= $method === 'manual' ? "\nInstrucciones de pago:\n" . setting('manual_payment_instructions') . "\n" : "\n";
    $body .= "\nConsulta el estado de tu pedido (y tus eSIM cuando se confirme el pago) aquí:\n$link\n\nGuarda este enlace, es privado.\n\n" . setting('store_name');
    send_mail($email, 'Pedido ' . $order['order_number'] . ' recibido', $body);
    if (setting('notify_email')) send_mail(setting('notify_email'), 'Nuevo pedido ' . $order['order_number'], "Nuevo pedido {$order['order_number']} de $name ($email) por " . money($cart['total']) . " - método: $method");
    return $order + ['token' => $token, 'total' => $cart['total']];
}
function throw_validation(array $errs): never { $e = new UserError('Revisa los datos del formulario'); $e->fields = $errs; throw $e; }

function find_order_by_token(string $number, string $token): ?array {
    if (!preg_match('/^TE-\d{8}-[A-Z0-9]{6}$/', $number) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM orders WHERE order_number=?');
    $st->execute([$number]);
    $o = $st->fetch();
    if (!$o || !hash_equals($o['access_token_hash'], hash('sha256', $token))) return null;
    return $o;
}

/** Marca un pedido como pagado tras verificación. Idempotente. Devuelve true si cambió. */
function mark_paid(int $orderId, string $method, ?string $ref, float $amount, string $currency, string $actor, $raw = null): bool {
    $changed = tx(function (PDO $pdo) use ($orderId, $method, $ref, $amount, $currency, $actor, $raw) {
        $st = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) throw new UserError('Pedido no encontrado');
        if (in_array($o['status'], PAID_STATUSES, true)) return false;
        if ($o['status'] !== 'pendiente_pago') throw new UserError('El pedido está ' . $o['status'] . ' y no puede marcarse como pagado');
        if (abs(round($amount, 2) - round((float)$o['total'], 2)) > 0.001 || strtoupper($currency) !== strtoupper($o['currency'])) {
            throw new UserError('El monto/moneda del pago no coincide con el pedido');
        }
        $pdo->prepare('INSERT INTO payments (order_id, method, provider_ref, amount, currency, status, raw_response, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$orderId, $method, $ref, $amount, strtoupper($currency), 'completado', $raw ? (is_string($raw) ? $raw : json_encode($raw)) : null, $actor]);
        $pdo->prepare("UPDATE orders SET status='pagado', paid_at=NOW() WHERE id=?")->execute([$orderId]);
        $pdo->prepare('INSERT INTO audit_log (actor, action, entity, entity_id, details, ip) VALUES (?,?,?,?,?,?)')
            ->execute([$actor, 'pedido_pagado', 'order', $orderId, json_encode(['metodo' => $method, 'ref' => $ref, 'monto' => $amount]), client_ip()]);
        return true;
    });
    if ($changed) {
        $o = get_order($orderId);
        send_mail($o['customer_email'], 'Pago confirmado - ' . $o['order_number'], "Hola {$o['customer_name']},\n\nConfirmamos el pago de tu pedido {$o['order_number']}.\n" . ($o['has_esim'] ? (setting('esim_delivery_mode') === 'auto' ? "Tus eSIM se envían en un correo aparte y también están en tu página de pedido.\n" : "Prepararemos tus eSIM y te avisaremos en cuanto estén listas.\n") : '') . ($o['has_physical'] ? "Te avisaremos cuando enviemos tus chips.\n" : '') . "\n" . order_link($o) . "\n\n" . setting('store_name'));
        if ($o['has_esim'] && setting('esim_delivery_mode', 'auto') === 'auto') {
            try { deliver_esims($orderId, 'sistema'); } catch (UserError $e) { audit('sistema', 'entrega_auto_fallida', 'order', $orderId, $e->getMessage()); }
        }
    }
    return $changed;
}

function get_order(int $id): ?array {
    $st = db()->prepare('SELECT * FROM orders WHERE id=?'); $st->execute([$id]); return $st->fetch() ?: null;
}
function order_link(array $o): string {
    return base_url() . '/pedido-confirmado.html?n=' . urlencode($o['order_number']) . '&t=' . decrypt_secret($o['access_token_enc']);
}

/** Asigna y entrega códigos eSIM. Atómico, idempotente: nunca entrega un código dos veces. */
function deliver_esims(int $orderId, string $actor, bool $sendEmail = true): int {
    $delivered = tx(function (PDO $pdo) use ($orderId, $actor) {
        $st = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) throw new UserError('Pedido no encontrado');
        if (!in_array($o['status'], PAID_STATUSES, true)) throw new UserError('Solo se entregan eSIM de pedidos con pago confirmado');
        $items = $pdo->prepare("SELECT * FROM order_items WHERE order_id=? AND type='esim'");
        $items->execute([$orderId]);
        $newly = [];
        foreach ($items->fetchAll() as $it) {
            $c = $pdo->prepare("SELECT id, status FROM esim_codes WHERE order_item_id=? AND status IN ('reservado','entregado') ORDER BY id FOR UPDATE");
            $c->execute([$it['id']]);
            $rows = $c->fetchAll();
            $done = count(array_filter($rows, fn($r) => $r['status'] === 'entregado'));
            $need = (int)$it['quantity'] - $done;
            if ($need <= 0) continue;
            $ids = array_slice(array_column(array_filter($rows, fn($r) => $r['status'] === 'reservado'), 'id'), 0, $need);
            if (count($ids) < $need) {
                $extra = $need - count($ids);
                if (!$it['product_id']) throw new UserError('Producto eliminado; asigna códigos manualmente');
                $s = $pdo->prepare("SELECT id FROM esim_codes WHERE product_id=? AND status='disponible' ORDER BY id LIMIT $extra FOR UPDATE");
                $s->execute([$it['product_id']]);
                $more = $s->fetchAll(PDO::FETCH_COLUMN);
                if (count($more) < $extra) throw new UserError('Faltan ' . ($extra - count($more)) . ' código(s) eSIM disponibles para ' . $it['product_name']);
                $ids = array_merge($ids, $more);
            }
            $inq = implode(',', array_map('intval', $ids));
            $u = $pdo->prepare("UPDATE esim_codes SET status='entregado', order_id=?, order_item_id=?, delivered_at=NOW(), reserved_at=COALESCE(reserved_at,NOW())
                                WHERE id IN ($inq) AND status IN ('reservado','disponible') AND (order_id IS NULL OR order_id=?)");
            $u->execute([$orderId, $it['id'], $orderId]);
            if ($u->rowCount() !== count($ids)) throw new RuntimeException('Conflicto de asignación de códigos, reintenta');
            foreach ($ids as $cid) {
                $pdo->prepare('INSERT INTO audit_log (actor, action, entity, entity_id, details, ip) VALUES (?,?,?,?,?,?)')
                    ->execute([$actor, 'esim_entregada', 'esim_code', $cid, json_encode(['pedido' => $o['order_number'], 'item' => $it['id']]), client_ip()]);
                $newly[] = (int)$cid;
            }
        }
        $pdo->prepare('UPDATE orders SET esim_delivered=1' . ($o['has_physical'] ? '' : ", status='entregado'") . ' WHERE id=?')->execute([$orderId]);
        return $newly;
    });
    if ($delivered && $sendEmail) email_esims($orderId);
    return count($delivered);
}

function order_esims(int $orderId): array {
    $st = db()->prepare("SELECT e.id, e.iccid, e.activation_code, e.smdp_address, e.confirmation_code, e.phone_number, e.qr_image, e.delivered_at, oi.product_name
                         FROM esim_codes e JOIN order_items oi ON oi.id=e.order_item_id WHERE e.order_id=? AND e.status='entregado' ORDER BY e.id");
    $st->execute([$orderId]);
    return $st->fetchAll();
}

function email_esims(int $orderId): bool {
    $o = get_order($orderId);
    $codes = order_esims($orderId);
    if (!$o || !$codes) return false;
    $b = "Hola {$o['customer_name']},\n\nAquí están tus eSIM del pedido {$o['order_number']}:\n\n";
    foreach ($codes as $i => $c) {
        $b .= ($i + 1) . ". {$c['product_name']}\n   ICCID: {$c['iccid']}\n   Código de activación (LPA): {$c['activation_code']}\n";
        if ($c['smdp_address']) $b .= "   SM-DP+: {$c['smdp_address']}\n";
        if ($c['confirmation_code']) $b .= "   Código de confirmación: {$c['confirmation_code']}\n";
        if ($c['phone_number']) $b .= "   Número: {$c['phone_number']}\n";
        $b .= "\n";
    }
    $b .= "Ver códigos QR para escanear:\n" . order_link($o) . "\n\nInstalación: Ajustes > Datos celulares / Red móvil > Agregar eSIM > Escanear código QR (o ingresar los datos manualmente).\nNo compartas estos datos: una eSIM solo puede instalarse una vez.\n\n" . setting('store_name');
    $r = send_mail($o['customer_email'], 'Tus eSIM - pedido ' . $o['order_number'], $b);
    audit('sistema', $r ? 'correo_esim_enviado' : 'correo_esim_fallido', 'order', $orderId);
    return $r;
}

function cancel_order(int $orderId, string $actor, string $reason = ''): bool {
    return tx(function (PDO $pdo) use ($orderId, $actor, $reason) {
        $st = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o || in_array($o['status'], ['cancelado', 'reembolsado'], true)) return false;
        $pdo->prepare("UPDATE esim_codes SET status='disponible', order_id=NULL, order_item_id=NULL, reserved_at=NULL WHERE order_id=? AND status='reservado'")->execute([$orderId]);
        if (!in_array($o['status'], ['enviado', 'entregado'], true)) {
            $it = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=? AND type='fisico' AND product_id IS NOT NULL");
            $it->execute([$orderId]);
            foreach ($it->fetchAll() as $r) $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$r['quantity'], $r['product_id']]);
        }
        $pdo->prepare("UPDATE orders SET status='cancelado', admin_notes=CONCAT(COALESCE(admin_notes,''), ?) WHERE id=?")->execute(["\n[" . date('Y-m-d H:i') . "] Cancelado: $reason", $orderId]);
        $pdo->prepare('INSERT INTO audit_log (actor, action, entity, entity_id, details, ip) VALUES (?,?,?,?,?,?)')->execute([$actor, 'pedido_cancelado', 'order', $orderId, $reason, client_ip()]);
        return true;
    });
}

function order_items(int $orderId): array {
    $st = db()->prepare('SELECT id, product_id, product_name, type, unit_price, quantity, line_total FROM order_items WHERE order_id=?');
    $st->execute([$orderId]);
    return $st->fetchAll();
}
