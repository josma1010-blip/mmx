<?php
require __DIR__ . '/../_lib/admin_auth.php';
method('GET');
start_session();
$csrf = csrf_token();
if (empty($_SESSION['admin_id'])) ok(['logged' => false, 'csrf' => $csrf]);
$a = require_admin(true);
ok(['logged' => true, 'username' => $a['username'], 'must_change_password' => (bool)$a['must_change_password'], 'csrf' => $csrf, 'store_name' => setting('store_name')]);
