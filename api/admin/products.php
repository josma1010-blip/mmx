<?php
require __DIR__ . '/../_lib/admin_auth.php';
$a = require_admin();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $st = $pdo->prepare('SELECT p.*, ' . stock_sql() . ' AS inventory FROM products p WHERE p.id=?'); $st->execute([(int)$_GET['id']]);
        $p = $st->fetch(); if (!$p) fail('No encontrado', 404);
        $im = $pdo->prepare('SELECT id, path, alt, sort_order FROM product_images WHERE product_id=? ORDER BY sort_order, id'); $im->execute([$p['id']]);
        $p['images'] = $im->fetchAll();
        $c = $pdo->prepare('SELECT status, COUNT(*) n FROM esim_codes WHERE product_id=? GROUP BY status'); $c->execute([$p['id']]);
        $p['esim_counts'] = $c->fetchAll(PDO::FETCH_KEY_PAIR);
        ok(['product' => $p]);
    }
    $rows = $pdo->query('SELECT p.id, p.name, p.slug, p.price, p.type, p.stock, p.available, p.active, p.featured, p.is_example, c.name category, co.name company, ' . stock_sql() . ' AS inventory,
        (SELECT path FROM product_images i WHERE i.product_id=p.id ORDER BY sort_order, id LIMIT 1) image
        FROM products p JOIN categories c ON c.id=p.category_id JOIN companies co ON co.id=p.company_id ORDER BY p.id DESC')->fetchAll();
    ok(['products' => $rows, 'categories' => $pdo->query('SELECT id, name FROM categories ORDER BY sort_order')->fetchAll(), 'companies' => $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll()]);
}
method('POST');
$in = input();
$action = str_in($in, 'action', 20);

function slugify(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s ?: 'producto';
}
function product_data(array $in): array {
    $d = ['name' => str_in($in, 'name', 180), 'short_description' => str_in($in, 'short_description', 300), 'description' => str_in($in, 'description', 10000),
        'price' => round((float)($in['price'] ?? 0), 2), 'compare_price' => ($in['compare_price'] ?? '') === '' || $in['compare_price'] === null ? null : round((float)$in['compare_price'], 2),
        'type' => in_array($in['type'] ?? '', ['esim', 'fisico'], true) ? $in['type'] : '', 'stock' => max(0, (int)($in['stock'] ?? 0)),
        'category_id' => (int)($in['category_id'] ?? 0), 'company_id' => (int)($in['company_id'] ?? 0),
        'available' => !empty($in['available']) ? 1 : 0, 'active' => !empty($in['active']) ? 1 : 0, 'featured' => !empty($in['featured']) ? 1 : 0, 'is_example' => !empty($in['is_example']) ? 1 : 0];
    if (mb_strlen($d['name']) < 3) fail('El nombre es obligatorio (mín. 3 caracteres)', 422);
    if ($d['price'] <= 0 || $d['price'] > 1000000) fail('Precio inválido', 422);
    if (!$d['type']) fail('Tipo inválido', 422);
    if (!$d['category_id'] || !$d['company_id']) fail('Selecciona categoría y compañía', 422);
    return $d;
}
function unique_slug(PDO $pdo, string $base, int $exceptId = 0): string {
    $slug = $base; $i = 2;
    while (true) { $st = $pdo->prepare('SELECT id FROM products WHERE slug=? AND id<>?'); $st->execute([$slug, $exceptId]); if (!$st->fetch()) return $slug; $slug = $base . '-' . $i++; }
}

switch ($action) {
    case 'create':
        $d = product_data($in);
        $d['slug'] = unique_slug($pdo, slugify(str_in($in, 'slug', 200) ?: $d['name']));
        $cols = array_keys($d);
        $pdo->prepare('INSERT INTO products (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($d));
        $id = (int)$pdo->lastInsertId();
        audit(admin_name(), 'producto_creado', 'product', $id, $d['name']);
        ok(['id' => $id]);
    case 'update':
        $id = (int)($in['id'] ?? 0);
        $d = product_data($in);
        $d['slug'] = unique_slug($pdo, slugify(str_in($in, 'slug', 200) ?: $d['name']), $id);
        $set = implode(',', array_map(fn($c) => "$c=?", array_keys($d)));
        $st = $pdo->prepare("UPDATE products SET $set WHERE id=?"); $st->execute([...array_values($d), $id]);
        audit(admin_name(), 'producto_editado', 'product', $id, ['precio' => $d['price'], 'stock' => $d['stock']]);
        ok(['id' => $id]);
    case 'quick':  // cambio rápido de precio / stock / activo
        $id = (int)($in['id'] ?? 0);
        if (isset($in['price'])) { $p = round((float)$in['price'], 2); if ($p <= 0) fail('Precio inválido', 422); $pdo->prepare('UPDATE products SET price=? WHERE id=?')->execute([$p, $id]); }
        if (isset($in['stock'])) $pdo->prepare("UPDATE products SET stock=? WHERE id=? AND type='fisico'")->execute([max(0, (int)$in['stock']), $id]);
        if (isset($in['active'])) $pdo->prepare('UPDATE products SET active=? WHERE id=?')->execute([$in['active'] ? 1 : 0, $id]);
        audit(admin_name(), 'producto_rapido', 'product', $id, array_intersect_key($in, ['price' => 1, 'stock' => 1, 'active' => 1]));
        ok();
    case 'delete':
        $id = (int)($in['id'] ?? 0);
        $c = $pdo->prepare('SELECT COUNT(*) FROM esim_codes WHERE product_id=?'); $c->execute([$id]);
        if ((int)$c->fetchColumn() > 0) {
            $pdo->prepare('UPDATE products SET active=0 WHERE id=?')->execute([$id]);
            audit(admin_name(), 'producto_desactivado', 'product', $id);
            ok(['deactivated' => true, 'message' => 'El producto tiene códigos eSIM registrados; se desactivó en lugar de eliminarse.']);
        }
        $imgs = $pdo->prepare('SELECT path FROM product_images WHERE product_id=?'); $imgs->execute([$id]);
        foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $p) if (str_starts_with($p, 'uploads/products/')) @unlink(uploads_dir('products/' . basename($p)));
        $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
        audit(admin_name(), 'producto_eliminado', 'product', $id);
        ok(['deleted' => true]);
    case 'category':
        $n = str_in($in, 'name', 120); if (mb_strlen($n) < 3) fail('Nombre inválido', 422);
        $pdo->prepare('INSERT INTO categories (name, slug, sort_order) VALUES (?,?,99)')->execute([$n, unique_cat_slug($pdo, slugify($n))]);
        ok(['id' => (int)$pdo->lastInsertId()]);
    case 'company':
        $n = str_in($in, 'name', 80); if (mb_strlen($n) < 2) fail('Nombre inválido', 422);
        $pdo->prepare('INSERT IGNORE INTO companies (name) VALUES (?)')->execute([$n]);
        ok();
}
function unique_cat_slug(PDO $pdo, string $s): string { $b = $s; $i = 2; while (true) { $st = $pdo->prepare('SELECT 1 FROM categories WHERE slug=?'); $st->execute([$s]); if (!$st->fetch()) return $s; $s = $b . '-' . $i++; } }
fail('Acción no válida');
