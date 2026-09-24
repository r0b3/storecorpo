<?php
// public/admin_sales.php — Historial de ventas + reversión

require_once __DIR__ . '/../lib/helpers.php'; // url(), csrf_field(), ensure_csrf(), redirect(), money(), e()
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../views/main.php';
require_once __DIR__ . '/_gate_private.php';

// Autorización ANTES de cualquier manejador POST. El de anulación de ventas
// (action=reverse) se procesa más abajo, así que un rol sin permiso (Seller)
// llegaba a anular órdenes y restituir stock si el chequeo quedaba después.
require_roles(['Admin','Billing']);

/* ====== Helpers locales ====== */
function table_exists(PDO $pdo, string $name): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
  $st->execute([':t'=>$name]); return (bool)$st->fetchColumn();
}
function columns(PDO $pdo, string $table): array {
  $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
  $st->execute([':t'=>$table]);
  return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
}
function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
  $st->execute([':t'=>$table, ':c'=>$col]); return (bool)$st->fetchColumn();
}
function pick(array $row, array $cands, $default=null) {
  foreach ($cands as $c) {
    if (isset($row[$c])) return $row[$c];
    $lc = strtolower($c);
    if (isset($row[$lc])) return $row[$lc];
  }
  return $default;
}
/** ¿El ENUM de status acepta este valor? Sin la migración, escribir 'gift'
 *  fallaría (modo estricto) o guardaría '' (no estricto): mejor avisar. */
function status_admite(PDO $pdo, string $table, string $valor): bool {
  $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = 'status'");
  $st->execute([':t'=>$table]);
  $tipo = (string)$st->fetchColumn();
  return stripos($tipo, 'enum(') !== 0 || stripos($tipo, "'" . $valor . "'") !== false;
}

/* === Observación / nota de la venta (POST) ========================== */
// Se permite también en anuladas: muchas veces la nota explica justamente por
// qué se anuló. El rol ya está exigido arriba (Admin/Billing).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_note') {
  ensure_csrf();
  $oid = (int)($_POST['order_id'] ?? 0);
  if ($oid <= 0) { redirect('admin_sales.php?ok=0&msg=' . urlencode('ID de venta inválido')); }

  $pdo = get_pdo();
  $ordersT = table_exists($pdo,'orders') ? 'orders' : (table_exists($pdo,'sales') ? 'sales' : null);
  if (!$ordersT || !has_column($pdo, $ordersT, 'notas')) {
    redirect('admin_sales.php?ok=0&msg=' . urlencode('Esta instalación no tiene columna de notas.'));
  }

  try {
    $texto = trim((string)($_POST['notas'] ?? ''));
    $texto = mb_substr($texto, 0, 1000);

    $u   = function_exists('auth_user') ? auth_user() : null;
    $uid = $u['id'] ?? null;

    // Vaciar el campo borra la nota: guardar '' dejaría una observación en
    // blanco que en la vista se ve como un recuadro vacío.
    $set = "notas = :n";
    $params = [':n' => ($texto !== '' ? $texto : null), ':id' => $oid];
    if (has_column($pdo, $ordersT, 'notas_at')) { $set .= ", notas_at = NOW()"; }
    if (has_column($pdo, $ordersT, 'notas_by') && $uid) { $set .= ", notas_by = :uid"; $params[':uid'] = $uid; }

    $st = $pdo->prepare("UPDATE {$ordersT} SET {$set} WHERE id = :id");
    $st->execute($params);
    if ($st->rowCount() === 0) {
      $chk = $pdo->prepare("SELECT 1 FROM {$ordersT} WHERE id = :id");
      $chk->execute([':id'=>$oid]);
      if (!$chk->fetchColumn()) { throw new RuntimeException('Venta no encontrada.'); }
    }

    redirect('admin_sales.php?ok=1&msg=' . urlencode($texto !== '' ? 'Observación guardada.' : 'Observación eliminada.'));
  } catch (Throwable $e) {
    error_log('[save_note] ' . $e->getMessage());
    redirect('admin_sales.php?ok=0&msg=' . urlencode($e->getMessage()));
  }
}
/* === /Observación ================================================== */

/* === Marcar venta como pagada (POST) ================================ */
// El rol ya está exigido arriba (Admin/Billing) antes de cualquier manejador.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
  ensure_csrf();
  $oid = (int)($_POST['order_id'] ?? 0);
  if ($oid <= 0) { redirect('admin_sales.php?ok=0&msg=' . urlencode('ID de venta inválido')); }

  $pdo = get_pdo();
  $ordersT = table_exists($pdo,'orders') ? 'orders' : (table_exists($pdo,'sales') ? 'sales' : null);
  if (!$ordersT) { redirect('admin_sales.php?ok=0&msg=' . urlencode('No existe la tabla de ventas.')); }

  try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT id, status FROM {$ordersT} WHERE id=:id FOR UPDATE");
    $st->execute([':id'=>$oid]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) { throw new RuntimeException('Venta no encontrada.'); }

    $estado = strtolower((string)$order['status']);
    // Una venta anulada no se cobra: habría que reactivarla primero, y eso es
    // otra decisión (el stock ya se devolvió).
    if ($estado === 'cancelled') { throw new RuntimeException('La venta está anulada: no se puede marcar como pagada.'); }
    if ($estado === 'paid')      { throw new RuntimeException('Esa venta ya estaba pagada.'); }

    $u   = function_exists('auth_user') ? auth_user() : null;
    $uid = $u['id'] ?? null;

    // El comprobante es opcional: a veces se cobra en efectivo y no hay papel.
    // Se guarda aparte de payment_ref, que es la referencia capturada en el
    // checkout: son dos momentos distintos y perder uno para poner el otro
    // borraría información.
    $paidRef = trim((string)($_POST['paid_ref'] ?? ''));

    $set = "status='paid'";
    $params = [':id'=>$oid];
    if (has_column($pdo, $ordersT, 'paid_at')) { $set .= ", paid_at = NOW()"; }
    if (has_column($pdo, $ordersT, 'paid_by') && $uid) { $set .= ", paid_by = :uid"; $params[':uid'] = $uid; }
    if ($paidRef !== '' && has_column($pdo, $ordersT, 'paid_ref')) {
      $set .= ", paid_ref = :ref"; $params[':ref'] = mb_substr($paidRef, 0, 120);
    }
    $pdo->prepare("UPDATE {$ordersT} SET {$set} WHERE id = :id")->execute($params);

    $pdo->commit();
    redirect('admin_sales.php?ok=1&msg=' . urlencode('Venta marcada como pagada.'));
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[mark_paid] ' . $e->getMessage());
    redirect('admin_sales.php?ok=0&msg=' . urlencode($e->getMessage()));
  }
}
/* === /Marcar como pagada =========================================== */

/* === Dar como obsequio (POST) ======================================= */
// Solo desde pendiente: una venta ya cobrada no se regala después, y una
// anulada ya devolvió su stock. El stock NO se restituye (el producto sí
// salió del stand); lo único que cambia es que deja de contar como venta.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'mark_gift') {
  ensure_csrf();
  $oid = (int)($_POST['order_id'] ?? 0);
  if ($oid <= 0) { redirect('admin_sales.php?ok=0&msg=' . urlencode('ID de venta inválido')); }

  $pdo = get_pdo();
  $ordersT = table_exists($pdo,'orders') ? 'orders' : (table_exists($pdo,'sales') ? 'sales' : null);
  if (!$ordersT || !status_admite($pdo, $ordersT, 'gift')) {
    redirect('admin_sales.php?ok=0&msg=' . urlencode('Esta instalación no admite el estado obsequio.'));
  }

  try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT id, status FROM {$ordersT} WHERE id=:id FOR UPDATE");
    $st->execute([':id'=>$oid]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) { throw new RuntimeException('Venta no encontrada.'); }
    if (strtolower((string)$order['status']) !== 'pending') {
      throw new RuntimeException('Solo una venta pendiente se puede dar como obsequio.');
    }

    $u   = function_exists('auth_user') ? auth_user() : null;
    $uid = $u['id'] ?? null;
    $motivo = trim((string)($_POST['gift_note'] ?? ''));

    $set = "status='gift'";
    $params = [':id'=>$oid];
    if (has_column($pdo, $ordersT, 'gift_at')) { $set .= ", gift_at = NOW()"; }
    if (has_column($pdo, $ordersT, 'gift_by') && $uid) { $set .= ", gift_by = :uid"; $params[':uid'] = $uid; }
    if ($motivo !== '' && has_column($pdo, $ordersT, 'gift_note')) {
      $set .= ", gift_note = :gn"; $params[':gn'] = mb_substr($motivo, 0, 255);
    }
    $pdo->prepare("UPDATE {$ordersT} SET {$set} WHERE id = :id")->execute($params);

    $pdo->commit();
    redirect('admin_sales.php?ok=1&msg=' . urlencode('Venta registrada como obsequio: ya no suma en el consolidado.'));
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[mark_gift] ' . $e->getMessage());
    redirect('admin_sales.php?ok=0&msg=' . urlencode($e->getMessage()));
  }
}
/* === /Obsequio ===================================================== */

/* === Reversión de venta (POST) ====================================== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reverse') {
  ensure_csrf();
  $oid = (int)($_POST['order_id'] ?? 0);
  if ($oid <= 0) { redirect('admin_sales.php?ok=0&msg=' . urlencode('ID de venta inválido')); }

  $pdo = get_pdo();
  $ordersT = table_exists($pdo,'orders') ? 'orders' : (table_exists($pdo,'sales') ? 'sales' : null);
  $itemsT  = table_exists($pdo,'order_items') ? 'order_items' : (table_exists($pdo,'sales_items') ? 'sales_items' : null);
  if (!$ordersT || !$itemsT) { redirect('admin_sales.php?ok=0&msg=' . urlencode('No existen tablas de ventas/ítems.')); }

  try {
    $pdo->beginTransaction();

    // Bloquea y lee estado de la orden
    $st = $pdo->prepare("SELECT id, status FROM {$ordersT} WHERE id=:id FOR UPDATE");
    $st->execute([':id'=>$oid]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) { throw new RuntimeException('Venta no encontrada.'); }

    if (strtolower((string)$order['status']) === 'cancelled') {
      $pdo->rollBack();
      redirect('admin_sales.php?ok=0&msg=' . urlencode('La venta ya está anulada.'));
    }

    // Carga ítems
    $it = $pdo->prepare("SELECT product_id, variant_id, quantity FROM {$itemsT} WHERE order_id = :oid");
    $it->execute([':oid'=>$oid]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    // Qué columnas existen
    $hasProdStock   = has_column($pdo, 'products', 'stock');
    $hasVarStock    = has_column($pdo, 'product_variants', 'stock');
    $hasCancelledAt = has_column($pdo, $ordersT, 'cancelled_at');
    $hasCancelledBy = has_column($pdo, $ordersT, 'cancelled_by');

    $u = function_exists('auth_user') ? auth_user() : null;
    $uid = $u['id'] ?? null;

    // Restituye inventario
    if ($items) {
      if ($hasVarStock) {
        $incVar = $pdo->prepare("UPDATE product_variants SET stock = stock + :q WHERE id = :vid");
        foreach ($items as $row) {
          $vid = (int)($row['variant_id'] ?? 0);
          $q   = max(0, (int)$row['quantity']);
          if ($vid > 0 && $q > 0) { $incVar->execute([':q'=>$q, ':vid'=>$vid]); }
        }
      }
      if ($hasProdStock) {
        $incProd = $pdo->prepare("UPDATE products SET stock = stock + :q WHERE id = :pid");
        foreach ($items as $row) {
          $pid = (int)($row['product_id'] ?? 0);
          $vid = (int)($row['variant_id'] ?? 0);
          $q   = max(0, (int)$row['quantity']);
          if ($pid > 0 && $vid === 0 && $q > 0) { $incProd->execute([':q'=>$q, ':pid'=>$pid]); }
        }
      }
    }

    // Marca orden como cancelada
    $set = "status='cancelled'";
    $params = [':id'=>$oid];
    if ($hasCancelledAt) { $set .= ", cancelled_at = NOW()"; }
    if ($hasCancelledBy && $uid) { $set .= ", cancelled_by = :uid"; $params[':uid'] = $uid; }
    $pdo->prepare("UPDATE {$ordersT} SET {$set} WHERE id = :id")->execute($params);

    $pdo->commit();
    redirect('admin_sales.php?ok=1&msg=' . urlencode('Venta anulada y stock restituido.'));
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[reverse order] ' . $e->getMessage());
    redirect('admin_sales.php?ok=0&msg=' . urlencode('No se pudo anular: ' . $e->getMessage()));
  }
}
/* === /Reversión de venta =========================================== */

// (El control de rol Admin/Billing se hace arriba, antes del manejador POST.)

$pdo = get_pdo();

/* ===== Tablas y columnas ===== */
$ordersT = table_exists($pdo,'orders') ? 'orders' : (table_exists($pdo,'sales') ? 'sales' : null);
$itemsT  = table_exists($pdo,'order_items') ? 'order_items' : (table_exists($pdo,'sales_items') ? 'sales_items' : null);
if (!$ordersT) { http_response_code(500); exit('No existe la tabla de ventas (orders o sales).'); }

$ocols = columns($pdo, $ordersT);
$icols = $itemsT ? columns($pdo, $itemsT) : [];

$dateCol = null;
foreach (['created_at','order_date','fecha','created','date','sale_date'] as $c) {
  if (in_array(strtolower($c), $ocols, true)) { $dateCol = $c; break; }
}
$itemFK = null;
foreach (['order_id','sale_id','sales_id','orders_id'] as $c) {
  if (in_array(strtolower($c), $icols, true)) { $itemFK = $c; break; }
}

// ¿Existe user_id para asociar vendedor?
$hasUserId = in_array('user_id', $ocols, true) || in_array('user_id', array_map('strtolower',$ocols), true);

/* ===== Filtros ===== */
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to']   ?? '');
$pm       = trim($_GET['pm']   ?? ''); // método de pago
$bt       = trim($_GET['bt']   ?? ''); // tipo de cliente
$q        = trim($_GET['q']    ?? ''); // texto libre
$sellerId = isset($_GET['seller_id']) && $_GET['seller_id'] !== '' ? (int)$_GET['seller_id'] : null;

/* ===== Query de órdenes (JOIN al espejo `usuarios` del SSO) ===== */
// `usuarios` es solo un espejo para resolver nombres; user_id guarda el id SSO.
$select = "o.*";
$join   = "";
if ($hasUserId && table_exists($pdo, 'usuarios')) {
  $select .= ", u.nombre_usuario AS seller_username"
           . ", TRIM(CONCAT_WS(' ', u.nombre, u.apellido)) AS seller_full_name"
           . ", u.id AS seller_id";
  $join    = " LEFT JOIN usuarios u ON u.id = o.user_id ";
}

$sql = "SELECT {$select} FROM {$ordersT} o {$join} WHERE 1";
$params = [];

if ($dateCol && $from !== '') { $sql .= " AND o.{$dateCol} >= :from"; $params[':from'] = $from.' 00:00:00'; }
if ($dateCol && $to   !== '') { $sql .= " AND o.{$dateCol} <= :to";   $params[':to']   = $to.' 23:59:59'; }

if ($pm !== '') {
  foreach (['payment_method','metodo_pago','pago'] as $c) {
    if (in_array(strtolower($c), $ocols, true)) { $sql .= " AND o.{$c} = :pm"; $params[':pm'] = $pm; break; }
  }
}
if ($bt !== '') {
  foreach (['customer_type','buyer_type','tipo_cliente','tipo'] as $c) {
    if (in_array(strtolower($c), $ocols, true)) { $sql .= " AND o.{$c} = :bt"; $params[':bt'] = $bt; break; }
  }
}
if ($sellerId !== null && $hasUserId) {
  $sql .= " AND o.user_id = :sid"; $params[':sid'] = $sellerId;
}
if ($q !== '') {
  $likeCols = array_values(array_intersect(
    array_map('strtolower',$ocols),
    ['full_name','customer_name','nombre','company_name','empresa','email','correo','phone','telefono','nit','cedula','dni','person_name','person_id','customer_email','customer_phone']
  ));
  $ors = [];
  foreach ($likeCols as $i => $c) { $ors[] = "o.{$c} LIKE :q{$i}"; $params[":q{$i}"] = "%{$q}%"; }
  if ($ors) $sql .= " AND (".implode(" OR ", $ors).")";
}

$sql .= $dateCol ? " ORDER BY o.{$dateCol} DESC" : " ORDER BY o.id DESC";
$sql .= " LIMIT 300";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== Carga items por orden ===== */
$itemsByOrder = [];
if ($itemsT && $orders) {
  $ids = array_map(fn($r)=>$r['id'] ?? null, $orders);
  $ids = array_values(array_filter($ids, 'is_numeric'));
  if ($ids) {
    if (!$itemFK) { $itemFK = in_array('order_id', $icols, true) ? 'order_id' : null; }
    if ($itemFK) {
      $in = implode(',', array_fill(0, count($ids), '?'));
      $it = $pdo->prepare("SELECT * FROM {$itemsT} WHERE {$itemFK} IN ($in) ORDER BY {$itemFK}, id");
      $it->execute($ids);
      foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $oid = $row[$itemFK];
        $itemsByOrder[$oid][] = $row;
      }
    }
  }
}

/* ===== Vendedores para filtro (espejo `usuarios`) ===== */
$sellers = [];
if ($hasUserId && table_exists($pdo, 'usuarios')) {
  $sellers = $pdo->query(
    "SELECT id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', nombre, apellido)),''),"
  . " NULLIF(nombre_usuario,''), CONCAT('Usuario #', id)) AS label"
  . " FROM usuarios ORDER BY label"
  )->fetchAll(PDO::FETCH_ASSOC);
}

/* ===== Render ===== */
ob_start(); ?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Ventas</h4>

  <form class="row g-2" method="get" action="<?= url('admin_sales.php') ?>">
    <div class="col-auto">
      <input type="date" class="form-control" name="from" value="<?= e($from) ?>" placeholder="Desde">
    </div>
    <div class="col-auto">
      <input type="date" class="form-control" name="to" value="<?= e($to) ?>" placeholder="Hasta">
    </div>
    <div class="col-auto">
      <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Buscar nombre, empresa, NIT, cédula, email...">
    </div>
    <div class="col-auto">
      <select class="form-select" name="pm">
        <option value="">Método de pago</option>
        <?php foreach (['Efectivo'=>'efectivo','Transferencia'=>'transferencia','Por pagar'=>'por_pagar'] as $txt=>$val): ?>
          <option value="<?= e($val) ?>" <?= $pm===$val?'selected':'' ?>><?= e($txt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select class="form-select" name="bt">
        <option value="">Tipo de cliente</option>
        <?php foreach (['Empresa'=>'empresa','Natural'=>'natural'] as $txt=>$val): ?>
          <option value="<?= e($val) ?>" <?= $bt===$val?'selected':'' ?>><?= e($txt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($sellers): ?>
      <div class="col-auto">
        <select class="form-select" name="seller_id">
          <option value="">Vendedor</option>
          <?php foreach ($sellers as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $sellerId===(int)$s['id']?'selected':'' ?>><?= e($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-auto">
      <button class="btn btn-primary" type="submit">Filtrar</button>
    </div>
  </form>
</div>

<?php if (isset($_GET['msg'])): 
  $ok = ($_GET['ok'] ?? '') === '1'; ?>
  <div class="alert <?= $ok ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show" role="alert">
    <?= e($_GET['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
  </div>
<?php endif; ?>

<?php if (!$orders): ?>
  <div class="alert alert-info">No hay ventas que coincidan con el filtro.</div>
<?php else: ?>
  <div class="row row-cols-1 g-3">
    <?php foreach ($orders as $o):
      $oid    = $o['id'] ?? null;
      $fecha  = pick($o, [$dateCol ?: 'created_at','order_date','fecha','created','date']) ?: '—';
      $pmeth  = pick($o, ['payment_method','metodo_pago','pago'], '—');
      $ctype  = pick($o, ['customer_type','buyer_type','tipo_cliente','tipo'], '—');

      // Cliente
      $empresa = pick($o, ['company_name','empresa','business_name']);
      $nit     = pick($o, ['company_nit','nit','tax_id']);
      $nombre  = pick($o, ['person_name','full_name','customer_name','nombre']);
      $doc     = pick($o, ['person_id','cedula','dni','document','doc_id']);
      $email   = pick($o, ['customer_email','email','correo']);
      $phone   = pick($o, ['customer_phone','phone','telefono','tel']);

      // Totales
      $subtotal = (float) pick($o, ['subtotal','subtotal_amount','sub_total'], 0);
      $tax      = (float) pick($o, ['tax','tax_amount','iva','impuesto'], 0);
      $total    = (float) pick($o, ['total','total_amount','grand_total'], $subtotal + $tax);

      // Vendedor
      $sellerName = null;
      if (isset($o['seller_full_name']) || isset($o['seller_username'])) {
        $sellerName = ($o['seller_full_name'] ?: null) ?: ($o['seller_username'] ?? null);
      } elseif (isset($o['user_id'])) {
        $sellerName = 'ID '.$o['user_id'];
      }

      $status = strtolower((string)($o['status'] ?? ''));
      $items = $itemsByOrder[$oid] ?? [];
    ?>
    <div class="col">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-3">
            <div>
              <strong>#<?= e($oid) ?></strong>
              <small class="text-muted ms-2"><?= e($fecha) ?></small>
            </div>
            <?php if ($sellerName): ?>
              <span class="badge text-bg-dark">Vendedor: <?= e($sellerName) ?></span>
            <?php endif; ?>
          </div>
          <?php
  $status = (string)($o['status'] ?? '');
  $s = strtolower($status);
  $notas    = trim((string)($o['notas'] ?? ''));
  $notasAt  = trim((string)($o['notas_at'] ?? ''));
  $paidRef  = trim((string)($o['paid_ref'] ?? ''));
  $payRef   = trim((string)pick($o, ['payment_ref'], ''));
  $giftNote = trim((string)($o['gift_note'] ?? ''));
  if ($s === 'cancelled') {
    $statusBadge = 'text-bg-danger';
    $statusLabel = 'Anulada';
  } elseif ($s === 'pending') {
    $statusBadge = 'text-bg-warning'; // ← amarillo
    $statusLabel = 'Pendiente';
  } elseif ($s === 'paid') {
    $statusBadge = 'text-bg-success';
    $statusLabel = 'Pagado';
  } elseif ($s === 'gift') {
    $statusBadge = 'text-bg-info';
    $statusLabel = 'Obsequio';
  } else {
    $statusBadge = 'text-bg-secondary';
    $statusLabel = $status ? ucfirst($status) : '—';
  }
?>
<div>
  <span class="badge <?= $statusBadge ?> me-2"><?= e($statusLabel) ?></span>
  <span class="badge text-bg-secondary me-2"><?= e(ucfirst((string)$pmeth)) ?></span>
  <?php if ($ctype): ?>
    <span class="badge text-bg-info"><?= e(ucfirst((string)$ctype)) ?></span>
  <?php endif; ?>
</div>

        </div>

        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <h6 class="mb-2">Cliente</h6>
              <?php if ($empresa): ?>
                <div><strong>Empresa:</strong> <?= e($empresa) ?></div>
                <?php if ($nit): ?><div><strong>NIT:</strong> <?= e($nit) ?></div><?php endif; ?>
              <?php else: ?>
                <div><strong>Nombre:</strong> <?= e($nombre ?: '—') ?></div>
                <?php if ($doc): ?><div><strong>Cédula:</strong> <?= e($doc) ?></div><?php endif; ?>
              <?php endif; ?>
              <div><strong>Email:</strong> <?= e($email ?: '—') ?></div>
              <div><strong>Teléfono:</strong> <?= e($phone ?: '—') ?></div>
            </div>
            <div class="col-md-6">
              <h6 class="mb-2">Totales</h6>
              <div>Subtotal: $<?= money($subtotal) ?></div>
              <div>Impuesto: $<?= money($tax) ?></div>
              <div class="fw-semibold">Total: $<?= money($total) ?></div>
              <?php if ($payRef !== ''): ?>
                <div class="small text-muted mt-1">Ref. de pago: <?= e($payRef) ?></div>
              <?php endif; ?>
              <?php if ($paidRef !== ''): ?>
                <div class="small text-muted">N° de comprobante: <strong><?= e($paidRef) ?></strong></div>
              <?php endif; ?>
              <?php if ($s === 'gift'): ?>
                <div class="small text-muted mt-1">
                  Obsequio: no suma en el consolidado<?= $giftNote !== '' ? ' · <strong>' . e($giftNote) . '</strong>' : '' ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($notas !== ''): ?>
            <div class="alert alert-light border mt-3 mb-0 py-2 px-3">
              <div class="small text-muted mb-1">
                Observación<?= $notasAt !== '' ? ' · ' . e($notasAt) : '' ?>
              </div>
              <div><?= nl2br(e($notas)) ?></div>
            </div>
          <?php endif; ?>

          <?php if ($items): ?>
            <hr>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th class="text-end">Cant.</th>
                    <th class="text-end">Unitario</th>
                    <th class="text-end">Subtotal</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($items as $it):
                    $pname = pick($it, ['product_name','name','item_name'], '—');
                    $variant = pick($it, ['variant_label','variante']);
                    if (!$variant) {
                      $o1n = pick($it, ['option1_name','opt1_name']);
                      $o1v = pick($it, ['option1_value','opt1_value']);
                      $o2n = pick($it, ['option2_name','opt2_name']);
                      $o2v = pick($it, ['option2_value','opt2_value']);
                      $parts = [];
                      if ($o1n && $o1v) $parts[] = "{$o1n}: {$o1v}";
                      if ($o2n && $o2v) $parts[] = "{$o2n}: {$o2v}";
                      $variant = $parts ? implode(' / ', $parts) : '—';
                    }
                    $qty   = (float) pick($it, ['qty','quantity','cantidad'], 0);
                    $unit  = (float) pick($it, ['unit_price','price','precio'], 0);
                    $line  = (float) pick($it, ['line_total','subtotal','total_line'], $qty * $unit);
                  ?>
                  <tr>
                    <td><?= e($pname) ?></td>
                    <td><?= e($variant) ?></td>
                    <td class="text-end"><?= e((string)$qty) ?></td>
                    <td class="text-end">$<?= money($unit) ?></td>
                    <td class="text-end">$<?= money($line) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-muted mt-2">Sin ítems registrados para esta venta.</div>
          <?php endif; ?>
        </div>

        <div class="card-footer">
          <div class="d-flex justify-content-end gap-2 flex-wrap">
          <!-- Copiar resumen -->
          <button class="btn btn-outline-secondary btn-sm"
                  onclick="navigator.clipboard.writeText(this.dataset.copy);this.textContent='Copiado';setTimeout(()=>this.textContent='Copiar datos',1500)"
                  data-copy="<?= e("Venta #{$oid} | Fecha: ".($fecha?:'—')." | Cliente: ".($empresa ?: $nombre ?: '—')." | Total: $".money($total)." | Método: {$pmeth}".($sellerName ? " | Vendedor: {$sellerName}" : "")) ?>">
            Copiar datos
          </button>

          <?php if ($s === 'pending'): ?>
            <button class="btn btn-outline-success btn-sm" type="button"
                    data-bs-toggle="collapse" data-bs-target="#pagar_<?= (int)$oid ?>">
              Marcar como pagada
            </button>
            <button class="btn btn-outline-info btn-sm" type="button"
                    data-bs-toggle="collapse" data-bs-target="#obsequio_<?= (int)$oid ?>">
              Dar como obsequio
            </button>
          <?php endif; ?>

          <button class="btn btn-outline-secondary btn-sm" type="button"
                  data-bs-toggle="collapse" data-bs-target="#nota_<?= (int)$oid ?>">
            <?= $notas !== '' ? 'Editar observación' : 'Agregar observación' ?>
          </button>

          <?php if ($status !== 'cancelled'): ?>
            <form class="d-inline" method="post" action="<?= url('admin_sales.php') ?>"
                  onsubmit="return confirm('¿Seguro que deseas ANULAR esta venta y devolver el stock?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reverse">
              <input type="hidden" name="order_id" value="<?= (int)$oid ?>">
              <button class="btn btn-outline-danger btn-sm" type="submit">Anular venta</button>
            </form>
          <?php else: ?>
            <span class="badge text-bg-danger">Anulada</span>
          <?php endif; ?>
          </div>

          <?php if ($s === 'pending'): ?>
            <div class="collapse mt-2" id="pagar_<?= (int)$oid ?>">
              <form method="post" action="<?= url('admin_sales.php') ?>" class="border rounded p-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="order_id" value="<?= (int)$oid ?>">
                <label class="form-label small mb-1">
                  N.° de comprobante <span class="text-muted">(opcional)</span>
                </label>
                <div class="d-flex gap-2 flex-wrap">
                  <input class="form-control form-control-sm" name="paid_ref" maxlength="120"
                         style="max-width:320px"
                         placeholder="Consignación, recibo, transferencia…">
                  <button class="btn btn-success btn-sm" type="submit">Confirmar cobro</button>
                </div>
              </form>
            </div>

            <div class="collapse mt-2" id="obsequio_<?= (int)$oid ?>">
              <form method="post" action="<?= url('admin_sales.php') ?>" class="border rounded p-2"
                    onsubmit="return confirm('¿Registrar esta venta como OBSEQUIO? Dejará de sumar en el consolidado; el stock sigue descontado.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_gift">
                <input type="hidden" name="order_id" value="<?= (int)$oid ?>">
                <label class="form-label small mb-1">
                  Motivo o beneficiario <span class="text-muted">(opcional)</span>
                </label>
                <div class="d-flex gap-2 flex-wrap">
                  <input class="form-control form-control-sm" name="gift_note" maxlength="255"
                         style="max-width:320px"
                         placeholder="Cortesía a invitado, patrocinio, premio…">
                  <button class="btn btn-info btn-sm" type="submit">Confirmar obsequio</button>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <div class="collapse mt-2" id="nota_<?= (int)$oid ?>">
            <form method="post" action="<?= url('admin_sales.php') ?>" class="border rounded p-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_note">
              <input type="hidden" name="order_id" value="<?= (int)$oid ?>">
              <label class="form-label small mb-1">
                Observación <span class="text-muted">(vaciar el campo la elimina)</span>
              </label>
              <textarea class="form-control form-control-sm" name="notas" rows="2"
                        maxlength="1000"><?= e($notas) ?></textarea>
              <button class="btn btn-primary btn-sm mt-2" type="submit">Guardar observación</button>
            </form>
          </div>
        </div>

      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
render('Ventas', __DIR__ . '/../views/pages/_blank.php', ['content'=>$content, 'cats'=>[]]);
