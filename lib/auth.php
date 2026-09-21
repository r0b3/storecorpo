<?php
// lib/auth.php — login stateless con cookie firmado (path /str/public)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const AUTH_COOKIE   = 'STRAUTH';
const AUTH_LIFETIME = 60 * 60 * 8; // 8 horas

function app_key(): string {
  $cfg = require __DIR__ . '/../config/config.php';
  $k = $cfg['app_key'] ?? '';
  if (!$k) { $k = hash('sha256', __FILE__ . php_uname() . __DIR__); }
  return $k;
}
function cookie_path_scope(): string {
  // Usamos exactamente tu base_url como path del cookie (ej: /str/public)
  $b = rtrim(base_url(), '/'); 
  return $b !== '' ? $b : '/';
}
function cookie_secure(): bool {
  return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}
function b64url_enc(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64url_dec(string $txt): string {
  $pad = 4 - (strlen($txt) % 4); if ($pad < 4) $txt .= str_repeat('=', $pad);
  return base64_decode(strtr($txt, '-_', '+/')) ?: '';
}
function sign_payload(array $payload): string {
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  $sig  = hash_hmac('sha256', $json, app_key(), true);
  return b64url_enc($json) . '.' . b64url_enc($sig);
}
function verify_cookie(string $cookie): ?array {
  $parts = explode('.', $cookie, 2);
  if (count($parts) !== 2) return null;
  [$p,$s] = $parts;
  $json = b64url_dec($p); $sig  = b64url_dec($s);
  if (!$json || !$sig) return null;
  $calc = hash_hmac('sha256', $json, app_key(), true);
  if (!hash_equals($calc, $sig)) return null;
  $data = json_decode($json, true);
  if (!is_array($data)) return null;
  if (!isset($data['exp']) || $data['exp'] < time()) return null;
  return $data;
}
function _setcookie(string $name, string $value, int $expires): void {
  $params = [
    'expires'  => $expires,
    'path'     => cookie_path_scope(), // ← /str/public
    'secure'   => cookie_secure(),
    'httponly' => true,
    'samesite' => 'Lax',
  ];
  if (PHP_VERSION_ID >= 70300) {
    @setcookie($name, $value, $params);
  } else {
    // Fallback PHP <7.3 (sin SameSite garantizado)
    @setcookie($name, $value, $expires, $params['path'], '', $params['secure'], $params['httponly']);
  }
}
function set_auth_cookie(array $user): void {
  $payload = [
    'id'        => (int)$user['id'],
    'username'  => $user['username'] ?? null,
    'email'     => $user['email'] ?? null,
    'full_name' => $user['full_name'] ?? null,
    'role'      => $user['role'] ?? 'Seller',
    'exp'       => time() + AUTH_LIFETIME,
  ];
  _setcookie(AUTH_COOKIE, sign_payload($payload), $payload['exp']);
}
function clear_auth_cookie(): void {
  _setcookie(AUTH_COOKIE, '', time() - 3600);
}

function auth_user(): ?array {
  if (!empty($_COOKIE[AUTH_COOKIE])) {
    $d = verify_cookie($_COOKIE[AUTH_COOKIE]);
    if ($d) {
      if ($d['exp'] - time() < 1800) set_auth_cookie($d); // refresco si <30 min
      return [
        'id'        => (int)($d['id'] ?? 0),
        'username'  => $d['username'] ?? null,
        'email'     => $d['email'] ?? null,
        'full_name' => $d['full_name'] ?? null,
        'role'      => $d['role'] ?? 'Seller',
      ];
    }
  }
  return null;
}

function auth_login(string $user_or_email, string $password): bool {
  $pdo = get_pdo();
  $st = $pdo->prepare("SELECT * FROM users WHERE username=:u OR email=:u LIMIT 1");
  $st->execute([':u'=>$user_or_email]);
  $u = $st->fetch();
  if ($u && password_verify($password, $u['password_hash'])) {
    $arr = [
      'id'        => (int)$u['id'],
      'username'  => $u['username'] ?? null,
      'email'     => $u['email'],
      'full_name' => $u['full_name'],
      'role'      => $u['role'] ?? 'Seller'
    ];
    set_auth_cookie($arr); // ← sin depender de $_SESSION
    return true;
  }
  return false;
}
function auth_logout(): void { clear_auth_cookie(); }

function require_login(): void {
  if (!auth_user()) {
    $next = $_SERVER['REQUEST_URI'] ?? url('index.php');
    header('Location: ' . url('login.php') . '?next=' . urlencode($next), true, 303);
    exit;
  }
}
function require_roles(array $roles): void {
  $u = auth_user();
  if (!$u) require_login();
  $want = array_map(fn($r)=>strtolower(trim($r)), $roles);
  $role = strtolower(trim($u['role'] ?? ''));
  $syn  = [
    'admin'   => ['admin','administrador'],
    'seller'  => ['seller','vendedor','staff'],
    'billing' => ['billing','facturador','facturación','facturacion'],
  ];
  foreach ($want as $w) {
    $set = $syn[$w] ?? [$w];
    if (in_array($role, $set, true)) return;
  }
  http_response_code(403);
  exit('Acceso denegado');
}
