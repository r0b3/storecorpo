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

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0 me-auto">Catálogo</h4>

  <!-- Buscador de página: solo donde el de la navbar queda escondido dentro
       del menú colapsado. En escritorio había dos buscadores. -->
  <form class="d-flex d-lg-none flex-grow-1 buscador-pagina" method="get" action="<?= url('index.php') ?>">
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

<?php
/** Valores de una opción en orden útil: tallas por tamaño, el resto natural. */
function ordenar_valores(array $vals): array {
  static $rango = null;
  $rango ??= array_flip(['XXS','XS','S','M','L','XL','XXL','XXXL']);
  $vals = array_values(array_unique($vals));
  usort($vals, function ($a, $b) use ($rango) {
    $ra = $rango[strtoupper($a)] ?? null; $rb = $rango[strtoupper($b)] ?? null;
    if ($ra !== null && $rb !== null) return $ra <=> $rb;
    if ($ra !== null) return -1;
    if ($rb !== null) return 1;
    return strnatcasecmp($a, $b);
  });
  return $vals;
}
?>
<div class="row g-3 catalogo">
<?php foreach ($products as $p):
  $pid   = (int)$p['id'];
  $vlist = $variantsByProduct[$pid] ?? [];
  // Sin foto de producto, la de su primera variante con foto: tras unificar
  // colores en un producto, las fotos quedaron en las variantes.
  $img = $p['image'] ?? null;
  if (!$img) {
    foreach ($vlist as $vv) { if (!empty($vv['image'])) { $img = $vv['image']; break; } }
  }
  $img  = $img ?: 'placeholder.png';
  $desc = trim((string)($p['description'] ?? ''));
  $base = (float)$p['base_price'];

  // ¿Tiene opciones reales (talla, color…)? Si no, se vende como producto
  // simple aunque tenga una variante: esa variante solo guarda el stock, y
  // mostrarla como fila "Variante" no le decía nada al vendedor.
  $conOpciones = false;
  foreach ($vlist as $vv) {
    if (trim((string)($vv['option1_value'] ?? '')) !== '' || trim((string)($vv['option2_value'] ?? '')) !== '') { $conOpciones = true; break; }
  }

  $stockS = null; $vidS = '';
  if (!$conOpciones) {
    $vS     = $vlist[0] ?? null;
    $precio = ($vS && $vS['price'] !== null) ? (float)$vS['price'] : $base;
    $stockS = $vS ? (int)$vS['stock'] : null;
    $vidS   = $vS ? (int)$vS['id'] : '';
  } else {
    $vars = []; $vals1 = []; $vals2 = [];
    $nom1 = ''; $nom2 = '';
    foreach ($vlist as $vv) {
      $o1 = trim((string)($vv['option1_value'] ?? '')); $o2 = trim((string)($vv['option2_value'] ?? ''));
      if ($o1 !== '') { $vals1[] = $o1; $nom1 = $nom1 ?: trim((string)$vv['option1_name']); }
      if ($o2 !== '') { $vals2[] = $o2; $nom2 = $nom2 ?: trim((string)$vv['option2_name']); }
      $vars[] = [
        'id' => (int)$vv['id'], 'o1' => $o1, 'o2' => $o2,
        'precio' => $vv['price'] !== null ? (float)$vv['price'] : $base,
        'stock'  => (int)$vv['stock'],
        'img'    => (string)($vv['image'] ?? ''),
      ];
    }
    $vals1 = ordenar_valores($vals1);
    $vals2 = ordenar_valores($vals2);
    // Si el catálogo viene filtrado (p. ej. Talla=M), la tarjeta arranca en esa talla.
    $pref = ['o1' => $opt[$nom1] ?? '', 'o2' => $opt[$nom2] ?? ''];
    $precio = $vars[0]['precio'] ?? $base;
  }
  $cat_txt = !empty($p['category'])
    ? (!empty($p['parent_name']) ? $p['parent_name'] . ' › ' . $p['category'] : $p['category'])
    : '';
  $agotadoS = !$conOpciones && $stockS !== null && $stockS <= 0;
?>
  <div class="col-12 col-sm-6 col-lg-4 col-xxl-3">
    <div class="card h-100 shadow-sm producto">
      <img src="<?= e(url('uploads/' . $img)) ?>" class="card-img-top prod-foto" alt="<?= e($p['name']) ?>"
           data-orig="<?= e(url('uploads/' . $img)) ?>"
           onerror="this.onerror=null;this.src='<?= url('uploads/placeholder.png') ?>'">
      <div class="card-body d-flex flex-column">
        <?php if ($cat_txt !== ''): ?>
          <div class="prod-cat"><?= e($cat_txt) ?></div>
        <?php endif; ?>
        <h5 class="card-title prod-nombre"><?= e($p['name']) ?></h5>
        <?php if ($desc !== ''): ?>
          <div class="prod-desc text-muted small"><?= e($desc) ?></div>
        <?php endif; ?>

        <?php if ($conOpciones): ?>
          <div class="selector mt-2"
               data-variantes="<?= e(json_encode($vars, JSON_UNESCAPED_UNICODE)) ?>"
               data-pref="<?= e(json_encode($pref, JSON_UNESCAPED_UNICODE)) ?>"
               data-base-uploads="<?= e(url('uploads/')) ?>">
            <?php foreach ([1 => [$nom1, $vals1], 2 => [$nom2, $vals2]] as $dim => [$nom, $vals]): if (!$vals) continue; ?>
              <div class="mb-2">
                <div class="sel-label"><?= e($nom ?: 'Opción') ?>: <strong data-actual="<?= $dim ?>"></strong></div>
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="<?= e($nom ?: 'Opción') ?>">
                  <?php foreach ($vals as $val): ?>
                    <button type="button" class="chip" data-dim="<?= $dim ?>" data-val="<?= e($val) ?>"
                            aria-pressed="false"><?= e($val) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Zona de compra anclada al fondo: los botones quedan alineados entre
             tarjetas aunque una tenga más opciones que otra. -->
        <div class="mt-auto pt-2 compra">
          <div class="d-flex align-items-baseline justify-content-between mb-2">
            <span class="prod-precio">$<?= money($precio) ?></span>
            <span class="prod-estado small text-muted"><?php
              if (!$conOpciones) {
                if ($agotadoS) echo '<span class="badge text-bg-secondary">Agotado</span>';
                elseif ($stockS !== null) echo 'Stock: ' . (int)$stockS;
              }
            ?></span>
          </div>
          <div class="d-flex align-items-center gap-1 qty-group">
            <button class="btn btn-sm btn-outline-secondary btn-qty" data-target="#q_<?= $pid ?>" data-delta="-1"
                    type="button" aria-label="Menos">–</button>
            <input id="q_<?= $pid ?>" class="form-control form-control-sm text-center qty-input"
                   type="number" min="1" value="1" aria-label="Cantidad"
                   <?= (!$conOpciones && $stockS !== null && $stockS > 0) ? 'max="' . (int)$stockS . '"' : '' ?>>
            <button class="btn btn-sm btn-outline-secondary btn-qty" data-target="#q_<?= $pid ?>" data-delta="+1"
                    type="button" aria-label="Más">+</button>
            <button class="btn btn-sm btn-primary ms-1 btn-add flex-grow-1"
                    data-pid="<?= $pid ?>" data-vid="<?= $conOpciones ? '' : $vidS ?>"
                    data-qref="#q_<?= $pid ?>" type="button"
                    <?= $agotadoS ? 'disabled title="Sin stock"' : '' ?>>Agregar</button>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script>
// Selector de variantes: con Color y Talla elegidos se resuelve UNA variante;
// su precio, stock y foto pasan a la zona de compra y su id al botón Agregar
// (que sigue usando el manejador AJAX de siempre).
(function () {
  const moneda = n => '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(n);

  document.querySelectorAll('.selector').forEach(sel => {
    const V     = JSON.parse(sel.dataset.variantes || '[]');
    const pref  = JSON.parse(sel.dataset.pref || '{}');
    const baseU = sel.dataset.baseUploads || '';
    const card  = sel.closest('.producto');
    const dims  = [...new Set([...sel.querySelectorAll('.chip')].map(c => +c.dataset.dim))];
    const val   = (v, d) => d === 1 ? v.o1 : v.o2;
    const st    = {};

    // Arranque: lo que pida el filtro activo; si no, la primera con stock.
    const ini = V.find(v => (!pref.o1 || v.o1 === pref.o1) && (!pref.o2 || v.o2 === pref.o2) && v.stock > 0)
             || V.find(v => v.stock > 0) || V[0];
    dims.forEach(d => { st[d] = ini ? val(ini, d) : null; });

    const actual = () => V.find(v => dims.every(d => val(v, d) === st[d]));

    function pintar() {
      sel.querySelectorAll('.chip').forEach(ch => {
        const d = +ch.dataset.dim, x = ch.dataset.val, on = st[d] === x;
        ch.classList.toggle('activo', on);
        ch.setAttribute('aria-pressed', on ? 'true' : 'false');
        // Disponible = existe con lo elegido en la otra dimensión y tiene stock.
        const cand = V.find(v => val(v, d) === x && dims.every(o => o === d || val(v, o) === st[o]));
        ch.classList.toggle('sin-stock', !cand || cand.stock <= 0);
      });
      dims.forEach(d => { const t = sel.querySelector('[data-actual="' + d + '"]'); if (t) t.textContent = st[d] || ''; });

      const v = actual();
      const precio = card.querySelector('.prod-precio'), estado = card.querySelector('.prod-estado');
      const btn = card.querySelector('.btn-add'), inp = card.querySelector('.qty-input'), foto = card.querySelector('.prod-foto');

      if (!v) {
        estado.textContent = 'No existe esta combinación';
        btn.disabled = true; btn.dataset.vid = '';
        return;
      }
      precio.textContent = moneda(v.precio);
      btn.dataset.vid = String(v.id);
      if (v.stock <= 0) {
        estado.innerHTML = '<span class="badge text-bg-secondary">Agotado</span>';
        btn.disabled = true; inp.removeAttribute('max');
      } else {
        estado.textContent = 'Stock: ' + v.stock;
        btn.disabled = false; inp.max = v.stock;
        if (+inp.value > v.stock) inp.value = v.stock;
      }
      foto.src = v.img ? baseU + encodeURIComponent(v.img) : foto.dataset.orig;
    }

    sel.addEventListener('click', ev => {
      const ch = ev.target.closest('.chip');
      if (!ch) return;
      st[+ch.dataset.dim] = ch.dataset.val;
      pintar();
    });
    pintar();
  });
})();
</script>

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
