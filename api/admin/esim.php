<?php
require __DIR__ . '/../_lib/admin_auth.php';
require __DIR__ . '/../_lib/upload.php';
require __DIR__ . '/../_lib/qr.php';
require_admin();
$pdo = db();
$QRDIR = uploads_dir('private/qr');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['qr'])) { // vista previa QR solo para admin
        $st = $pdo->prepare('SELECT activation_code, qr_image FROM esim_codes WHERE id=?'); $st->execute([(int)$_GET['qr']]);
        $c = $st->fetch(); if (!$c) { http_response_code(404); exit; }
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        $f = $c['qr_image'] ? realpath($QRDIR . '/' . basename($c['qr_image'])) : false;
        if ($f && is_file($f)) { header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($f)); readfile($f); exit; }
        header('Content-Type: image/svg+xml'); echo QrCode::svg($c['activation_code']); exit;
    }
    $w = []; $args = [];
    if (!empty($_GET['product_id'])) { $w[] = 'e.product_id=?'; $args[] = (int)$_GET['product_id']; }
    if (!empty($_GET['status'])) { $w[] = 'e.status=?'; $args[] = (string)$_GET['status']; }
    if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $w[] = '(e.iccid LIKE ? OR e.activation_code LIKE ?)'; $l = '%' . addcslashes($q, '%_\\') . '%'; array_push($args, $l, $l); }
    $st = $pdo->prepare('SELECT e.id, e.product_id, p.name product, e.iccid, e.activation_code, e.smdp_address, e.confirmation_code, e.phone_number, e.status, (e.qr_image IS NOT NULL) has_qr,
        o.order_number, e.created_at, e.delivered_at FROM esim_codes e JOIN products p ON p.id=e.product_id LEFT JOIN orders o ON o.id=e.order_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY e.id DESC LIMIT 500');
    $st->execute($args);
    $sum = $pdo->query("SELECT p.id, p.name, SUM(e.status='disponible') disponible, SUM(e.status='reservado') reservado, SUM(e.status='entregado') entregado, SUM(e.status='anulado') anulado
        FROM products p LEFT JOIN esim_codes e ON e.product_id=p.id WHERE p.type='esim' GROUP BY p.id, p.name ORDER BY p.name")->fetchAll();
    ok(['codes' => $st->fetchAll(), 'summary' => $sum]);
}
method('POST');
$in = input();

function esim_product(PDO $pdo, int $pid): void {
    $st = $pdo->prepare("SELECT 1 FROM products WHERE id=? AND type='esim'"); $st->execute([$pid]);
    if (!$st->fetch()) fail('Selecciona un producto de tipo eSIM', 422);
}
function insert_code(PDO $pdo, int $pid, array $r): void {
    $iccid = preg_replace('/\s+/', '', (string)($r['iccid'] ?? ''));
    $act = trim((string)($r['activation_code'] ?? ''));
    if (!preg_match('/^[0-9A-Za-z\-]{6,40}$/', $iccid)) throw new UserError('ICCID inválido');
    if (strlen($act) < 8 || strlen($act) > 500) throw new UserError('Código de activación inválido');
    $smdp = trim((string)($r['smdp_address'] ?? ''));
    if ($smdp === '' && preg_match('/^LPA:1\$([^$]+)\$/', $act, $m)) $smdp = $m[1];
    $pdo->prepare('INSERT INTO esim_codes (product_id, iccid, activation_code, smdp_address, confirmation_code, phone_number, notes) VALUES (?,?,?,?,?,?,?)')
        ->execute([$pid, $iccid, $act, mb_substr($smdp, 0, 190) ?: null, mb_substr(trim((string)($r['confirmation_code'] ?? '')), 0, 60) ?: null, mb_substr(trim((string)($r['phone_number'] ?? '')), 0, 20) ?: null, mb_substr(trim((string)($r['notes'] ?? '')), 0, 255) ?: null]);
}

switch (str_in($in, 'action', 20)) {
    case 'add':
        $pid = (int)($in['product_id'] ?? 0); esim_product($pdo, $pid);
        try { insert_code($pdo, $pid, $in); }
        catch (UserError $e) { fail($e->getMessage(), 422); }
        catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) == 1062) fail('Duplicado: ese ICCID o código de activación ya existe', 409); throw $e; }
        $id = (int)$pdo->lastInsertId();
        if (!empty($_FILES['qr']) && $_FILES['qr']['error'] !== UPLOAD_ERR_NO_FILE) {
            try { $n = save_uploaded_image($_FILES['qr'], $QRDIR); $pdo->prepare('UPDATE esim_codes SET qr_image=? WHERE id=?')->execute([$n, $id]); }
            catch (UserError $e) { $pdo->prepare('DELETE FROM esim_codes WHERE id=?')->execute([$id]); fail($e->getMessage(), 422); }
        }
        audit(admin_name(), 'esim_alta', 'esim_code', $id);
        ok(['id' => $id]);
    case 'import':
        $pid = (int)($in['product_id'] ?? 0); esim_product($pdo, $pid);
        $csv = '';
        if (!empty($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['csv']['size'] > 2 * 1048576) fail('CSV demasiado grande (máx 2 MB)', 422);
            $csv = (string)file_get_contents($_FILES['csv']['tmp_name']);
        } else $csv = (string)($in['csv_text'] ?? '');
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (!$lines || trim($lines[0]) === '') fail('CSV vacío', 422);
        $delim = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $head = array_map(fn($h) => strtolower(trim($h)), str_getcsv($lines[0], $delim, '"', '\\'));
        $cols = ['iccid', 'activation_code', 'smdp_address', 'confirmation_code', 'phone_number', 'notes'];
        $hasHeader = in_array('iccid', $head, true);
        $map = $hasHeader ? $head : array_slice($cols, 0, count($head));
        $res = ['inserted' => 0, 'duplicates' => [], 'errors' => []];
        foreach (array_slice($lines, $hasHeader ? 1 : 0) as $i => $line) {
            if (trim($line) === '') continue;
            if ($res['inserted'] + count($res['duplicates']) + count($res['errors']) >= 5000) { $res['errors'][] = 'Límite de 5000 filas por importación'; break; }
            $vals = str_getcsv($line, $delim, '"', '\\'); $row = [];
            foreach ($map as $k => $c) $row[$c] = $vals[$k] ?? '';
            $ln = $i + ($hasHeader ? 2 : 1);
            try { insert_code($pdo, $pid, $row); $res['inserted']++; }
            catch (UserError $e) { $res['errors'][] = "Línea $ln: " . $e->getMessage(); }
            catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) == 1062) $res['duplicates'][] = "Línea $ln: " . ($row['iccid'] ?? ''); else throw $e; }
        }
        audit(admin_name(), 'esim_importacion', 'product', $pid, ['insertados' => $res['inserted'], 'duplicados' => count($res['duplicates']), 'errores' => count($res['errors'])]);
        ok($res);
    case 'qr':
        $id = (int)($in['id'] ?? 0);
        if (empty($_FILES['qr'])) fail('Selecciona la imagen QR', 422);
        try { $n = save_uploaded_image($_FILES['qr'], $QRDIR); } catch (UserError $e) { fail($e->getMessage(), 422); }
        $old = $pdo->prepare('SELECT qr_image FROM esim_codes WHERE id=?'); $old->execute([$id]); $o = $old->fetchColumn();
        if ($o) @unlink($QRDIR . '/' . basename($o));
        $pdo->prepare('UPDATE esim_codes SET qr_image=? WHERE id=?')->execute([$n, $id]);
        audit(admin_name(), 'esim_qr', 'esim_code', $id);
        ok();
    case 'void':
        $id = (int)($in['id'] ?? 0);
        $st = $pdo->prepare("UPDATE esim_codes SET status='anulado', order_id=IF(status='reservado', NULL, order_id), order_item_id=IF(status='reservado', NULL, order_item_id) WHERE id=? AND status IN ('disponible','reservado','entregado')");
        $st->execute([$id]);
        if (!$st->rowCount()) fail('No se pudo anular', 422);
        audit(admin_name(), 'esim_anulada', 'esim_code', $id);
        ok();
    case 'restore':
        $id = (int)($in['id'] ?? 0);
        $st = $pdo->prepare("UPDATE esim_codes SET status='disponible' WHERE id=? AND status='anulado' AND order_id IS NULL"); $st->execute([$id]);
        if (!$st->rowCount()) fail('Solo se pueden reactivar códigos anulados sin pedido', 422);
        audit(admin_name(), 'esim_reactivada', 'esim_code', $id);
        ok();
    case 'delete':
        $id = (int)($in['id'] ?? 0);
        $q = $pdo->prepare("SELECT qr_image FROM esim_codes WHERE id=? AND order_id IS NULL AND status IN ('disponible','anulado')"); $q->execute([$id]);
        $f = $q->fetch(); if (!$f) fail('Solo se eliminan códigos sin pedido (disponibles o anulados)', 422);
        $pdo->prepare('DELETE FROM esim_codes WHERE id=?')->execute([$id]);
        if ($f['qr_image']) @unlink($QRDIR . '/' . basename($f['qr_image']));
        audit(admin_name(), 'esim_eliminada', 'esim_code', $id);
        ok();
}
fail('Acción no válida');
