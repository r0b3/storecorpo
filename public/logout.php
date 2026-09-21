<?php
// public/logout.php
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';

auth_logout();
$next = $_GET['next'] ?? url('login.php');
redirect($next);
