<?php
// lib/helpers.php
require_once __DIR__ . '/db.php';

/* =========================================================
 *  Utilidades básicas (primero porque CSRF las usa)
 * =======================================================*/
function money($n): string { return number_format((float)$n, 0, ',', '.'); }
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function base_url(): string {
  static $b = null;
  if ($b === null) {
    $cfg = require __DIR__ . '/../config/config.php';
    $b = rtrim($cfg['base_url'] ?? '', '/'); // ej: /str/public
  }
  return $b;
}
function url(string $path): string {
  return base_url() . '/' . ltrim($path, '/');
}

/* =========================================================
 *  CSRF: Double-submit cookie (independiente de $_SESSION)
 * =======================================================*/
if (!defined('CSRF_COOKIE')) {
  define('CSRF_COOKIE', 'STRCSRF');
}

/**
 * Detección robusta de HTTPS (soporta proxies / LB)
 */
function is_https_request(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
  $xfp = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
  if ($xfp === 'https') return true;
  $xfs = strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '');
  if ($xfs === 'on' || $xfs === '1') return true;
  return false;
}

/**
 * IMPORTANTE: usa path "/" para evitar que el cookie
 * no se envíe en rutas hermanas (fuente clásica de 403).
 */
function csrf_cookie_path(): string {
  return '/';
}
function csrf_cookie_secure(): bool {
  return is_https_request();
}
function _setcookie_csrf(string $name, string $value, int $expires): void {
  $params = [
    'expires'  => $expires,
    'path'     => csrf_cookie_path(),    // <- ahora siempre "/"
    'secure'   => csrf_cookie_secure(),
    'httponly' => true,                  // no accesible por JS
    'samesite' => 'Lax',
  ];
  if (PHP_VERSION_ID >= 70300) {
    @setcookie($name, $value, $params);
  } else {
    @setcookie($name, $value, $expires, $params['path'], '', $params['secure'], $params['httponly']);
  }
}

/**
 * Genera/recupera el token y asegura el cookie
 */
function csrf_token(): string {
  $tok = $_COOKIE[CSRF_COOKIE] ?? '';
  if (!$tok) {
    $tok = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    _setcookie_csrf(CSRF_COOKIE, $tok, 0); // cookie de sesión
  }
  return $tok;
}

/** Campo oculto para forms */
function csrf_field(): string {
  $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
  return '<input type="hidden" name="csrf" value="'.$t.'">';
}

/**
 * Lee token enviado por:
 *  - POST form-encoded: $_POST['csrf']
 *  - Header: X-CSRF-Token
 *  - JSON body: {"csrf":"..."}
 */
function _read_incoming_csrf(): string {
  // 1) Header para AJAX
  $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (is_string($hdr) && $hdr !== '') return $hdr;

  // 2) application/x-www-form-urlencoded | multipart/form-data
  $post = $_POST['csrf'] ?? '';
  if (is_string($post) && $post !== '') return $post;

  // 3) JSON body
  $ct = strtolower(trim($_SERVER['CONTENT_TYPE'] ?? ''));
  if (strpos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
      $json = json_decode($raw, true);
      if (is_array($json) && !empty($json['csrf']) && is_string($json['csrf'])) {
        return $json['csrf'];
      }
    }
  }
  return '';
}

/**
 * Verificación CSRF para métodos con efectos
 */
function ensure_csrf(): void {
  $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  if (!in_array($method, ['POST','PUT','PATCH','DELETE'], true)) return;

  $cook = $_COOKIE[CSRF_COOKIE] ?? '';
  $incoming = _read_incoming_csrf();
  $ok = $incoming && $cook && hash_equals($cook, $incoming);

  if (!$ok) {
    http_response_code(403);
    // Mensaje conciso (evita filtrar detalles en prod)
    exit('CSRF inválido');
  }
}

/**
 * Exenciones (opcional)
 * Uso: ensure_csrf_exempt(['login.php','acceso.php']);
 */
function ensure_csrf_exempt(array $exemptFiles): void {
  $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  if (!in_array($method, ['POST','PUT','PATCH','DELETE'], true)) return;

  $uri = ($_SERVER['PHP_SELF'] ?? $_SERVER['REQUEST_URI'] ?? '');
  foreach ($exemptFiles as $f) {
    if (substr($uri, -strlen('/'.$f)) === '/'.$f) return; // no validar
  }
  ensure_csrf();
}

/* =========================================================
 *  PRIMERA TAREA: asegurar el cookie CSRF listo
 * =======================================================*/
if (PHP_SAPI !== 'cli') {
  csrf_token(); // genera/asegura cookie CSRF al inicio del request
}

/* =========================================================
 *  Redirect robusto (con fallback si headers ya salieron)
 * =======================================================*/
/* Redirect robusto (sin duplicar /str/public) */
if (!function_exists('redirect')) {
  function redirect(string $to, int $status = 303): void {
    // Si es absoluta (http/https), úsala tal cual
    if (preg_match('#^https?://#i', $to)) {
      // ok tal cual
    }
    // Si empieza con "/", ya es ruta absoluta del sitio -> NO preprendar base_url()
    elseif (isset($to[0]) && $to[0] === '/') {
      // ok tal cual
    }
    // En cualquier otro caso (p.ej. "admin_users.php"), conviértelo con url()
    else {
      if (function_exists('url')) $to = url($to);
    }

    if (!headers_sent()) {
      header('Location: ' . $to, true, $status);
      exit;
    }
    $toEsc = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><meta charset='utf-8'>";
    echo "<meta http-equiv='refresh' content='0;url={$toEsc}'>";
    echo "<title>Redirigiendo…</title>";
    echo "<p>Redirigiendo a <a href='{$toEsc}'>{$toEsc}</a>…</p>";
    echo "<script>try{location.replace('{$toEsc}')}catch(e){location.href='{$toEsc}'}</script>";
    exit;
  }
}

// ---- Guardar imágenes subidas (productos/variantes) ----
// lib/helpers.php
if (!function_exists('save_uploaded_image')) {
  function save_uploaded_image(array $f, string $prefix = 'pv_'): string {
    if (!isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
      throw new RuntimeException('Archivo inválido.');
    }

    // Valida tamaño y tipo
    $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
    $mime = mime_content_type($f['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) throw new RuntimeException('Usa JPG/PNG/WebP.');
    if (($f['size'] ?? 0) > 5*1024*1024) throw new RuntimeException('Máx 5MB.');

    $ext   = $allowed[$mime];
    $base  = bin2hex(random_bytes(8));
    $fname = "{$prefix}{$base}.{$ext}";

    // IMPORTANTE: guardar donde lo sirve el navegador
    // helpers.php está en /str/lib  → queremos /str/public/uploads
    $dir = dirname(__DIR__) . '/public/uploads';

    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    if (!is_writable($dir)) {
      throw new RuntimeException('La carpeta /public/uploads no es escribible.');
    }
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
      throw new RuntimeException('No se pudo guardar la imagen.');
    }
    return $fname; // guardamos solo el nombre de archivo
  }
}
