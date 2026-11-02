<?php
require __DIR__ . '/../_lib/admin_auth.php';
require __DIR__ . '/../_lib/upload.php';
method('POST');
require_admin();
$pdo = db();
$in = input();
$action = str_in($in, 'action', 20) ?: 'upload';
if ($action === 'upload') {
    $pid = (int)($in['product_id'] ?? 0);
    $st = $pdo->prepare('SELECT name FROM products WHERE id=?'); $st->execute([$pid]);
    $pname = $st->fetchColumn();
    if ($pname === false) fail('Producto no encontrado', 404);
    if (empty($_FILES['image'])) fail('Selecciona una imagen', 422);
    try { $name = save_uploaded_image($_FILES['image'], uploads_dir('products')); } catch (UserError $e) { fail($e->getMessage(), 422); }
    $path = 'uploads/products/' . $name;
    $mx = $pdo->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM product_images WHERE product_id=?'); $mx->execute([$pid]);
    $pdo->prepare('INSERT INTO product_images (product_id, path, alt, sort_order) VALUES (?,?,?,?)')->execute([$pid, $path, $pname, (int)$mx->fetchColumn()]);
    audit(admin_name(), 'imagen_subida', 'product', $pid, $path);
    ok(['id' => (int)$pdo->lastInsertId(), 'path' => $path]);
}
$id = (int)($in['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM product_images WHERE id=?'); $st->execute([$id]);
$img = $st->fetch();
if (!$img) fail('Imagen no encontrada', 404);
if ($action === 'delete') {
    $pdo->prepare('DELETE FROM product_images WHERE id=?')->execute([$id]);
    if (str_starts_with($img['path'], 'uploads/products/')) @unlink(uploads_dir('products/') . basename($img['path']));
    audit(admin_name(), 'imagen_eliminada', 'product', $img['product_id'], $img['path']);
    ok();
}
if ($action === 'primary') {
    $pdo->prepare('UPDATE product_images SET sort_order=sort_order+1 WHERE product_id=?')->execute([$img['product_id']]);
    $pdo->prepare('UPDATE product_images SET sort_order=0 WHERE id=?')->execute([$id]);
    ok();
}
fail('Acción no válida');
