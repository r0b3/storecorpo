<?php
// public/_gate_private.php — puerta de acceso para todo el portal privado
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';

// Si NO estás en login/logout, exige login
$script = basename($_SERVER['PHP_SELF'] ?? '');
if (!in_array($script, ['login.php','logout.php'], true)) {
  require_login(); // redirige a login con ?next=...
}
