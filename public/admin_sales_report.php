<?php
// public/admin_sales_report.php — Consolidado de Ventas
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../views/main.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_gate_private.php';

require_login();
require_roles(['Admin','Billing']);

$pdo = get_pdo();

/* ===== Helpers ===== */
function columns(PDO $pdo, string $table): array {
  $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
  $st->execute([':t'=>$table]);
  return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
}
function table_exists(PDO $pdo, string $t): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
  $st->execute([':t'=>$t]); return (bool)$st->fetchColumn();
}
function has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
  $st->execute([':t'=>$table, ':c'=>$col]); return (bool)$st->fetchColumn();
}
function pick_date_col(PDO $pdo, string $table): ?string {
  foreach (['created_at','order_date','fecha','created','date','sale_date'] as $c) {
    if (has_column($pdo, $table, $c)) return $c;
  }
  return null;
}
// Filtros con opción de excluir anuladas (por defecto true)
function where_and_params(PDO $pdo, string $ordersT, ?string $dateCol, bool $excludeCancelled = true): array {
  $where = ["1"];
  $p = [];

  $from = $_GET['from'] ?? '';
  $to   = $_GET['to'] ?? '';
  $pm   = strtolower(trim($_GET['pm'] ?? ''));
  $bt   = strtolower(trim($_GET['bt'] ?? ''));
  $art  = $_GET['art'] ?? '';

  if ($excludeCancelled && has_column($pdo, $ordersT, 'status')) {
    $where[] = "{$ordersT}.status <> 'cancelled'";
  }

  // Columnas reales
  $pmCol  = null;
  foreach (['payment_method','metodo_pago','pago'] as $c) {
    if (has_column($pdo, $ordersT, $c)) { $pmCol = $c; break; }
  }
  $ctCol  = has_column($pdo, $ordersT, 'customer_type') ? 'customer_type' : null;
  $artCol = has_column($pdo, $ordersT, 'for_artesanas') ? 'for_artesanas' : null;

  if ($dateCol) {
    if ($from !== '') { $where[] = "{$ordersT}.{$dateCol} >= :from"; $p[':from'] = $from.' 00:00:00'; }
    if ($to   !== '') { $where[] = "{$ordersT}.{$dateCol} <= :to";   $p[':to']   = $to  .' 23:59:59'; }
  }
  if (in_array($pm, ['efectivo','transferencia','por_pagar'], true) && $pmCol) {
    $where[] = "{$ordersT}.{$pmCol} = :pm"; $p[':pm'] = $pm;
  }
  if (in_array($bt, ['empresa','natural'], true) && $ctCol) {
    $where[] = "{$ordersT}.{$ctCol} = :bt"; $p[':bt'] = $bt;
  }
  if (($art === '0' || $art === '1') && $artCol) {
    $where[] = "{$ordersT}.{$artCol} = :art"; $p[':art'] = (int)$art;
  }

  return ['where'=>implode(' AND ', $where), 'params'=>$p];
}

/* ===== Tablas detectadas ===== */
$ordersT = table_exists($pdo,'orders') ? 'orders' : (table_exists($pdo,'sales') ? 'sales' : null);
if (!$ordersT) { http_response_code(500); exit('No existe la tabla de órdenes/ventas.'); }

$itemsT  = table_exists($pdo,'order_items') ? 'order_items' : (table_exists($pdo,'sales_items') ? 'sales_items' : null);
$usersT  = table_exists($pdo,'users') ? 'users' : null;

$dateCol = pick_date_col($pdo, $ordersT);
$hasArtesanas = has_column($pdo,$ordersT,'for_artesanas');

/* ===== Filtros ===== */
// Para totales y agrupaciones: EXCLUIR canceladas
list('where'=>$WHERE, 'params'=>$PARAMS) = where_and_params($pdo, $ordersT, $dateCol, true);
$WHERE_O = preg_replace('/\b'.preg_quote($ordersT, '/').'\./', 'o.', $WHERE);

// Para listado de órdenes: INCLUIR canceladas
list('where'=>$WHERE_LIST, 'params'=>$PARAMS_LIST) = where_and_params($pdo, $ordersT, $dateCol, false);
$WHERE_LIST_O = preg_replace('/\b'.preg_quote($ordersT, '/').'\./', 'o.', $WHERE_LIST);

/* ===== Consultas ===== */
// Totales globales
$sqlTotals = "SELECT
  COUNT(*) AS n_orders,
  SUM(subtotal) AS subtotal_sum,
  SUM(tax) AS tax_sum,
  SUM(total) AS total_sum,
  SUM(CASE WHEN status='paid' THEN total ELSE 0 END) AS total_paid
FROM {$ordersT}
WHERE {$WHERE}";
$totals = $pdo->prepare($sqlTotals);
$totals->execute($PARAMS);
$TOT = array_map(fn($v)=>$v ?? 0, $totals->fetch(PDO::FETCH_ASSOC) ?: []);

// Por día
$sqlByDay = "SELECT DATE(".($dateCol ? "{$ordersT}.{$dateCol}" : "{$ordersT}.id").") AS d,
  COUNT(*) AS n, SUM(total) AS s
FROM {$ordersT}
WHERE {$WHERE}
GROUP BY d
ORDER BY d DESC
LIMIT 120";
$stByDay = $pdo->prepare($sqlByDay);
$stByDay->execute($PARAMS);
$BY_DAY = $stByDay->fetchAll(PDO::FETCH_ASSOC);

// Por método de pago
$BY_PM = [];
if (has_column($pdo,$ordersT,'payment_method')) {
  $sqlByPm = "SELECT payment_method, COUNT(*) n, SUM(total) s
  FROM {$ordersT}
  WHERE {$WHERE}
  GROUP BY payment_method
  ORDER BY s DESC";
  $st = $pdo->prepare($sqlByPm); $st->execute($PARAMS);
  $BY_PM = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Por tipo de cliente
$BY_CT = [];
if (has_column($pdo,$ordersT,'customer_type')) {
  $sqlByCt = "SELECT customer_type, COUNT(*) n, SUM(total) s
  FROM {$ordersT}
  WHERE {$WHERE}
  GROUP BY customer_type
  ORDER BY s DESC";
  $st = $pdo->prepare($sqlByCt); $st->execute($PARAMS);
  $BY_CT = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Por vendedor (usuario)
$BY_SELLER = [];
if ($usersT && has_column($pdo,$ordersT,'user_id')) {
  $sellerExpr = "COALESCE(NULLIF(u.full_name,''), NULLIF(u.username,''), NULLIF(u.email,''), CONCAT('Usuario #', u.id))";
  $sqlBySeller = "SELECT
      u.id AS uid,
      {$sellerExpr} AS seller,
      COUNT(*)  AS n,
      SUM(o.total) AS s
    FROM {$ordersT} o
    LEFT JOIN {$usersT} u ON u.id = o.user_id
    WHERE {$WHERE_O}
    GROUP BY uid, seller
    ORDER BY s DESC";
  $st = $pdo->prepare($sqlBySeller);
  $st->execute($PARAMS);
  $BY_SELLER = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Top productos (necesita items)
$TOP_PRODUCTS = [];
if ($itemsT && has_column($pdo,$itemsT,'product_name')) {
  $sqlTop = "SELECT
      i.product_id,
      i.product_name,
      COALESCE(i.variant_label,'') AS variant_label,
      SUM(i.quantity) AS qty,
      SUM(i.line_total) AS amount
    FROM {$itemsT} i
    INNER JOIN {$ordersT} o ON o.id = i.order_id
    WHERE {$WHERE_O}
    GROUP BY i.product_id, i.product_name, i.variant_label
    ORDER BY qty DESC, amount DESC
    LIMIT 50";
  $st = $pdo->prepare($sqlTop); $st->execute($PARAMS);
  $TOP_PRODUCTS = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Listado de órdenes (incluye canceladas)
$orderListLimit = 200;
$sqlList = "SELECT o.* FROM {$ordersT} o
WHERE {$WHERE_LIST_O}
ORDER BY ".($dateCol ? "o.{$dateCol}" : "o.id")." DESC
LIMIT {$orderListLimit}";
$stList = $pdo->prepare($sqlList);
$stList->execute($PARAMS_LIST);

$ORDERS_LIST = $stList->fetchAll(PDO::FETCH_ASSOC);

/* ===== Navbar cats ===== */
$cats = $pdo->query("SELECT name, slug FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ===== UI ===== */
ob_start();
$from = e($_GET['from'] ?? '');
$to   = e($_GET['to'] ?? '');
$pm   = e($_GET['pm']   ?? '');
$bt   = e($_GET['bt']   ?? '');
$art  = e($_GET['art']  ?? '');
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Consolidado de Ventas</h4>
  <form class="row g-2" method="get" action="<?= url('admin_sales_report.php') ?>">
    <div class="col-auto">
      <input type="date" class="form-control" name="from" value="<?= $from ?>" placeholder="Desde">
    </div>
    <div class="col-auto">
      <input type="date" class="form-control" name="to" value="<?= $to ?>" placeholder="Hasta">
    </div>
    <div class="col-auto">
      <select class="form-select" name="pm">
        <option value="">Método de pago</option>
        <?php foreach (['efectivo'=>'Efectivo','transferencia'=>'Transferencia','por_pagar'=>'Por pagar'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($pm===$k)?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select class="form-select" name="bt">
        <option value="">Tipo cliente</option>
        <?php foreach (['empresa'=>'Empresa','natural'=>'Natural'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($bt===$k)?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($hasArtesanas): ?>
    <div class="col-auto">
      <select class="form-select" name="art">
        <option value="">Artesanas</option>
        <option value="1" <?= ($art==='1')?'selected':'' ?>>Sí</option>
        <option value="0" <?= ($art==='0')?'selected':'' ?>>No</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-auto">
      <button class="btn btn-primary" type="submit">Filtrar</button>
    </div>
  </form>
</div>

<!-- Tarjetas de totales -->
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Órdenes</div>
      <div class="fs-4 fw-semibold"><?= (int)($TOT['n_orders'] ?? 0) ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Subtotal</div>
      <div class="fs-4 fw-semibold">$<?= money($TOT['subtotal_sum'] ?? 0) ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Impuestos</div>
      <div class="fs-4 fw-semibold">$<?= money($TOT['tax_sum'] ?? 0) ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card shadow-sm"><div class="card-body">
      <div class="text-muted small">Total</div>
      <div class="fs-4 fw-semibold">$<?= money($TOT['total_sum'] ?? 0) ?></div>
      <div class="small text-success mt-1">Pagado: $<?= money($TOT['total_paid'] ?? 0) ?></div>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <!-- Por día -->
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header">Ventas por día</div>
      <div class="card-body">
        <?php if (!$BY_DAY): ?>
          <div class="text-muted">Sin datos.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Fecha</th><th class="text-end">Órdenes</th><th class="text-end">Total</th></tr></thead>
              <tbody>
                <?php foreach ($BY_DAY as $r): ?>
                  <tr>
                    <td><?= e($r['d']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end">$<?= money($r['s']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Por método de pago -->
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header">Ventas por método de pago</div>
      <div class="card-body">
        <?php if (!$BY_PM): ?>
          <div class="text-muted">Sin datos.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Método</th><th class="text-end">Órdenes</th><th class="text-end">Total</th></tr></thead>
              <tbody>
                <?php foreach ($BY_PM as $r): ?>
                  <tr>
                    <td><?= e(ucfirst($r['payment_method'])) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end">$<?= money($r['s']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Por tipo de cliente -->
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header">Ventas por tipo de cliente</div>
      <div class="card-body">
        <?php if (!$BY_CT): ?>
          <div class="text-muted">Sin datos.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Tipo</th><th class="text-end">Órdenes</th><th class="text-end">Total</th></tr></thead>
              <tbody>
                <?php foreach ($BY_CT as $r): ?>
                  <tr>
                    <td><?= e(ucfirst($r['customer_type'])) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end">$<?= money($r['s']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Por vendedor -->
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header">Ventas por vendedor</div>
      <div class="card-body">
        <?php if (!$BY_SELLER): ?>
          <div class="text-muted">Sin datos, o no existe la tabla de usuarios.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Vendedor</th><th class="text-end">Órdenes</th><th class="text-end">Total</th></tr></thead>
              <tbody>
                <?php foreach ($BY_SELLER as $r): ?>
                  <tr>
                    <td><?= e($r['seller']) ?></td>
                    <td class="text-end"><?= (int)$r['n'] ?></td>
                    <td class="text-end">$<?= money($r['s']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Top productos -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header">Top productos (por cantidad)</div>
      <div class="card-body">
        <?php if (!$TOP_PRODUCTS): ?>
          <div class="text-muted">Sin datos de ítems.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th style="width:80px">ID</th>
                  <th>Producto</th>
                  <th>Variante</th>
                  <th class="text-end">Cant.</th>
                  <th class="text-end">Monto</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($TOP_PRODUCTS as $r): ?>
                  <tr>
                    <td><?= (int)$r['product_id'] ?></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= e($r['variant_label'] ?: '—') ?></td>
                    <td class="text-end"><?= (int)$r['qty'] ?></td>
                    <td class="text-end">$<?= money($r['amount']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Listado de órdenes (incluye canceladas, en rojo) -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header">Órdenes (últimas <?= (int)$orderListLimit ?>)</div>
      <div class="card-body">
        <?php if (!$ORDERS_LIST): ?>
          <div class="text-muted">No hay órdenes para mostrar.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th style="width:90px">#</th>
                  <th style="width:160px">Fecha</th>
                  <th>Cliente</th>
                  <th style="width:160px">Método</th>
                  <th class="text-end" style="width:140px">Total</th>
                  <th style="width:120px">Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ORDERS_LIST as $o):
                  $isCancelled = (strtolower((string)($o['status'] ?? '')) === 'cancelled');
                  $rowClass = $isCancelled ? 'table-danger' : '';
                  $fecha  = $dateCol && !empty($o[$dateCol]) ? $o[$dateCol] : ($o['created_at'] ?? ($o['date'] ?? '—'));
                  // Cliente: empresa o persona
                  $cliente = $o['company_name'] ?? ($o['person_name'] ?? ($o['full_name'] ?? '—'));
                  // Método
                  $pmeth = $o['payment_method'] ?? ($o['metodo_pago'] ?? ($o['pago'] ?? '—'));
                  $total = (float)($o['total'] ?? 0);
                  $status= $o['status'] ?? '—';
                ?>
                <tr class="<?= $rowClass ?>">
                  <td>#<?= (int)($o['id'] ?? 0) ?></td>
                  <td><?= e($fecha) ?></td>
                  <td><?= e($cliente) ?></td>
                  <td><?= e(ucfirst((string)$pmeth)) ?></td>
                  <td class="text-end">$<?= money($total) ?></td>
                  <td>
                  <?php
                    $statusLc = strtolower((string)($o['status'] ?? ''));
                    // Mapeo de estado → clase de badge
                    $badgeClass = match ($statusLc) {
                      'cancelled' => 'text-bg-danger',   // rojo
                      'pending'   => 'text-bg-warning',  // amarillo
                      'paid'      => 'text-bg-success',  // verde
                      default     => 'text-bg-secondary' // gris
                    };
                    // Etiqueta amigable
                    $label = $statusLc === 'cancelled' ? 'Anulada' : ucfirst($statusLc ?: '—');
                  ?>
                  <span class="badge <?= $badgeClass ?>"><?= e($label) ?></span>
                </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
render('Consolidado de Ventas', __DIR__ . '/../views/pages/_blank.php', [
  'content' => $content,
  'cats'    => $cats ?? [],
]);
