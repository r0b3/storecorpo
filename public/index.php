<?php
// public/index.php — Catálogo con filtro por categorías y controles +/– por variante

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../views/main.php';
require_once __DIR__ . '/_gate_private.php'; // portal privado

$pdo = get_pdo();

/* ===== Helpers locales ===== */
function has_column(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :c");
  $st->execute([':c'=>$column]);
  return (bool)$st->fetch();
}
function first_existing_column(PDO $pdo, string $table, array $cols): ?string {
  foreach ($cols as $c) if (has_column($pdo, $table, $c)) return $c;
  return null;
}
function product_image_col(PDO $pdo): ?string {
  return first_existing_column($pdo, 'products', ['image','cover','photo','picture','img']);
}

/* ===== Filtros ===== */
$q   = trim($_GET['q']   ?? '');
$cat = trim($_GET['cat'] ?? '');

// Filtros por opción de variante: ?opt[Talla]=M&opt[Color]=Rojo
// Se combinan entre sí (AND): cada uno exige que el producto tenga AL MENOS
// una variante activa con ese valor. Es un EXISTS y no un JOIN para no
// multiplicar filas por variante ni necesitar DISTINCT.
$opt = [];
foreach ((array)($_GET['opt'] ?? []) as $k => $v) {
  $k = trim((string)$k); $v = trim((string)$v);
  if ($k !== '' && $v !== '') { $opt[$k] = $v; }
}

$hasParent = has_column($pdo, 'categories', 'parent_id');

/* ===== Carga de productos con categoría (por slug) ===== */
$imgCol = product_image_col($pdo);
$imgSQL = $imgCol ? ", p.{$imgCol} AS image" : "";

// El JOIN al padre permite que filtrar por una categoría principal incluya
// también los productos de sus subcategorías.
$joinPadre = $hasParent ? " LEFT JOIN categories padre ON padre.id = c.parent_id" : "";
$selPadre  = $hasParent ? ", padre.name AS parent_name, padre.slug AS parent_slug" : "";

$sql = "SELECT p.id, p.name, p.description, p.base_price{$imgSQL},
               c.name AS category, c.slug AS category_slug{$selPadre}
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id{$joinPadre}
        WHERE 1=1";
$args = [];

if ($q !== '') {
  $sql .= " AND (p.name LIKE :q OR p.description LIKE :q)";
  $args[':q'] = "%{$q}%";
}
if ($cat !== '') {
  $sql .= $hasParent ? " AND (c.slug = :cat OR padre.slug = :cat)" : " AND c.slug = :cat";
  $args[':cat'] = $cat;
}
if ($opt) {
  // UN SOLO EXISTS con todas las condiciones dentro: los filtros deben
  // cumplirse en LA MISMA variante. Con un EXISTS por filtro, una camiseta
  // con (M, Rojo) y (L, Azul) colaba al pedir "M + Azul" aunque esa
  // combinación no exista. Pedir talla M y color azul significa que exista
  // la variante M-azul, que es la que se puede vender.
  $cond = [];
  $i = 0;
  foreach ($opt as $nombre => $valor) {
    $i++;
    $pn = ":on{$i}"; $pv = ":ov{$i}";
    $cond[] = "((v.option1_name = {$pn} AND v.option1_value = {$pv})
             OR (v.option2_name = {$pn} AND v.option2_value = {$pv}))";
    $args[$pn] = $nombre; $args[$pv] = $valor;
  }
  $sql .= " AND EXISTS (SELECT 1 FROM product_variants v
                         WHERE v.product_id = p.id
                           AND (v.active = 1 OR v.active IS NULL)
                           AND " . implode(" AND ", $cond) . ")";
}

$sql .= " ORDER BY p.created_at DESC, p.id DESC";
$st = $pdo->prepare($sql);
$st->execute($args);
$products = $st->fetchAll(PDO::FETCH_ASSOC);

/* ===== Variantes activas por producto ===== */
$variantsByProduct = [];
if ($products) {
  $ids = array_column($products, 'id');
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $sqlV = "SELECT v.*
           FROM product_variants v
           WHERE v.product_id IN ($in)
             AND (v.active = 1 OR v.active IS NULL)
           ORDER BY v.product_id, v.option1_value,
                    -- Tallas en orden de tamaño, no alfabético (L antes que M)
                    -- ni de creación. Valores desconocidos van al final.
                    FIELD(UPPER(v.option2_value), 'XXS','XS','S','M','L','XL','XXL','XXXL') = 0,
                    FIELD(UPPER(v.option2_value), 'XXS','XS','S','M','L','XL','XXL','XXXL'),
                    v.option2_value, v.id";
  $sv = $pdo->prepare($sqlV);
  $sv->execute($ids);
  foreach ($sv->fetchAll(PDO::FETCH_ASSOC) as $v) {
    $variantsByProduct[(int)$v['product_id']][] = $v;
  }
}

/* ===== Categorías (para el selector) ===== */
$catCols = $hasParent ? "id, name, slug, parent_id" : "id, name, slug";
$cats = $pdo->query("SELECT {$catCols} FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Facetas de variante: valores realmente en uso, para no ofrecer filtros vacíos.
$facetas = [];
foreach ([1, 2] as $n) {
  $rows = $pdo->query(
    "SELECT DISTINCT option{$n}_name AS nombre, option{$n}_value AS valor
       FROM product_variants
      WHERE (active = 1 OR active IS NULL)
        AND option{$n}_name IS NOT NULL AND option{$n}_name <> ''
        AND option{$n}_value IS NOT NULL AND option{$n}_value <> ''
      ORDER BY nombre, valor"
  )->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) { $facetas[$r['nombre']][$r['valor']] = true; }
}
// Tallas por tamaño (M, L, XL), no alfabético (L, M, XL); el resto, natural.
$ordenTalla = array_flip(['XXS','XS','S','M','L','XL','XXL','XXXL']);
foreach ($facetas as $n => $vals) {
  $lista = array_keys($vals);
  usort($lista, function ($a, $b) use ($ordenTalla) {
    $ra = $ordenTalla[strtoupper($a)] ?? null; $rb = $ordenTalla[strtoupper($b)] ?? null;
    if ($ra !== null && $rb !== null) return $ra <=> $rb;
    if ($ra !== null) return -1;
    if ($rb !== null) return 1;
    return strnatcasecmp($a, $b);
  });
  $facetas[$n] = $lista;
}

// Padres y, si hay uno seleccionado, sus hijas (para la segunda fila de píldoras).
$padres = array_values(array_filter($cats, fn($c) => empty($c['parent_id'])));
$catSel = null;
foreach ($cats as $c) { if ((string)$c['slug'] === $cat) { $catSel = $c; break; } }
$ramaId = $catSel ? (int)($catSel['parent_id'] ?: $catSel['id']) : 0;
$hijas  = $ramaId ? array_values(array_filter($cats, fn($c) => (int)($c['parent_id'] ?? 0) === $ramaId)) : [];
$ramaSlug = '';
if ($ramaId) { foreach ($cats as $c) { if ((int)$c['id'] === $ramaId) { $ramaSlug = (string)$c['slug']; break; } } }

/** Conserva los filtros vigentes al construir un enlace. */
function url_filtros(array $cambios): string {
  $qs = array_merge(['q' => $_GET['q'] ?? '', 'cat' => $_GET['cat'] ?? '', 'opt' => (array)($_GET['opt'] ?? [])], $cambios);
  $qs = array_filter($qs, fn($v) => $v !== '' && $v !== [] && $v !== null);
  return url('index.php') . ($qs ? '?' . http_build_query($qs) : '');
}

/* ===== Render ===== */
ob_start(); ?>

<div class="d-flex align-items-center mb-3">
  <h4 class="mb-0">Catálogo</h4>

  <!-- Buscador -->
  <form class="ms-auto d-flex" method="get" action="<?= url('index.php') ?>">
    <?php if ($cat !== ''): ?>
      <input type="hidden" name="cat" value="<?= e($cat) ?>">
    <?php endif; ?>
    <input class="form-control me-2" type="search" placeholder="Buscar" name="q" value="<?= e($q) ?>">
    <button class="btn btn-outline-secondary" type="submit">Buscar</button>
  </form>
</div>

<!-- Filtros: categorías principales, subcategorías y opciones de variante -->
<div class="mb-3">
  <div class="d-flex flex-wrap gap-2 align-items-center filtros-cat">
    <a class="btn btn-sm <?= $cat==='' ? 'btn-primary' : 'btn-outline-secondary' ?>"
       href="<?= e(url_filtros(['cat' => ''])) ?>">Todas</a>
    <?php foreach ($padres as $c):
      // Una principal queda marcada también cuando lo elegido es una hija suya.
      $activa = ($cat === (string)$c['slug']) || ($ramaId === (int)$c['id']);
    ?>
      <a class="btn btn-sm <?= $activa ? 'btn-primary' : 'btn-outline-secondary' ?>"
         href="<?= e(url_filtros(['cat' => $c['slug']])) ?>"><?= e($c['name']) ?></a>
    <?php endforeach; ?>

    <?php if ($cat !== '' || $q !== '' || $opt): ?>
      <a class="btn btn-sm btn-outline-dark ms-auto" href="<?= url('index.php') ?>">Limpiar filtros</a>
    <?php endif; ?>
  </div>

  <?php if ($hijas): ?>
    <!-- Subcategorías de la rama elegida. "Todo en X" vuelve al padre, que
         incluye los productos de todas sus hijas. -->
    <div class="d-flex flex-wrap gap-2 align-items-center filtros-cat mt-2 ms-1">
      <span class="small text-muted me-1">Subcategorías:</span>
      <a class="btn btn-sm <?= $cat === $ramaSlug ? 'btn-secondary' : 'btn-outline-secondary' ?>"
         href="<?= e(url_filtros(['cat' => $ramaSlug])) ?>">Todo</a>
      <?php foreach ($hijas as $h): ?>
        <a class="btn btn-sm <?= $cat === (string)$h['slug'] ? 'btn-secondary' : 'btn-outline-secondary' ?>"
           href="<?= e(url_filtros(['cat' => $h['slug']])) ?>"><?= e($h['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php foreach ($facetas as $nombre => $valores): ?>
    <div class="d-flex flex-wrap gap-2 align-items-center filtros-cat mt-2 ms-1">
      <span class="small text-muted me-1"><?= e($nombre) ?>:</span>
      <?php foreach ($valores as $v):
        $puesto = (($opt[$nombre] ?? '') === $v);
        // Volver a pulsar el valor activo lo quita: el filtro es un interruptor.
        $nuevoOpt = $opt; if ($puesto) { unset($nuevoOpt[$nombre]); } else { $nuevoOpt[$nombre] = $v; }
      ?>
        <a class="btn btn-sm <?= $puesto ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="<?= e(url_filtros(['opt' => $nuevoOpt])) ?>"><?= e($v) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!$products): ?>
  <div class="alert alert-info">No hay productos que coincidan con los filtros.</div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($products as $p):
  $pid   = (int)$p['id'];
  // Sin foto de producto, la de su primera variante con foto: tras unificar
  // colores en un producto, las fotos quedaron en las variantes.
  $img   = $p['image'] ?? null;
  if (!$img) {
    foreach ($variantsByProduct[(int)$p['id']] ?? [] as $vv) {
      if (!empty($vv['image'])) { $img = $vv['image']; break; }
    }
  }
  $img   = $img ?: 'placeholder.png';
  $desc  = (string)($p['description'] ?? '');
  $vlist = $variantsByProduct[$pid] ?? [];
?>
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100 shadow-sm">
      <img src="<?= url('uploads/' . $img) ?>" class="card-img-top"
           onerror="this.src='<?= url('uploads/placeholder.png') ?>'">
      <div class="card-body d-flex flex-column">
        <h5 class="card-title mb-1"><?= e($p['name']) ?></h5>
        <?php if (!empty($p['category'])): ?>
          <div class="mb-1"><span class="badge bg-light text-dark"><?php
            echo e(!empty($p['parent_name']) ? $p['parent_name'] . ' › ' . $p['category'] : $p['category']);
          ?></span></div>
        <?php endif; ?>
        <div class="text-muted small mb-2">
          <?= e($desc !== '' ? mb_strimwidth($desc,0,120,'…','UTF-8') : '') ?>
        </div>

        <?php if (empty($vlist)): ?>
          <!-- Sin variantes: precio base -->
          <div class="border rounded p-2 d-flex align-items-center justify-content-between mb-2 linea-compra">
            <div>
              <div class="small text-muted">Precio</div>
              <div class="fw-semibold">$<?= money($p['base_price']) ?></div>
            </div>
            <div class="d-flex align-items-center gap-1 qty-group">
              <button class="btn btn-sm btn-outline-secondary btn-qty" data-target="#q_<?= $pid ?>_0" data-delta="-1" type="button">–</button>
              <input id="q_<?= $pid ?>_0" class="form-control form-control-sm text-center qty-input"
                     type="number" min="1" value="1">
              <button class="btn btn-sm btn-outline-secondary btn-qty" data-target="#q_<?= $pid ?>_0" data-delta="+1" type="button">+</button>
              <button class="btn btn-sm btn-primary ms-1 btn-add"
                      data-pid="<?= $pid ?>" data-vid=""
                      data-qref="#q_<?= $pid ?>_0" type="button">Agregar</button>
            </div>
          </div>
        <?php else: ?>
          <!-- Con variantes: lista con controles -->
          <div class="small text-muted mb-1">Variantes</div>
          <div class="list-group list-group-flush">
            <?php foreach ($vlist as $v):
              $vid = (int)$v['id'];
              $o1n = trim((string)($v['option1_name'] ?? ''));
              $o1v = trim((string)($v['option1_value'] ?? ''));
              $o2n = trim((string)($v['option2_name'] ?? ''));
              $o2v = trim((string)($v['option2_value'] ?? ''));
              $labelParts = [];
              if ($o1n !== '' || $o1v !== '') $labelParts[] = ($o1n!==''?$o1n.': ':'').($o1v!==''?$o1v:'');
              if ($o2n !== '' || $o2v !== '') $labelParts[] = ($o2n!==''?$o2n.': ':'').($o2v!==''?$o2v:'');
              $label = implode(' / ', array_filter($labelParts, fn($x)=>trim($x) !== ''));
              $price = $v['price'] !== null ? (float)$v['price'] : (float)$p['base_price'];
              $stock = isset($v['stock']) ? (int)$v['stock'] : null;
            ?>
              <div class="list-group-item d-flex align-items-center justify-content-between linea-compra">
                <?php if (!empty($v['image'])): ?>
                  <img src="<?= url('uploads/' . $v['image']) ?>" alt="" class="var-mini me-2"
                       width="40" height="40" loading="lazy" onerror="this.remove()">
                <?php endif; ?>
                <div class="me-2 flex-grow-1">
                  <div class="fw-semibold"><?= e($label ?: 'Variante') ?></div>
                  <div class="small text-muted">
                    $<?= money($price) ?><?= ($stock!==null?' · Stock: '.(int)$stock:'') ?>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-1 qty-group">
                  <button class="btn btn-sm btn-outline-secondary btn-qty"
                          data-target="#q_<?= $pid ?>_<?= $vid ?>" data-delta="-1" type="button">–</button>
                  <input id="q_<?= $pid ?>_<?= $vid ?>" class="form-control form-control-sm text-center qty-input" type="number" min="1" value="1"
                         <?= ($stock!==null && $stock>0) ? 'max="'.$stock.'"' : '' ?>>
                  <button class="btn btn-sm btn-outline-secondary btn-qty"
                          data-target="#q_<?= $pid ?>_<?= $vid ?>" data-delta="+1" type="button">+</button>
                  <button class="btn btn-sm btn-primary ms-1 btn-add"
                          data-pid="<?= $pid ?>" data-vid="<?= $vid ?>"
                          data-qref="#q_<?= $pid ?>_<?= $vid ?>" type="button">Agregar</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="mt-auto"></div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Toast de confirmación -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="cartToast" class="toast" role="status" aria-live="polite" aria-atomic="true">
    <div class="toast-header">
      <strong class="me-auto">Carrito</strong>
      <small>Ahora</small>
      <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Cerrar"></button>
    </div>
    <div class="toast-body">Producto agregado.</div>
  </div>
</div>

<script>
// Botones +/- para inputs
document.addEventListener('click', function (ev) {
  const b = ev.target.closest('.btn-qty');
  if (!b) return;
  const target = b.getAttribute('data-target');
  const delta  = b.getAttribute('data-delta') === '-1' ? -1 : 1;
  const input  = document.querySelector(target);
  if (!input) return;

  const min = parseInt(input.getAttribute('min') || '1', 10);
  const maxAttr = input.getAttribute('max');
  const max = maxAttr ? parseInt(maxAttr, 10) : Infinity;

  let val = parseInt(input.value || '1', 10);
  if (isNaN(val)) val = 1;
  val = Math.min(max, Math.max(min, val + delta));
  input.value = String(val);
});

// Agregar al carrito (AJAX)
document.addEventListener('click', async function (ev) {
  const btn = ev.target.closest('.btn-add');
  if (!btn) return;

  const pid  = parseInt(btn.getAttribute('data-pid') || '0', 10);
  const vidS = btn.getAttribute('data-vid') || '';
  const vid  = vidS === '' ? '' : parseInt(vidS, 10);
  const qref = btn.getAttribute('data-qref') || '';
  const inp  = qref ? document.querySelector(qref) : null;
  const qty  = Math.max(1, parseInt(inp?.value || '1', 10));

  try {
    const res = await window.csrfFetch('<?= url('cart.php') ?>', {
  method: 'POST',
  headers: { 'Accept': 'application/json' },
  body: {
    action: 'add',
    product_id: String(pid),
    variant_id: vid === '' ? '' : String(vid),
    qty: String(qty),
    ajax: '1',                 // ← fuerza respuesta JSON aunque el server no “detecte” AJAX
  }
});


    let data = null;
    const ct = (res.headers.get('Content-Type') || '').toLowerCase();
    if (ct.includes('application/json')) {
      data = await res.json();
    } else {
      // Fallback PRG si tu cart.php no devuelve JSON
      location.href = '<?= url('cart.php') ?>';
      return;
    }

    if (!res.ok || !data?.ok) {
      throw new Error(data?.message || 'No se pudo agregar al carrito');
    }

    // Actualiza badge del carrito
    const badge = document.getElementById('cartBadge');
    if (badge && typeof data.items !== 'undefined') {
      badge.textContent = String(data.items);
    }

    // Muestra toast
    const toastEl = document.getElementById('cartToast');
    if (toastEl) {
      toastEl.querySelector('.toast-body').textContent = data.message || 'Producto agregado.';
      const toast = new bootstrap.Toast(toastEl);
      toast.show();
    }

  } catch (err) {
    console.error(err);
    alert(err.message || 'Error al agregar al carrito.');
  }
});
</script>
<?php
$content = ob_get_clean();
render('Tienda - Inicio', __DIR__ . '/../views/pages/_blank.php', [
  'content' => $content,
  'cats'    => $cats,
]);
