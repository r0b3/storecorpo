<?php
// public/logout.php
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';

auth_logout();
redirect(safe_next($_GET['next'] ?? null, url('login.php')));
