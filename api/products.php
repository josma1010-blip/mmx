<?php
// Catálogo público: GET ?q=&category=&company=&type=&min=&max=&available=1&sort=&page=  |  GET ?slug=  |  GET ?ids=1,2,3
require __DIR__ . '/_lib/orders.php';
method('GET');
$pdo = db();
$base = 'SELECT p.id, p.name, p.slug, p.short_description, p.price, p.compare_price, p.type, p.available, p.featured, p.is_example,
        c.name AS category, c.slug AS category_slug, co.name AS company, ' . stock_sql() . ' AS stock,
        (SELECT path FROM product_images i WHERE i.product_id=p.id ORDER BY sort_order, id LIMIT 1) AS image
        FROM products p JOIN categories c ON c.id=p.category_id JOIN companies co ON co.id=p.company_id WHERE p.active=1';

function shape(array $r): array {
    $r['id'] = (int)$r['id']; $r['price'] = (float)$r['price']; $r['compare_price'] = $r['compare_price'] !== null ? (float)$r['compare_price'] : null;
    $r['stock'] = (int)$r['stock']; $r['available'] = (bool)$r['available'] && $r['stock'] > 0; $r['featured'] = (bool)$r['featured']; $r['is_example'] = (bool)$r['is_example'];
    return $r;
}

if (isset($_GET['slug'])) {
    $st = $pdo->prepare($base . ' AND p.slug=?');
    $st->execute([substr((string)$_GET['slug'], 0, 200)]);
    $p = $st->fetch();
    if (!$p) fail('Producto no encontrado', 404);
    $d = $pdo->prepare('SELECT description FROM products WHERE id=?'); $d->execute([$p['id']]);
    $p = shape($p); $p['description'] = $d->fetchColumn();
    $im = $pdo->prepare('SELECT path, alt FROM product_images WHERE product_id=? ORDER BY sort_order, id'); $im->execute([$p['id']]);
    $p['images'] = $im->fetchAll();
    ok(['product' => $p]);
}
if (isset($_GET['ids'])) {
    $ids = array_slice(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))), 0, 50);
    if (!$ids) ok(['products' => []]);
    $st = $pdo->prepare($base . ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    ok(['products' => array_map('shape', $st->fetchAll())]);
}
$where = []; $args = [];
if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $where[] = '(p.name LIKE ? OR p.short_description LIKE ? OR co.name LIKE ? OR c.name LIKE ?)'; $like = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%'; array_push($args, $like, $like, $like, $like); }
if (!empty($_GET['category'])) { $where[] = 'c.slug=?'; $args[] = (string)$_GET['category']; }
if (!empty($_GET['company'])) { $where[] = 'co.id=?'; $args[] = (int)$_GET['company']; }
if (in_array($_GET['type'] ?? '', ['esim', 'fisico'], true)) { $where[] = 'p.type=?'; $args[] = $_GET['type']; }
if (is_numeric($_GET['min'] ?? null)) { $where[] = 'p.price>=?'; $args[] = (float)$_GET['min']; }
if (is_numeric($_GET['max'] ?? null)) { $where[] = 'p.price<=?'; $args[] = (float)$_GET['max']; }
if (!empty($_GET['featured'])) $where[] = 'p.featured=1';
$sql = $base . ($where ? ' AND ' . implode(' AND ', $where) : '');
if (!empty($_GET['available'])) $sql = "SELECT * FROM ($sql) t WHERE t.available=1 AND t.stock>0";
else $sql = "SELECT * FROM ($sql) t WHERE 1";
$sort = ['precio_asc' => 'price ASC', 'precio_desc' => 'price DESC', 'nombre' => 'name ASC', 'nuevos' => 'id DESC'][$_GET['sort'] ?? ''] ?? 'featured DESC, (stock>0) DESC, id ASC';
$limit = min(60, max(1, (int)($_GET['limit'] ?? 24)));
$page = max(1, (int)($_GET['page'] ?? 1));
$cnt = $pdo->prepare("SELECT COUNT(*) FROM ($sql) x"); $cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$st = $pdo->prepare("$sql ORDER BY $sort LIMIT $limit OFFSET " . (($page - 1) * $limit));
$st->execute($args);
ok(['products' => array_map('shape', $st->fetchAll()), 'total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $limit)]);
