<?php
/**
 * Migración inicial idempotente (CLI). Importa sql/schema.sql SOLO si la base de datos no tiene tablas.
 * Uso: php cron/migrate.php [--wait=60]
 * Si ya hay tablas, no hace nada (nunca ejecuta los DROP TABLE del esquema sobre datos existentes).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../api/_lib/config_loader.php';
$cfg = tienda_load_config();
if ($cfg === null) { fwrite(STDERR, "[migrate] Sin configuración de BD (config/config.php o MYSQL*).\n"); exit(1); }
$wait = 60;
foreach ($argv as $a) if (preg_match('/^--wait=(\d+)$/', $a, $m)) $wait = (int)$m[1];
$pdo = null; $t0 = time();
while (true) {
    try { $pdo = tienda_pdo($cfg['db']); break; }
    catch (Throwable $e) {
        if (time() - $t0 >= $wait) { fwrite(STDERR, "[migrate] No se pudo conectar a MySQL tras {$wait}s: " . $e->getMessage() . "\n"); exit(2); }
        fwrite(STDERR, "[migrate] Esperando a MySQL...\n"); sleep(3);
    }
}
$n = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
if ($n > 0) { echo "[migrate] La base ya tiene $n tablas; no se importa nada.\n"; exit(0); }
$sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
if ($sql === false) { fwrite(STDERR, "[migrate] No se encontró sql/schema.sql\n"); exit(3); }
// Quitar comentarios de línea y dividir por ';' al final de línea (el esquema no usa DELIMITER/triggers).
$lines = array_filter(preg_split('/\R/', $sql), fn($l) => !preg_match('/^\s*--/', $l));
$stmts = array_filter(array_map('trim', preg_split('/;\s*(\R|$)/', implode("\n", $lines))));
$count = 0;
foreach ($stmts as $st) { $pdo->exec($st); $count++; }
echo "[migrate] Esquema importado ($count sentencias).\n";
