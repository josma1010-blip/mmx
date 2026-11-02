<?php
require __DIR__ . '/../_lib/admin_auth.php';
method('GET');
require_admin();
ok(['log' => db()->query('SELECT id, actor, action, entity, entity_id, details, ip, created_at FROM audit_log ORDER BY id DESC LIMIT 300')->fetchAll()]);
