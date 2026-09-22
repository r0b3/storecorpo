<?php
// public/checkout.php — Checkout con registro de orden e impacto en inventario

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../views/main.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_gate_private.php'; // portal privado

if (session_status() === PHP_SESSION_NONE) @session_start();

$cfg = require __DIR__ . '/../config/config.php';
$pdo = get_pdo();

// Si el carrito está vacío, vuelve al catálogo
if (empty($_SESSION['cart'])) {
  header('Location: ' . url('index.php')); exit;
}

// Totales
$subtotal = 0.0;
foreach ($_SESSION['cart'] as $it) $subtotal += ((float)$it['price']) * ((int)$it['qty']);
$tax   = 0.0;
$total = $subtotal;


function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :c");
  $st->execute([':c'=>$col]); return (bool)$st->fetch();
}
$hasProductStock = has_column($pdo, 'products', 'stock'); // por si quieres restar stock del producto sin variantes
$hasForArtesanas = has_column($pdo, 'orders', 'for_artesanas');

// POST: crear orden
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  ensure_csrf();

  $payment_method = $_POST['payment_method'] ?? '';
  $customer_type  = $_POST['customer_type'] ?? 'natural';
  $shipping_address = trim($_POST['shipping_address'] ?? '');
  $for_artesanas = !empty($_POST['for_artesanas']) ? 1 : 0;

  // Normaliza y valida método de pago (solo efectivo/transferencia)
  $pm = strtolower($payment_method);
  if (!in_array($pm, ['efectivo','transferencia', 'por_pagar'], true)) {
    $pm = 'efectivo';
  }

  // Campos por tipo
  $company_name = null; $company_nit = null; $person_name = null; $person_id = null;
  $customer_email = trim($_POST['customer_email'] ?? '');
  $customer_phone = trim($_POST['customer_phone'] ?? '');
  $payment_ref    = null;

  if ($pm === 'transferencia') {
    $payment_ref = trim($_POST['payment_ref'] ?? '');
  }

  // Marca para los datos que el cliente no quiso dar. Se guarda en vez de
  // dejar NULL para que en Ventas se distinga "no lo dio" de "falta por
  // capturar", y para que las columnas obligatorias del esquema no fallen.
  $sinDato = 'ND';

  if ($customer_type === 'empresa') {
    // Empresa sigue siendo estricta: el NIT y el correo hacen falta para
    // facturar. Aquí NO se rellena con ND.
    $company_name = trim($_POST['company_name'] ?? '');
    $company_nit  = trim($_POST['company_nit'] ?? '');
    if ($company_name === '' || $company_nit === '') {
      $err = 'Para cliente empresa, nombre de empresa y NIT son obligatorios.';
    }
    if ($customer_email === '')   { $err = 'El correo del cliente es obligatorio.'; }
    if ($shipping_address === '') { $err = 'La dirección es obligatoria.'; }
  } else {
    // Natural: todo opcional. Muchos clientes de mostrador no quieren dar sus
    // datos y el vendedor no puede quedarse sin cerrar la venta por eso.
    $customer_type = 'natural';
    $person_name = trim($_POST['person_name'] ?? '');
    $person_id   = trim($_POST['person_id'] ?? '');

    if ($person_name === '')      { $person_name      = $sinDato; }
    if ($person_id === '')        { $person_id        = $sinDato; }
    if ($customer_email === '')   { $customer_email   = $sinDato; }
    if ($customer_phone === '')   { $customer_phone   = $sinDato; }
    if ($shipping_address === '') { $shipping_address = $sinDato; }
  }
  if (!empty($err)) {
    // Re-render con error
    $cats = $pdo->query("SELECT name, slug FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $form_error = $err;
    return render('Checkout', __DIR__ . '/../views/pages/checkout.php', compact(
      'subtotal','tax','total','cats','form_error','customer_type','pm',
    ));
  }

  // user_id desde cookie de auth (si existe)
  $u = function_exists('auth_user') ? auth_user() : null;
  $user_id = $u['id'] ?? null;

  try {
    $pdo->beginTransaction();

    // Insert order (con for_artesanas si existe la columna)
if ($hasForArtesanas) {
  $sqlO = "INSERT INTO orders
    (user_id, customer_type, company_name, company_nit, person_name, person_id,
     customer_email, customer_phone, shipping_address,
     payment_method, payment_ref, for_artesanas,
     subtotal, tax, total, status, created_at)
    VALUES
    (:user_id, :customer_type, :company_name, :company_nit, :person_name, :person_id,
     :customer_email, :customer_phone, :shipping_address,
     :payment_method, :payment_ref, :for_artesanas,
     :subtotal, :tax, :total, 'pending', NOW())";
  $stO = $pdo->prepare($sqlO);
  $stO->execute([
    ':user_id'          => $user_id ?: null,
    ':customer_type'    => $customer_type,
    ':company_name'     => $company_name ?: null,
    ':company_nit'      => $company_nit ?: null,
    ':person_name'      => $person_name ?: null,
    ':person_id'        => $person_id ?: null,
    ':customer_email'   => $customer_email,
    ':customer_phone'   => $customer_phone ?: null,
    ':shipping_address' => $shipping_address,
    ':payment_method'   => $pm,
    ':payment_ref'      => $payment_ref ?: null,
    ':for_artesanas'    => $for_artesanas,
    ':subtotal'         => $subtotal,
    ':tax'              => 0.0,       // sin impuestos
    ':total'            => $subtotal, // total neto
  ]);
} else {
  $sqlO = "INSERT INTO orders
    (user_id, customer_type, company_name, company_nit, person_name, person_id,
     customer_email, customer_phone, shipping_address,
     payment_method, payment_ref,
     subtotal, tax, total, status, created_at)
    VALUES
    (:user_id, :customer_type, :company_name, :company_nit, :person_name, :person_id,
     :customer_email, :customer_phone, :shipping_address,
     :payment_method, :payment_ref,
     :subtotal, :tax, :total, 'pending', NOW())";
  $stO = $pdo->prepare($sqlO);
  $stO->execute([
    ':user_id'          => $user_id ?: null,
    ':customer_type'    => $customer_type,
    ':company_name'     => $company_name ?: null,
    ':company_nit'      => $company_nit ?: null,
    ':person_name'      => $person_name ?: null,
    ':person_id'        => $person_id ?: null,
    ':customer_email'   => $customer_email,
    ':customer_phone'   => $customer_phone ?: null,
    ':shipping_address' => $shipping_address,
    ':payment_method'   => $pm,
    ':payment_ref'      => $payment_ref ?: null,
    ':subtotal'         => $subtotal,
    ':tax'              => 0.0,       // sin impuestos
    ':total'            => $subtotal, // total neto
  ]);
}


    $order_id = (int)$pdo->lastInsertId();

    // Insert items y descuenta inventario
    $sqlI = "INSERT INTO order_items
      (order_id, product_id, variant_id, sku, product_name, variant_label, unit_price, quantity, line_total)
      VALUES
      (:order_id, :product_id, :variant_id, :sku, :product_name, :variant_label, :unit_price, :quantity, :line_total)";
    $stI = $pdo->prepare($sqlI);

    $stDecVar = $pdo->prepare("UPDATE product_variants SET stock = GREATEST(stock - :q, 0) WHERE id = :vid");
    $stDecProd = $hasProductStock
      ? $pdo->prepare("UPDATE products SET stock = GREATEST(stock - :q, 0) WHERE id = :pid")
      : null;

    foreach ($_SESSION['cart'] as $key => $it) {
      $pid   = (int)($it['pid'] ?? 0);
      $vid   = isset($it['vid']) ? (int)$it['vid'] : null;
      $sku   = $it['sku'] ?? null;
      $pname = (string)($it['name'] ?? 'Producto');
      $vlabel= (string)($it['variant'] ?? '');
      $qty   = max(1, (int)($it['qty'] ?? 1));
      $price = (float)($it['price'] ?? 0.0);
      $line  = round($qty * $price, 2);

      // Inserta item
      $stI->execute([
        ':order_id'     => $order_id,
        ':product_id'   => $pid,
        ':variant_id'   => $vid ?: null,
        ':sku'          => $sku ?: null,
        ':product_name' => $pname,
        ':variant_label'=> $vlabel !== '' ? $vlabel : null,
        ':unit_price'   => $price,
        ':quantity'     => $qty,
        ':line_total'   => $line,
      ]);

      // Descuenta inventario
      if ($vid) {
        $stDecVar->execute([':q'=>$qty, ':vid'=>$vid]);
      } elseif ($stDecProd) {
        $stDecProd->execute([':q'=>$qty, ':pid'=>$pid]);
      }
    }

    // Marca pagado si el método es efectivo (puedes ajustarlo a tu flujo)
    // Si prefieres manual: deja siempre pending
    if ($pm === 'efectivo') {
      $pdo->prepare("UPDATE orders SET status='paid' WHERE id=:id")->execute([':id'=>$order_id]);
    }

    $pdo->commit();

    // Limpia carrito y envía a gracias
    $_SESSION['last_order_total'] = $total;
    $_SESSION['last_order_id']    = $order_id;
    $_SESSION['cart'] = [];
    header('Location: ' . url('order_success.php')); exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[checkout] ' . $e->getMessage());
    $cats = $pdo->query("SELECT name, slug FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $form_error = 'No se pudo registrar la orden: ' . $e->getMessage();
    return render('Checkout', __DIR__ . '/../views/pages/checkout.php', compact(
      'subtotal','tax','total','cats','form_error','customer_type','pm'
    ));
  }
}

// Categorías para navbar
$cats = $pdo->query("SELECT name, slug FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Render vista
render('Checkout', __DIR__ . '/../views/pages/checkout.php', [
  'subtotal' => $subtotal,
  'tax'      => $tax,
  'total'    => $total,
  'cats'     => $cats,
]);
