<?php
// public/cart.php — carrito con soporte AJAX y variantes

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../views/main.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_gate_private.php'; // portal privado

// 1) Sesión SIEMPRE
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

// 2) CSRF: valida solo POST (si WAF molesta, temporalmente usa ensure_csrf_exempt(['cart.php']))
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  ensure_csrf();
}

$pdo = get_pdo();
$cfg = require __DIR__ . '/../config/config.php';

// 3) Carrito en sesión
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) { $_SESSION['cart'] = []; }

/* ===== Helpers locales ===== */
function is_ajax_request(): bool {
  $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
  $xr     = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
  return (strpos($accept, 'application/json') !== false) || ($xr === 'fetch' || $xr === 'xmlhttprequest');
}
function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :c");
  $st->execute([':c'=>$col]); return (bool)$st->fetch();
}
function first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (has_column($pdo,$table,$c)) return $c;
  return null;
}
function product_image_col(PDO $pdo): ?string {
  return first_existing_column($pdo,'products',['image','cover','photo','picture','img']);
}
function cart_key(int $pid, ?int $vid): string { return $pid . ':' . (string)($vid ?? 0); }
function cart_totals(array $cart, float $tax_rate): array {
  $items = 0; $subtotal = 0.0;
  foreach ($cart as $it) {
    $qty   = (int)($it['qty'] ?? 0);
    $price = (float)($it['price'] ?? 0);
    $items    += $qty;
    $subtotal += $qty * $price;
  }
  $tax   = round($subtotal * $tax_rate, 2);
  $total = round($subtotal + $tax, 2);
  return [$items, $subtotal, $tax, $total];
}
function json_out(array $payload, int $status = 200): void {
  if (!headers_sent()) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if (!function_exists('redirect')) {
  function redirect(string $to, int $status = 303): void {
    if (!preg_match('#^https?://#i',$to) && (!isset($to[0]) || $to[0] !== '/')) {
      if (function_exists('url')) $to = url($to);
    }
    if (!headers_sent()) { header('Location: '.$to, true, $status); exit; }
    $toEsc = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><meta http-equiv='refresh' content='0;url={$toEsc}'>";
    echo "<p>Redirigiendo a <a href='{$toEsc}'>{$toEsc}</a>…</p>";
    exit;
  }
}

/* ===== Lógica: agregar con variantes ===== */
function cart_add(PDO $pdo, int $pid, ?int $vid, int $qty): void {
  if ($qty < 1) $qty = 1;

  $imgCol = product_image_col($pdo);
  $imgSQL = $imgCol ? ", {$imgCol} AS image" : "";
  $st = $pdo->prepare("SELECT id, name, base_price{$imgSQL} FROM products WHERE id=:id");
  $st->execute([':id'=>$pid]);
  $p = $st->fetch(PDO::FETCH_ASSOC);
  if (!$p) throw new RuntimeException('Producto no encontrado.');

  $name  = (string)$p['name'];
  $price = (float)$p['base_price'];
  $image = $p['image'] ?? null;
  $sku   = null;
  $variantLabel = '';

  if ($vid) {
    $sv = $pdo->prepare("SELECT id, sku, price, stock, active,
                                option1_name, option1_value,
                                option2_name, option2_value"
                       . (has_column($pdo, 'product_variants', 'image') ? ", image" : "") . "
                         FROM product_variants
                         WHERE id=:vid AND product_id=:pid");
    $sv->execute([':vid'=>$vid, ':pid'=>$pid]);
    $v = $sv->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new RuntimeException('Variante no encontrada.');
    if (isset($v['active']) && (int)$v['active'] !== 1) throw new RuntimeException('Variante inactiva.');

    if ($v['price'] !== null) $price = (float)$v['price'];
    $sku = $v['sku'] ?: null;
    // La foto de la variante (el color concreto) manda sobre la del producto.
    if (!empty($v['image'])) $image = $v['image'];

    $parts = [];
    $o1n = trim((string)($v['option1_name'] ?? '')); $o1v = trim((string)($v['option1_value'] ?? ''));
    $o2n = trim((string)($v['option2_name'] ?? '')); $o2v = trim((string)($v['option2_value'] ?? ''));
    if ($o1n !== '' || $o1v !== '') $parts[] = ($o1n !== '' ? $o1n.': ' : '').($o1v !== '' ? $o1v : '');
    if ($o2n !== '' || $o2v !== '') $parts[] = ($o2n !== '' ? $o2n.': ' : '').($o2v !== '' ? $o2v : '');
    $variantLabel = implode(' / ', array_filter($parts, fn($x)=>trim($x) !== ''));

    // Tope por stock si existe
    if (isset($v['stock'])) {
      $current = (int)($_SESSION['cart'][cart_key($pid, $vid)]['qty'] ?? 0);
      $maxAdd  = max(0, (int)$v['stock'] - $current);
      if ($maxAdd <= 0) throw new RuntimeException('Sin stock disponible para esa variante.');
      if ($qty > $maxAdd) $qty = $maxAdd;
    }
  }

  $key = cart_key($pid, $vid);
  if (!isset($_SESSION['cart'][$key])) {
    $_SESSION['cart'][$key] = [
      'pid'     => $pid,
      'vid'     => $vid,
      'name'    => $name,
      'price'   => $price,
      'qty'     => 0,
      'sku'     => $sku,
      'image'   => $image ?: null,
      'variant' => $variantLabel ?: '',
    ];
  }
  $_SESSION['cart'][$key]['qty'] += $qty;
}

function cart_update(array $qtys): void {
  foreach ($qtys as $key=>$q) {
    $qty = (int)$q;
    if ($qty <= 0) unset($_SESSION['cart'][$key]);
    else if (isset($_SESSION['cart'][$key])) $_SESSION['cart'][$key]['qty'] = $qty;
  }
}

/* ===== Acciones ===== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'add') {
      $pid = (int)($_POST['product_id'] ?? $_POST['id'] ?? 0);
      $vid = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? (int)$_POST['variant_id'] : null;
      $qty = max(1, (int)($_POST['qty'] ?? 1));
      if ($pid <= 0) throw new RuntimeException('Producto inválido.');

      cart_add($pdo, $pid, $vid, $qty);

      // Escribe sesión antes de responder
      if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }

      // Calcula totales (sin impuestos)
[$items, $subtotal, $tax, $total] = cart_totals($_SESSION['cart'], 0.0);
$tax = 0.0;
$total = $subtotal;

// ✅ JSON si es AJAX / ajax=1
if (is_ajax_request() || isset($_POST['ajax'])) {
  json_out([
    'ok'       => true,
    'message'  => 'Producto agregado',
    'items'    => $items,
    'lines'    => count($_SESSION['cart']),
    'subtotal' => $subtotal,
    'tax'      => $tax,
    'total'    => $total,
  ]);
}

      // Fallback: PRG
      redirect('cart.php', 303);
    }

    if ($action === 'update') {
      $qtys = $_POST['qty'] ?? [];
      if (!is_array($qtys)) $qtys = [];
      cart_update($qtys);
      redirect('cart.php', 303);
    }

    if ($action === 'clear') {
      $_SESSION['cart'] = [];
      if (is_ajax_request() || isset($_POST['ajax'])) {
        json_out(['ok'=>true,'message'=>'Carrito vaciado','items'=>0,'lines'=>0,'subtotal'=>0,'tax'=>0,'total'=>0]);
      }
      redirect('cart.php', 303);
    }

    // Acción desconocida
    if (is_ajax_request() || isset($_POST['ajax'])) json_out(['ok'=>false,'message'=>'Acción no reconocida'], 400);
    redirect('cart.php?'.http_build_query(['ok'=>'0','msg'=>'Acción no reconocida']), 303);

  } catch (Throwable $e) {
    error_log('[cart POST] '.$e->getMessage());
    if (is_ajax_request() || isset($_POST['ajax'])) json_out(['ok'=>false,'message'=>$e->getMessage()], 400);
    redirect('cart.php?'.http_build_query(['ok'=>'0','msg'=>$e->getMessage()]), 303);
  }
}

/* ===== GET (página carrito) ===== */
$subtotal = 0.0;
foreach ($_SESSION['cart'] as $it) {
  $subtotal += ((float)($it['price'] ?? 0)) * ((int)($it['qty'] ?? 0));
}
$tax   = 0.0;
$total = $subtotal;

$cats = $pdo->query("SELECT name, slug FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$flash_ok  = ($_GET['ok'] ?? '') === '1';
$flash_msg = trim($_GET['msg'] ?? '');

render('Tienda - Carrito', __DIR__ . '/../views/pages/cart.php', [
  'subtotal'  => $subtotal,
  'tax'       => $tax,        // (sigue llegando 0 a la vista por si lo usas)
  'total'     => $total,
  'cats'      => $cats,
  'flash_ok'  => $flash_ok,
  'flash_msg' => $flash_msg,
]);

