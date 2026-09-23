<?php
// public/admin_dashboard.php — Indicadores de venta (Admin / Facturación).
//
// La tabla de hechos es order_items unida a orders: como la tienda fuerza
// tax = 0, orders.total es exactamente la suma de line_total, y trabajar a
// nivel de línea permite filtrar por categoría sin cuadrar dos fuentes.
// Todo lo que suma ventas excluye las anuladas; las anuladas se cuentan aparte.
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../views/main.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/_gate_private.php';

require_roles(['Admin', 'Billing']);

// MySQL corre en -05:00 pero PHP-FPM en UTC: sin esto, desde las 7 p. m.
// "Hoy" apuntaría a mañana y los rangos quedarían corridos un día.
date_default_timezone_set('America/Bogota');

$pdo = get_pdo();

function dash_has_column(PDO $pdo, string $t, string $c): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM {$t} LIKE :c");
  $st->execute([':c' => $c]);
  return (bool)$st->fetch();
}
$tienePadre = dash_has_column($pdo, 'categories', 'parent_id');

/* ============================ Filtros ============================ */
const RANGOS = [
  'hoy'     => 'Hoy',
  '7d'      => 'Últimos 7 días',
  '30d'     => 'Últimos 30 días',
  'mes'     => 'Mes en curso',
  'mes_ant' => 'Mes anterior',
  'anio'    => 'Año en curso',
  'custom'  => 'Personalizado',
];
const METODOS = [
  'efectivo'      => 'Efectivo',
  'transferencia' => 'Transferencia',
  'por_pagar'     => 'Por pagar',
];

$hoy   = new DateTimeImmutable('today');
$rango = array_key_exists($_GET['rango'] ?? '', RANGOS) ? $_GET['rango'] : '30d';

function fecha_valida(string $s): ?DateTimeImmutable {
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
  return ($d && $d->format('Y-m-d') === $s) ? $d : null;
}

switch ($rango) {
  case 'hoy':     $desde = $hoy;                                   $hasta = $hoy; break;
  case '7d':      $desde = $hoy->modify('-6 days');                $hasta = $hoy; break;
  case 'mes':     $desde = $hoy->modify('first day of this month'); $hasta = $hoy; break;
  case 'mes_ant': $desde = $hoy->modify('first day of last month'); $hasta = $hoy->modify('last day of last month'); break;
  case 'anio':    $desde = $hoy->setDate((int)$hoy->format('Y'), 1, 1); $hasta = $hoy; break;
  case 'custom':
    $desde = fecha_valida((string)($_GET['desde'] ?? '')) ?? $hoy->modify('-29 days');
    $hasta = fecha_valida((string)($_GET['hasta'] ?? '')) ?? $hoy;
    if ($desde > $hasta) { [$desde, $hasta] = [$hasta, $desde]; }
    break;
  default:        $desde = $hoy->modify('-29 days');               $hasta = $hoy;
}

// Periodo anterior de la misma duración, pegado por detrás: es la base de las
// variaciones de los indicadores.
$dias      = (int)$desde->diff($hasta)->days + 1;
$prevHasta = $desde->modify('-1 day');
$prevDesde = $prevHasta->modify('-' . ($dias - 1) . ' days');

$pm   = array_key_exists($_GET['pm'] ?? '', METODOS) ? $_GET['pm'] : '';
$vend = max(0, (int)($_GET['vend'] ?? 0));
$cat  = trim((string)($_GET['cat'] ?? ''));

/* ==================== Opciones de los filtros ==================== */
$catCols = $tienePadre ? 'id, name, slug, parent_id' : 'id, name, slug';
$catsTodas = $pdo->query("SELECT {$catCols} FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$slugsValidos = array_column($catsTodas, 'slug');
if ($cat !== '' && !in_array($cat, $slugsValidos, true)) { $cat = ''; }

// Árbol de un nivel para el selector: principales y debajo sus hijas.
$catsArbol = [];
$porPadre  = [];
foreach ($catsTodas as $c) { $porPadre[(int)($c['parent_id'] ?? 0)][] = $c; }
foreach ($porPadre[0] ?? [] as $p) {
  $catsArbol[] = $p + ['hija' => false];
  foreach ($porPadre[(int)$p['id']] ?? [] as $h) { $catsArbol[] = $h + ['hija' => true]; }
}

$vendedores = $pdo->query(
  "SELECT DISTINCT o.user_id AS id,
          COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)),''), u.nombre_usuario,
                   CONCAT('Usuario #', o.user_id)) AS nombre
     FROM orders o LEFT JOIN usuarios u ON u.id = o.user_id
    WHERE o.user_id IS NOT NULL
    ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

/* ======================== Consultas base ======================== */
$FROM = "FROM order_items i
         JOIN orders o ON o.id = i.order_id
         LEFT JOIN products p ON p.id = i.product_id
         LEFT JOIN categories c ON c.id = p.category_id"
      . ($tienePadre ? " LEFT JOIN categories cp ON cp.id = c.parent_id" : "");

/** WHERE y parámetros para un rango de fechas con los filtros vigentes. */
function where_rango(DateTimeImmutable $d, DateTimeImmutable $h, string $pm, int $vend, string $cat, bool $tienePadre): array {
  $w = ["o.created_at >= :desde", "o.created_at < :hasta"];
  $p = [':desde' => $d->format('Y-m-d 00:00:00'), ':hasta' => $h->modify('+1 day')->format('Y-m-d 00:00:00')];
  if ($pm !== '')  { $w[] = "o.payment_method = :pm"; $p[':pm'] = $pm; }
  if ($vend > 0)   { $w[] = "o.user_id = :vend";      $p[':vend'] = $vend; }
  if ($cat !== '') {
    $w[] = $tienePadre ? "(c.slug = :cat OR cp.slug = :cat)" : "c.slug = :cat";
    $p[':cat'] = $cat;
  }
  return [implode(' AND ', $w), $p];
}

function kpis(PDO $pdo, string $FROM, array $wp): array {
  [$w, $p] = $wp;
  $st = $pdo->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN i.line_total END), 0) AS ventas,
       COUNT(DISTINCT CASE WHEN o.status <> 'cancelled' THEN o.id END)           AS ordenes,
       COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN i.quantity END), 0)   AS unidades,
       COALESCE(SUM(CASE WHEN o.status = 'paid'    THEN i.line_total END), 0)    AS cobrado,
       COALESCE(SUM(CASE WHEN o.status = 'pending' THEN i.line_total END), 0)    AS por_cobrar,
       COUNT(DISTINCT CASE WHEN o.status = 'cancelled' THEN o.id END)            AS anuladas,
       COALESCE(SUM(CASE WHEN o.status = 'cancelled' THEN i.line_total END), 0)  AS anulado
     {$FROM} WHERE {$w}"
  );
  $st->execute($p);
  $r = array_map('floatval', $st->fetch(PDO::FETCH_ASSOC) ?: []);
  $r['ticket'] = $r['ordenes'] > 0 ? $r['ventas'] / $r['ordenes'] : 0.0;
  return $r;
}

function agrupar(PDO $pdo, string $sql, array $p): array {
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

$wpAct  = where_rango($desde, $hasta, $pm, $vend, $cat, $tienePadre);
$wpPrev = where_rango($prevDesde, $prevHasta, $pm, $vend, $cat, $tienePadre);
$K  = kpis($pdo, $FROM, $wpAct);
$KP = kpis($pdo, $FROM, $wpPrev);
[$W, $P] = $wpAct;
$vivas = "o.status <> 'cancelled'";

// Serie diaria, rellenando los días sin ventas con 0: un hueco en la línea se
// leería como "sin dato", no como "cero".
$porDia = [];
foreach (agrupar($pdo, "SELECT DATE(o.created_at) AS d, SUM(i.line_total) AS s, COUNT(DISTINCT o.id) AS n
                          {$FROM} WHERE {$W} AND {$vivas} GROUP BY d", $P) as $r) {
  $porDia[$r['d']] = ['s' => (float)$r['s'], 'n' => (int)$r['n']];
}
$serie = [];
for ($d = $desde; $d <= $hasta; $d = $d->modify('+1 day')) {
  $k = $d->format('Y-m-d');
  $serie[] = ['d' => $k, 's' => $porDia[$k]['s'] ?? 0.0, 'n' => $porDia[$k]['n'] ?? 0];
}

$porMetodo = [];
foreach (agrupar($pdo, "SELECT o.payment_method AS m, SUM(i.line_total) AS s, COUNT(DISTINCT o.id) AS n
                          {$FROM} WHERE {$W} AND {$vivas} GROUP BY m", $P) as $r) {
  $porMetodo[$r['m']] = ['s' => (float)$r['s'], 'n' => (int)$r['n']];
}

// Top productos: ocho y el resto a "Otros", nunca más barras que eso.
// Agrupado por el producto ACTUAL, no por el nombre guardado en la venta:
// las líneas conservan el nombre con el que se vendieron ("CAMISETA AMARILLA
// TALLA M") y agrupar por él partiría Camiseta en una fila por color y talla.
$prods = agrupar($pdo, "SELECT COALESCE(p.name, i.product_name) AS nombre, SUM(i.quantity) AS q, SUM(i.line_total) AS s
                          {$FROM} WHERE {$W} AND {$vivas}
                          GROUP BY COALESCE(CAST(p.id AS CHAR), CONCAT('n:', i.product_name)), nombre
                          ORDER BY s DESC", $P);
$topProd = array_slice($prods, 0, 8);
if (count($prods) > 8) {
  $resto = array_slice($prods, 8);
  $topProd[] = ['nombre' => 'Otros (' . count($resto) . ')',
                'q' => array_sum(array_column($resto, 'q')),
                's' => array_sum(array_column($resto, 's'))];
}

$porCat = agrupar($pdo, "SELECT " . ($tienePadre ? "COALESCE(cp.name, c.name, 'Sin categoría')" : "COALESCE(c.name, 'Sin categoría')") . " AS nombre,
                                SUM(i.quantity) AS q, SUM(i.line_total) AS s
                           {$FROM} WHERE {$W} AND {$vivas} GROUP BY nombre ORDER BY s DESC", $P);

$porVend = agrupar($pdo, "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)),''), u.nombre_usuario,
                                         CONCAT('Usuario #', o.user_id)) AS nombre,
                                 COUNT(DISTINCT o.id) AS n, SUM(i.line_total) AS s
                            {$FROM} LEFT JOIN usuarios u ON u.id = o.user_id
                           WHERE {$W} AND {$vivas} GROUP BY o.user_id, nombre ORDER BY s DESC", $P);

$porHora = array_fill(0, 24, 0.0);
foreach (agrupar($pdo, "SELECT HOUR(o.created_at) AS h, SUM(i.line_total) AS s
                          {$FROM} WHERE {$W} AND {$vivas} GROUP BY h", $P) as $r) {
  $porHora[(int)$r['h']] = (float)$r['s'];
}
// Solo la franja con actividad (más un margen), para no dibujar 24 columnas
// casi vacías cuando el stand abre ocho horas.
$horasCon = array_keys(array_filter($porHora, fn($v) => $v > 0));
$hIni = $horasCon ? max(0, min($horasCon) - 1) : 8;
$hFin = $horasCon ? min(23, max($horasCon) + 1) : 20;

/* =========================== Formato =========================== */
/** Variación porcentual; null cuando no hay base con la que comparar. */
function variacion(float $act, float $prev): ?float {
  return $prev > 0 ? ($act - $prev) / $prev * 100 : null;
}
/** Chip de variación: flecha + texto, nunca color solo. */
function chip_var(?float $v, bool $subirEsBueno = true): string {
  if ($v === null) return '<span class="kpi-delta neutro">Sin periodo previo</span>';
  if (abs($v) < 0.05) return '<span class="kpi-delta neutro">= igual que el periodo anterior</span>';
  $sube  = $v > 0;
  $bueno = $sube === $subirEsBueno;
  $flecha = $sube ? '▲' : '▼';
  $txt = ($sube ? '+' : '−') . number_format(abs($v), 1, ',', '.') . ' %';
  return '<span class="kpi-delta ' . ($bueno ? 'bueno' : 'malo') . '">' . $flecha . ' ' . e($txt)
       . ' <span class="kpi-delta-ref">vs periodo anterior</span></span>';
}
function pct(float $a, float $b): string {
  return $b > 0 ? number_format($a / $b * 100, 1, ',', '.') . ' %' : '—';
}

$qsBase = ['rango' => $rango, 'desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d'),
           'pm' => $pm, 'vend' => $vend ?: '', 'cat' => $cat];
$hayFiltros = $pm !== '' || $vend > 0 || $cat !== '';
$cobradoPct = $K['ventas'] > 0 ? $K['cobrado'] / $K['ventas'] * 100 : 0;

// Datos para las gráficas, serializados de forma segura para <script>.
$J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$datos = [
  'serie'  => $serie,
  'prod'   => array_map(fn($r) => ['n' => $r['nombre'], 's' => (float)$r['s'], 'q' => (int)$r['q']], $topProd),
  'cat'    => array_map(fn($r) => ['n' => $r['nombre'], 's' => (float)$r['s'], 'q' => (int)$r['q']], $porCat),
  'vend'   => array_map(fn($r) => ['n' => $r['nombre'], 's' => (float)$r['s'], 'q' => (int)$r['n']], $porVend),
  'hora'   => array_map(fn($h) => ['n' => sprintf('%02d:00', $h), 's' => $porHora[$h]], range($hIni, $hFin)),
];

/* ============================ Vista ============================ */
ob_start(); ?>
<style>
  /* Tokens de la vista. El color de cada serie sigue a la ENTIDAD (efectivo
     es siempre azul), nunca a su posición: filtrar no repinta a nadie. */
  .dash {
    --serie-1: #2a78d6;  --serie-2: #eb6834;  --serie-3: #1baf7a;
    --ink: #0b0b0b; --ink-2: #52514e; --muted: #898781;
    --grid: #e1e0d9; --eje: #c3c2b7;
    --bueno: #006300; --malo: #d03b3b;
    --pista: #cde2fb;           /* paso 100 de la misma rampa que --serie-1 */
  }
  .dash .kpi-label   { font-size: .8rem; color: var(--ink-2); font-weight: 500; }
  .dash .kpi-value   { font-size: 1.65rem; font-weight: 600; color: var(--ink); line-height: 1.15; }
  .dash .hero-value  { font-size: 3rem; font-weight: 650; color: var(--ink); line-height: 1.05; letter-spacing: -.01em; }
  .dash .kpi-delta   { font-size: .8rem; font-weight: 600; white-space: nowrap; }
  .dash .kpi-delta.bueno  { color: var(--bueno); }
  .dash .kpi-delta.malo   { color: var(--malo); }
  .dash .kpi-delta.neutro { color: var(--muted); font-weight: 500; }
  .dash .kpi-delta-ref    { color: var(--muted); font-weight: 400; }
  .dash .kpi-sub     { font-size: .8rem; color: var(--ink-2); }

  .dash .medidor       { height: 8px; background: var(--pista); border-radius: 4px; overflow: hidden; }
  .dash .medidor > span{ display: block; height: 100%; background: var(--serie-1); border-radius: 4px; }

  .dash .card-title-sm { font-size: .95rem; font-weight: 600; color: var(--ink); margin: 0; }
  .dash .card-sub      { font-size: .78rem; color: var(--muted); }
  .dash .lienzo        { position: relative; height: 280px; }
  .dash .lienzo.alto   { height: 320px; }
  .dash .vacio         { height: 200px; display: flex; align-items: center; justify-content: center;
                         color: var(--muted); font-size: .9rem; }

  /* Parte-todo de métodos de pago: segmentos separados por 2px de hueco en
     vez de bordes, y leyenda con monto y % (el aqua no llega a 3:1 sobre la
     superficie: la etiqueta visible es obligatoria, no decorativa). */
  .dash .barra-parte     { display: flex; gap: 2px; height: 28px; }
  .dash .barra-parte > span:first-child { border-radius: 4px 0 0 4px; }
  .dash .barra-parte > span:last-child  { border-radius: 0 4px 4px 0; }
  .dash .barra-parte > span:only-child  { border-radius: 4px; }
  .dash .leyenda-fila    { display: flex; align-items: center; gap: .6rem; padding: .45rem 0;
                           border-bottom: 1px solid var(--grid); font-size: .88rem; }
  .dash .leyenda-fila:last-child { border-bottom: 0; }
  .dash .punto           { width: 10px; height: 10px; border-radius: 50%; flex: 0 0 auto; }
  .dash .leyenda-monto   { margin-left: auto; font-weight: 600; color: var(--ink); font-variant-numeric: tabular-nums; }
  .dash .leyenda-pct     { width: 4.2rem; text-align: right; color: var(--muted); font-variant-numeric: tabular-nums; }

  .dash details.datos > summary { font-size: .8rem; color: var(--ink-2); cursor: pointer; }
  .dash details.datos table     { font-size: .82rem; font-variant-numeric: tabular-nums; }
  .dash .filtros .form-label    { font-size: .72rem; color: var(--ink-2); margin-bottom: .15rem; }
  .dash .actualizando           { opacity: .55; transition: opacity .15s; }
</style>

<div class="dash">

  <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
    <div>
      <h4 class="mb-0">Indicadores de venta</h4>
      <div class="card-sub">
        <?= e($desde->format('d/m/Y')) ?> – <?= e($hasta->format('d/m/Y')) ?>
        · <?= $dias ?> día<?= $dias === 1 ? '' : 's' ?>
        · comparado con <?= e($prevDesde->format('d/m/Y')) ?> – <?= e($prevHasta->format('d/m/Y')) ?>
      </div>
    </div>
  </div>

  <!-- Una sola fila de filtros, arriba de todo lo que filtra. -->
  <form class="card mb-3 filtros" method="get" action="<?= url('admin_dashboard.php') ?>" id="formFiltros">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-4 col-xl">
          <label class="form-label">Periodo</label>
          <select class="form-select form-select-sm" name="rango" id="fRango">
            <?php foreach (RANGOS as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= $k === $rango ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-4 col-xl">
          <label class="form-label">Desde</label>
          <input type="date" class="form-control form-control-sm fecha" name="desde" value="<?= e($desde->format('Y-m-d')) ?>">
        </div>
        <div class="col-6 col-md-4 col-xl">
          <label class="form-label">Hasta</label>
          <input type="date" class="form-control form-control-sm fecha" name="hasta" value="<?= e($hasta->format('Y-m-d')) ?>">
        </div>
        <div class="col-6 col-md-4 col-xl">
          <label class="form-label">Método de pago</label>
          <select class="form-select form-select-sm auto" name="pm">
            <option value="">Todos</option>
            <?php foreach (METODOS as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= $k === $pm ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-4 col-xl">
          <label class="form-label">Vendedor</label>
          <select class="form-select form-select-sm auto" name="vend">
            <option value="">Todos</option>
            <?php foreach ($vendedores as $v): ?>
              <option value="<?= (int)$v['id'] ?>" <?= (int)$v['id'] === $vend ? 'selected' : '' ?>><?= e($v['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-4 col-xl">
          <label class="form-label">Categoría</label>
          <select class="form-select form-select-sm auto" name="cat">
            <option value="">Todas</option>
            <?php foreach ($catsArbol as $c): ?>
              <option value="<?= e($c['slug']) ?>" <?= $c['slug'] === $cat ? 'selected' : '' ?>>
                <?= $c['hija'] ? '— ' : '' ?><?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-xl-auto d-flex gap-2 justify-content-end">
          <button class="btn btn-primary btn-sm" type="submit">Aplicar</button>
          <?php if ($hayFiltros || $rango !== '30d'): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= url('admin_dashboard.php') ?>">Limpiar</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </form>

  <!-- ======================= Indicadores ======================= -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-lg-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="kpi-label">Ventas netas</div>
          <div class="hero-value my-1">$<?= money($K['ventas']) ?></div>
          <div><?= chip_var(variacion($K['ventas'], $KP['ventas'])) ?></div>
          <div class="mt-3">
            <div class="d-flex justify-content-between kpi-sub mb-1">
              <span>Cobrado $<?= money($K['cobrado']) ?></span>
              <span><?= pct($K['cobrado'], $K['ventas']) ?></span>
            </div>
            <div class="medidor" role="meter" aria-label="Porcentaje cobrado"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($cobradoPct) ?>">
              <span style="width: <?= number_format(min(100, $cobradoPct), 2, '.', '') ?>%"></span>
            </div>
            <div class="kpi-sub mt-1">
              Por cobrar <strong>$<?= money($K['por_cobrar']) ?></strong>
              <?php if ($K['por_cobrar'] > 0): ?>
                · <a href="<?= url('admin_sales.php') ?>">ver pendientes</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php
    $tiles = [
      ['Órdenes',          number_format($K['ordenes'], 0, ',', '.'),  variacion($K['ordenes'], $KP['ordenes']), true],
      ['Ticket promedio',  '$' . money($K['ticket']),                  variacion($K['ticket'], $KP['ticket']),   true],
      ['Unidades vendidas', number_format($K['unidades'], 0, ',', '.'), variacion($K['unidades'], $KP['unidades']), true],
      // Para anuladas, que suban es MALO: el chip invierte el sentido.
      ['Anuladas',         number_format($K['anuladas'], 0, ',', '.') . ($K['anulado'] > 0 ? ' · $' . money($K['anulado']) : ''),
                           variacion($K['anuladas'], $KP['anuladas']), false],
    ];
    ?>
    <div class="col-12 col-lg-8">
      <div class="row g-3 h-100">
        <?php foreach ($tiles as [$lab, $val, $var, $subeBueno]): ?>
          <div class="col-6">
            <div class="card h-100">
              <div class="card-body">
                <div class="kpi-label"><?= e($lab) ?></div>
                <div class="kpi-value my-1"><?= e($val) ?></div>
                <div><?= chip_var($var, $subeBueno) ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ======================== Gráficas ======================== -->
  <div class="row g-3" id="graficas">
    <div class="col-12 col-xl-8">
      <div class="card h-100">
        <div class="card-body">
          <p class="card-title-sm">Ventas por día</p>
          <div class="card-sub mb-2">Total vendido cada día, sin anuladas</div>
          <?php if ($K['ventas'] > 0): ?>
            <div class="lienzo alto"><canvas id="gDia" aria-label="Ventas por día" role="img"></canvas></div>
          <?php else: ?>
            <div class="vacio">Sin ventas en este periodo</div>
          <?php endif; ?>
          <details class="datos mt-2">
            <summary>Ver datos</summary>
            <div class="table-responsive mt-2" style="max-height:260px">
              <table class="table table-sm mb-0">
                <thead><tr><th>Día</th><th class="text-end">Órdenes</th><th class="text-end">Ventas</th></tr></thead>
                <tbody>
                  <?php foreach (array_reverse($serie) as $r): ?>
                    <tr><td><?= e(date('d/m/Y', strtotime($r['d']))) ?></td>
                        <td class="text-end"><?= (int)$r['n'] ?></td>
                        <td class="text-end">$<?= money($r['s']) ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </details>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="card h-100">
        <div class="card-body">
          <p class="card-title-sm">Métodos de pago</p>
          <div class="card-sub mb-3">Participación en las ventas del periodo</div>
          <?php
            $totMet = array_sum(array_column($porMetodo, 's'));
            $colorMet = ['efectivo' => 'var(--serie-1)', 'transferencia' => 'var(--serie-2)', 'por_pagar' => 'var(--serie-3)'];
          ?>
          <?php if ($totMet > 0): ?>
            <div class="barra-parte mb-3" role="img" aria-label="Participación por método de pago">
              <?php foreach (METODOS as $k => $lab): if (empty($porMetodo[$k]['s'])) continue; ?>
                <span style="flex: <?= number_format($porMetodo[$k]['s'], 2, '.', '') ?> 1 0; background: <?= $colorMet[$k] ?>"
                      title="<?= e($lab . ': $' . money($porMetodo[$k]['s']) . ' (' . pct($porMetodo[$k]['s'], $totMet) . ')') ?>"></span>
              <?php endforeach; ?>
            </div>
            <?php foreach (METODOS as $k => $lab): $s = $porMetodo[$k]['s'] ?? 0; $n = $porMetodo[$k]['n'] ?? 0; ?>
              <div class="leyenda-fila">
                <span class="punto" style="background: <?= $colorMet[$k] ?>"></span>
                <span><?= e($lab) ?> <span class="text-muted small">· <?= $n ?> <?= $n === 1 ? 'orden' : 'órdenes' ?></span></span>
                <span class="leyenda-monto">$<?= money($s) ?></span>
                <span class="leyenda-pct"><?= pct($s, $totMet) ?></span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="vacio">Sin ventas en este periodo</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php
    // Tarjetas de barras horizontales: una sola serie, así que un solo color
    // (slot 1) y sin leyenda: el título ya dice qué se grafica.
    $barras = [
      ['gProd', 'Productos más vendidos', 'Por ventas; el resto se agrupa en "Otros"', 'prod', 'Producto', 'Unid.'],
      ['gCat',  'Ventas por categoría',   'Agrupado por categoría principal',          'cat',  'Categoría', 'Unid.'],
      ['gVend', 'Ventas por vendedor',    'Quién registró cada venta',                  'vend', 'Vendedor',  'Órdenes'],
    ];
    foreach ($barras as [$id, $tit, $sub, $clave, $col, $colQ]):
      $filas = $datos[$clave];
      $alto  = max(160, 44 * max(1, count($filas)) + 40);
    ?>
      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-body">
            <p class="card-title-sm"><?= e($tit) ?></p>
            <div class="card-sub mb-2"><?= e($sub) ?></div>
            <?php if ($filas): ?>
              <div class="lienzo" style="height: <?= (int)$alto ?>px"><canvas id="<?= e($id) ?>" role="img" aria-label="<?= e($tit) ?>"></canvas></div>
            <?php else: ?>
              <div class="vacio">Sin ventas en este periodo</div>
            <?php endif; ?>
            <details class="datos mt-2">
              <summary>Ver datos</summary>
              <table class="table table-sm mt-2 mb-0">
                <thead><tr><th><?= e($col) ?></th><th class="text-end"><?= e($colQ) ?></th><th class="text-end">Ventas</th></tr></thead>
                <tbody>
                  <?php foreach ($filas as $r): ?>
                    <tr><td><?= e($r['n']) ?></td><td class="text-end"><?= (int)$r['q'] ?></td><td class="text-end">$<?= money($r['s']) ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </details>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="col-12 col-xl-6">
      <div class="card h-100">
        <div class="card-body">
          <p class="card-title-sm">Ventas por hora del día</p>
          <div class="card-sub mb-2">Acumulado del periodo: cuándo se vende más</div>
          <?php if ($K['ventas'] > 0): ?>
            <div class="lienzo"><canvas id="gHora" role="img" aria-label="Ventas por hora"></canvas></div>
          <?php else: ?>
            <div class="vacio">Sin ventas en este periodo</div>
          <?php endif; ?>
          <details class="datos mt-2">
            <summary>Ver datos</summary>
            <table class="table table-sm mt-2 mb-0">
              <thead><tr><th>Hora</th><th class="text-end">Ventas</th></tr></thead>
              <tbody>
                <?php foreach ($datos['hora'] as $r): ?>
                  <tr><td><?= e($r['n']) ?></td><td class="text-end">$<?= money($r['s']) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Versión fijada + SRI: si el archivo del CDN cambia, el navegador lo rechaza
     y la vista queda con sus tablas de datos (degradación prevista abajo). -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
        integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt"
        crossorigin="anonymous"></script>
<script>
(function () {
  const D = <?= json_encode($datos, $J) ?>;

  // Filtros: los selectores aplican al cambiar; tocar una fecha pasa a
  // "Personalizado". Mientras recarga, la vista se atenúa en vez de saltar.
  const form = document.getElementById('formFiltros');
  const rango = document.getElementById('fRango');
  const enviar = () => { document.querySelector('.dash')?.classList.add('actualizando'); form.submit(); };
  form.querySelectorAll('select.auto').forEach(s => s.addEventListener('change', enviar));
  rango.addEventListener('change', () => { if (rango.value !== 'custom') enviar(); });
  form.querySelectorAll('input.fecha').forEach(i => i.addEventListener('change', () => { rango.value = 'custom'; }));

  if (typeof Chart === 'undefined') return;   // sin CDN, quedan las tablas

  const css   = getComputedStyle(document.querySelector('.dash'));
  const tok   = n => css.getPropertyValue(n).trim();
  const S1 = tok('--serie-1'), GRID = tok('--grid'), EJE = tok('--eje'), MUTED = tok('--muted'), INK2 = tok('--ink-2');

  const moneda  = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
  const compact = new Intl.NumberFormat('es-CO', { notation: 'compact', maximumFractionDigits: 1 });
  const $c = v => '$' + compact.format(v);

  Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.color = MUTED;

  const tooltip = {
    backgroundColor: 'rgba(11,11,11,.9)', titleColor: '#fff', bodyColor: '#fff',
    padding: 10, cornerRadius: 8, boxWidth: 8, boxHeight: 8, boxPadding: 4, usePointStyle: true,
  };

  // Línea vertical de referencia bajo el cursor en la serie temporal.
  const crosshair = {
    id: 'crosshair',
    afterDatasetsDraw(chart) {
      const act = chart.tooltip && chart.tooltip.getActiveElements();
      if (!act || !act.length) return;
      const x = act[0].element.x, { top, bottom } = chart.chartArea, ctx = chart.ctx;
      ctx.save(); ctx.strokeStyle = EJE; ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, bottom); ctx.stroke(); ctx.restore();
    }
  };

  // Valor en la punta de cada barra horizontal, en tinta de texto (nunca en
  // el color de la serie). Hay margen reservado a la derecha para que quepa.
  const valorPunta = {
    id: 'valorPunta',
    afterDatasetsDraw(chart) {
      const ctx = chart.ctx, meta = chart.getDatasetMeta(0);
      ctx.save(); ctx.fillStyle = INK2; ctx.font = '600 12px system-ui, -apple-system, "Segoe UI", sans-serif';
      ctx.textBaseline = 'middle';
      // Valor exacto: es la lectura precisa de la barra. El compacto ("$2 K")
      // se queda para los ejes, donde solo hace falta orden de magnitud.
      meta.data.forEach((bar, i) => ctx.fillText(moneda.format(chart.data.datasets[0].data[i]), bar.x + 6, bar.y));
      ctx.restore();
    }
  };

  const fmtDia = s => { const [y, m, d] = s.split('-'); return d + '/' + m; };

  const el = id => document.getElementById(id);

  if (el('gDia')) {
    const muchos = D.serie.length > 45;
    new Chart(el('gDia'), {
      type: 'line',
      data: {
        labels: D.serie.map(r => fmtDia(r.d)),
        datasets: [{
          data: D.serie.map(r => r.s),
          borderColor: S1, borderWidth: 2, borderJoinStyle: 'round', borderCapStyle: 'round',
          // 'monotone' no sobrepasa los datos: con tensión libre la curva
          // bajaba de cero antes de un salto y dibujaba ventas negativas.
          backgroundColor: 'rgba(42,120,214,.10)', fill: true, cubicInterpolationMode: 'monotone',
          // Punto solo en días con venta: treinta puntos sobre el cero son ruido.
          pointRadius: ctx => (muchos || !ctx.raw) ? 0 : 4,
          pointBackgroundColor: S1, pointBorderColor: '#fff', pointBorderWidth: 2,
          pointHoverRadius: 5, pointHoverBorderWidth: 2, pointHoverBorderColor: '#fff', pointHitRadius: 14,
        }]
      },
      options: {
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: { ...tooltip, displayColors: false, callbacks: {
            title: it => D.serie[it[0].dataIndex].d.split('-').reverse().join('/'),
            label: it => moneda.format(it.parsed.y) + ' · ' + D.serie[it.dataIndex].n + ' órdenes',
          } }
        },
        scales: {
          x: { grid: { display: false }, border: { color: EJE }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
          y: { beginAtZero: true, grid: { color: GRID, lineWidth: 1 }, border: { display: false },
               ticks: { callback: v => $c(v), maxTicksLimit: 6 } }
        }
      },
      plugins: [crosshair]
    });
  }

  function barrasH(id, filas) {
    if (!el(id) || !filas.length) return;
    new Chart(el(id), {
      type: 'bar',
      data: {
        labels: filas.map(r => r.n.length > 28 ? r.n.slice(0, 27) + '…' : r.n),
        datasets: [{ data: filas.map(r => r.s), backgroundColor: S1, hoverBackgroundColor: '#256abf',
                     borderRadius: 4, borderSkipped: 'start', maxBarThickness: 24, categoryPercentage: .8 }]
      },
      options: {
        indexAxis: 'y', maintainAspectRatio: false,
        layout: { padding: { right: 96 } },
        plugins: {
          legend: { display: false },
          tooltip: { ...tooltip, displayColors: false, callbacks: {
            title: it => filas[it[0].dataIndex].n,
            label: it => moneda.format(it.parsed.x),
          } }
        },
        scales: {
          x: { beginAtZero: true, grid: { color: GRID }, border: { display: false },
               ticks: { callback: v => $c(v), maxTicksLimit: 5 } },
          y: { grid: { display: false }, border: { color: EJE }, ticks: { color: INK2 } }
        }
      },
      plugins: [valorPunta]
    });
  }
  barrasH('gProd', D.prod);
  barrasH('gCat',  D.cat);
  barrasH('gVend', D.vend);

  if (el('gHora')) {
    new Chart(el('gHora'), {
      type: 'bar',
      data: {
        labels: D.hora.map(r => r.n.slice(0, 2)),
        datasets: [{ data: D.hora.map(r => r.s), backgroundColor: S1, hoverBackgroundColor: '#256abf',
                     borderRadius: 4, borderSkipped: 'start', maxBarThickness: 24 }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { ...tooltip, displayColors: false, callbacks: {
            title: it => D.hora[it[0].dataIndex].n,
            label: it => moneda.format(it.parsed.y),
          } }
        },
        scales: {
          x: { grid: { display: false }, border: { color: EJE } },
          y: { beginAtZero: true, grid: { color: GRID }, border: { display: false },
               ticks: { callback: v => $c(v), maxTicksLimit: 5 } }
        }
      }
    });
  }
})();
</script>
<?php
$content = ob_get_clean();
render('Indicadores de venta', __DIR__ . '/../views/pages/_blank.php', ['content' => $content, 'cats' => []]);
