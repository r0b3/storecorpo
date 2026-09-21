<?php
// public/admin_inventory.php — Inventario (solo Admin)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_login();
require_roles(['Admin']); // restringe a Admin
require_once __DIR__ . '/../views/main.php';
require __DIR__ . '/_gate_private.php'; // ← añade esta línea


$pdo = get_pdo();

$hasProductImage = has_column($pdo, 'products', 'image');
$hasVariantImage = has_column($pdo, 'product_variants', 'image');

/* ========= Helpers de columnas y slug ========= */
function has_column(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :c");
  $st->execute([':c'=>$column]);
  return (bool)$st->fetch();
}
function slugify(string $txt): string {
  $s = iconv('UTF-8','ASCII//TRANSLIT',$txt);
  $s = strtolower(preg_replace('~[^a-z0-9]+~','-',$s));
  $s = trim($s, '-');
  return $s ?: 'item';
}
function unique_slug(PDO $pdo, string $base): string {
  $slug = $base; $i = 1;
  $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE slug = :s");
  while (true) {
    $st->execute([':s'=>$slug]);
    if ((int)$st->fetchColumn() === 0) return $slug;
    $i++; $slug = $base . '-' . $i;
  }
}
function flash_redirect(string $to, bool $ok, string $msg): void {
  $q = http_build_query(['ok'=>$ok?'1':'0', 'msg'=>$msg]);
  redirect($to . (str_contains($to,'?') ? '&' : '?') . $q);
}

/* ========= ACCIONES (POST) ========= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  ensure_csrf();
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'create_product') {
  $name  = trim($_POST['name'] ?? '');
  $desc  = trim($_POST['description'] ?? '');
  $price = (float)($_POST['base_price'] ?? 0);
  $cat   = ($_POST['category_id'] ?? '') === '' ? null : (int)$_POST['category_id'];

  if ($name === '') throw new RuntimeException('El nombre es obligatorio.');
  if ($price < 0)   throw new RuntimeException('El precio base no puede ser negativo.');

  // ¿La tabla tiene columna slug?
  $hasSlug = has_column($pdo, 'products', 'slug');
  $slug = null;
  if ($hasSlug) {
    $base = slugify($name);
    $slug = unique_slug($pdo, $base);
  }

  // Imagen principal (opcional)
  $imageName = null;
  if ($hasProductImage && !empty($_FILES['image']['name'] ?? '')) {
    $imageName = save_uploaded_image($_FILES['image'], 'p_'); // prefijo para productos
  }

  if ($hasSlug && $hasProductImage) {
    $sql = "INSERT INTO products (name, slug, description, base_price, category_id, image, created_at)
            VALUES (:n,:s,:d,:p,:c,:img,NOW())";
    $params = [':n'=>$name, ':s'=>$slug, ':d'=>$desc, ':p'=>$price, ':c'=>$cat, ':img'=>$imageName];
  } elseif ($hasSlug && !$hasProductImage) {
    $sql = "INSERT INTO products (name, slug, description, base_price, category_id, created_at)
            VALUES (:n,:s,:d,:p,:c,NOW())";
    $params = [':n'=>$name, ':s'=>$slug, ':d'=>$desc, ':p'=>$price, ':c'=>$cat];
  } elseif (!$hasSlug && $hasProductImage) {
    $sql = "INSERT INTO products (name, description, base_price, category_id, image, created_at)
            VALUES (:n,:d,:p,:c,:img,NOW())";
    $params = [':n'=>$name, ':d'=>$desc, ':p'=>$price, ':c'=>$cat, ':img'=>$imageName];
  } else {
    $sql = "INSERT INTO products (name, description, base_price, category_id, created_at)
            VALUES (:n,:d,:p,:c,NOW())";
    $params = [':n'=>$name, ':d'=>$desc, ':p'=>$price, ':c'=>$cat];
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);

  flash_redirect('admin_inventory.php', true, 'Producto creado correctamente.');
}



elseif ($action === 'update_product') {
  $id    = (int)($_POST['id'] ?? 0);
  $name  = trim($_POST['name'] ?? '');
  $desc  = trim($_POST['description'] ?? '');
  $price = (float)($_POST['base_price'] ?? 0);
  $cat   = ($_POST['category_id'] ?? '') === '' ? null : (int)$_POST['category_id'];

  if ($id <= 0)     throw new RuntimeException('ID de producto inválido.');
  if ($name === '') throw new RuntimeException('El nombre es obligatorio.');
  if ($price < 0)   throw new RuntimeException('El precio base no puede ser negativo.');

  $params = [':n'=>$name, ':d'=>$desc, ':p'=>$price, ':c'=>$cat, ':id'=>$id];

  if ($hasProductImage && !empty($_FILES['image']['name'] ?? '')) {
    $imageName = save_uploaded_image($_FILES['image'], 'p_');
    $sql = "UPDATE products SET name=:n, description=:d, base_price=:p, category_id=:c, image=:img WHERE id=:id";
    $params[':img'] = $imageName;
  } else {
    $sql = "UPDATE products SET name=:n, description=:d, base_price=:p, category_id=:c WHERE id=:id";
  }

  $pdo->prepare($sql)->execute($params);
  flash_redirect('admin_inventory.php', true, 'Producto actualizado.');
}
 elseif ($action === 'delete_product') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID inválido para eliminar.');

      // Borra variantes (si existen) y producto
      $pdo->prepare("DELETE FROM product_variants WHERE product_id=:id")->execute([':id'=>$id]);
      $pdo->prepare("DELETE FROM products WHERE id=:id")->execute([':id'=>$id]);
      flash_redirect('admin_inventory.php', true, 'Producto eliminado.');

    } elseif ($action === 'add_variant') {
  $pid  = (int)($_POST['product_id'] ?? 0);
  if ($pid <= 0) throw new RuntimeException('Producto inválido para la variante.');

  $chk = $pdo->prepare("SELECT id FROM products WHERE id = :id");
  $chk->execute([':id'=>$pid]);
  if (!$chk->fetchColumn()) throw new RuntimeException('El producto no existe (ID: '.$pid.').');

  $o1n  = trim($_POST['option1_name'] ?? '');
  $o1v  = trim($_POST['option1_value'] ?? '');
  $o2n  = trim($_POST['option2_name'] ?? '');
  $o2v  = trim($_POST['option2_value'] ?? '');
  $sku  = trim($_POST['sku'] ?? '');
  $stk  = (int)($_POST['stock'] ?? 0);
  $prc  = strlen($_POST['price'] ?? '') ? (float)$_POST['price'] : null;
  $act  = (int)($_POST['active'] ?? 1);

  $imageName = null;
  if ($hasVariantImage && !empty($_FILES['v_image']['name'] ?? '')) {
    $imageName = save_uploaded_image($_FILES['v_image'], 'v_'); // prefijo variante
  }

  if ($hasVariantImage) {
    $sql = "INSERT INTO product_variants
              (product_id, option1_name, option1_value, option2_name, option2_value,
               sku, stock, price, active, image)
            VALUES
              (:p,:o1n,:o1v,:o2n,:o2v,:sku,:stk,:prc,:act,:img)";
    $ok = $pdo->prepare($sql)->execute([
      ':p'=>$pid,
      ':o1n'=>$o1n?:null, ':o1v'=>$o1v?:null,
      ':o2n'=>$o2n?:null, ':o2v'=>$o2v?:null,
      ':sku'=>$sku?:null, ':stk'=>$stk,
      ':prc'=>$prc, ':act'=>$act?1:0,
      ':img'=>$imageName
    ]);
  } else {
    $sql = "INSERT INTO product_variants
              (product_id, option1_name, option1_value, option2_name, option2_value,
               sku, stock, price, active)
            VALUES
              (:p,:o1n,:o1v,:o2n,:o2v,:sku,:stk,:prc,:act)";
    $ok = $pdo->prepare($sql)->execute([
      ':p'=>$pid,
      ':o1n'=>$o1n?:null, ':o1v'=>$o1v?:null,
      ':o2n'=>$o2n?:null, ':o2v'=>$o2v?:null,
      ':sku'=>$sku?:null, ':stk'=>$stk,
      ':prc'=>$prc, ':act'=>$act?1:0
    ]);
  }

  if (!$ok || !$pdo->lastInsertId()) throw new RuntimeException('No se pudo insertar la variante.');
  flash_redirect('admin_inventory.php#v'.$pid, true, 'Variante creada.');
} 
elseif ($action === 'update_variant') {
  $vid  = (int)($_POST['variant_id'] ?? 0);
  $pid  = (int)($_POST['product_id'] ?? 0);
  if ($vid<=0 || $pid<=0) throw new RuntimeException('Variante o producto inválido.');

  $o1n  = trim($_POST['option1_name'] ?? '');
  $o1v  = trim($_POST['option1_value'] ?? '');
  $o2n  = trim($_POST['option2_name'] ?? '');
  $o2v  = trim($_POST['option2_value'] ?? '');
  $sku  = trim($_POST['sku'] ?? '');
  $stk  = (int)($_POST['stock'] ?? 0);
  $prc  = strlen($_POST['price'] ?? '') ? (float)$_POST['price'] : null;
  $act  = (int)($_POST['active'] ?? 1);

  $params = [
    ':o1n'=>$o1n?:null, ':o1v'=>$o1v?:null,
    ':o2n'=>$o2n?:null, ':o2v'=>$o2v?:null,
    ':sku'=>$sku?:null, ':stk'=>$stk, ':prc'=>$prc, ':act'=>$act?1:0,
    ':id'=>$vid, ':pid'=>$pid
  ];

  if ($hasVariantImage && !empty($_FILES['v_image']['name'] ?? '')) {
    $imageName = save_uploaded_image($_FILES['v_image'], 'v_');
    $sql = "UPDATE product_variants SET
              option1_name=:o1n, option1_value=:o1v,
              option2_name=:o2n, option2_value=:o2v,
              sku=:sku, stock=:stk, price=:prc, active=:act, image=:img
            WHERE id=:id AND product_id=:pid";
    $params[':img'] = $imageName;
  } else {
    $sql = "UPDATE product_variants SET
              option1_name=:o1n, option1_value=:o1v,
              option2_name=:o2n, option2_value=:o2v,
              sku=:sku, stock=:stk, price=:prc, active=:act
            WHERE id=:id AND product_id=:pid";
  }

  $pdo->prepare($sql)->execute($params);
  flash_redirect('admin_inventory.php#v'.$pid, true, 'Variante actualizada.');
}
      elseif ($action === 'delete_variant') {
      $vid = (int)($_POST['variant_id'] ?? 0);
      $pid = (int)($_POST['product_id'] ?? 0);
      if ($vid<=0 || $pid<=0) throw new RuntimeException('Variante o producto inválido.');

      $pdo->prepare("DELETE FROM product_variants WHERE id=:id AND product_id=:pid")->execute([':id'=>$vid, ':pid'=>$pid]);
      flash_redirect('admin_inventory.php#v'.$pid, true, 'Variante eliminada.');

    } else {
      throw new RuntimeException('Acción no reconocida.');
    }

  } catch (Throwable $e) {
    error_log('[admin_inventory POST] ' . $e->getMessage());
    flash_redirect('admin_inventory.php', false, $e->getMessage());
  }
}

/* ========= CARGA DE DATOS ========= */
$catsCols = has_column($pdo, 'categories', 'slug') ? 'id, name, slug' : 'id, name';
$cats = $pdo->query("SELECT {$catsCols} FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

     $sql = "SELECT
          p.id,
          p.name,
          p.description,
          p.base_price,
          p.category_id,
          p.image,
          COALESCE(SUM(CASE WHEN v.active=1 THEN v.stock ELSE 0 END),0) AS total_stock
        FROM products p
        LEFT JOIN product_variants v ON v.product_id = p.id
        GROUP BY p.id, p.name, p.description, p.base_price, p.category_id, p.image
        ORDER BY p.created_at DESC";

$products = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Variantes por producto (para poblar el modal sin AJAX)
$variantsByProduct = [];
if ($products) {
  $ids = array_map(fn($r)=>$r['id'], $products);
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $vs  = $pdo->prepare("SELECT * FROM product_variants WHERE product_id IN ($in) ORDER BY product_id, id");
  $vs->execute($ids);
  foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) {
    $pid = (int)$v['product_id'];
    if (!isset($variantsByProduct[$pid])) $variantsByProduct[$pid] = [];
    $variantsByProduct[$pid][] = $v;
  }
}


/* ========= UI ========= */
ob_start();

// ALERTAS
$ok  = ($_GET['ok'] ?? '') === '1';
$msg = trim($_GET['msg'] ?? '');
if ($msg !== '') {
  echo '<div class="alert '.($ok?'alert-success':'alert-danger').' alert-dismissible fade show" role="alert">'
     . e($msg)
     . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>'
     . '</div>';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Inventario</h4>
  <div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNewProduct">Nuevo producto</button>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th>Producto</th>
            <th class="text-end" style="width:140px">Precio base</th>
            <th class="text-end" style="width:120px">Stock total</th>
            <th style="width:320px"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="5" class="text-muted">No hay productos aún.</td></tr>
        <?php else: foreach ($products as $p): ?>
          <tr>
            <td>#<?= (int)$p['id'] ?></td>
            <td>
  <div class="d-flex align-items-center gap-2">
    <?php if (!empty($p['image'])): ?>
      <img src="<?= url('uploads/' . $p['image']) ?>" alt=""
           class="rounded border" style="width:48px;height:48px;object-fit:cover"
           onerror="this.style.display='none'">
    <?php endif; ?>
    <div>
      <div class="fw-semibold"><?= e($p['name']) ?></div>
      <div class="small text-muted"><?= e($p['description'] ?: '') ?></div>
    </div>
  </div>
</td>

              <td class="text-end">$<?= money($p['base_price']) ?></td>
              <td class="text-end"><?= (int)$p['total_stock'] ?></td>
              <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary me-1"
                      data-bs-toggle="modal" data-bs-target="#modalVariants"
                      data-pid="<?= (int)$p['id'] ?>">Variantes</button>

              <button class="btn btn-sm btn-outline-primary me-1"
                      data-bs-toggle="modal" data-bs-target="#modalEditProduct"
                      data-pid="<?= (int)$p['id'] ?>"
                      data-name="<?= e($p['name']) ?>"
                      data-desc="<?= e($p['description'] ?: '') ?>"
                      data-price="<?= (float)$p['base_price'] ?>"
                      data-cat="<?= (int)($p['category_id'] ?: 0) ?>"
                      data-image="<?= e($p['image'] ?? '') ?>">Editar</button>

              <button class="btn btn-sm btn-outline-danger"
                      data-bs-toggle="modal" data-bs-target="#modalDeleteProduct"
                      data-pid="<?= (int)$p['id'] ?>"
                      data-name="<?= e($p['name']) ?>">Eliminar</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL: NUEVO PRODUCTO -->
<div class="modal fade" id="modalNewProduct" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="<?= url('admin_inventory.php') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_product">
        <div class="modal-header">
          <h5 class="modal-title">Nuevo producto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Nombre</label>
              <input class="form-control" name="name" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Precio base</label>
              <input class="form-control" type="number" step="0.01" min="0" name="base_price" value="0" required>
            </div>
            
            <div class="col-md-6">
              <label class="form-label">Categoría</label>
              <select class="form-select" name="category_id">
                <option value="">(Ninguna)</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Descripción</label>
              <textarea class="form-control" name="description" rows="3"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Imagen principal</label>
              <input class="form-control" type="file" name="image" accept="image/*">
              <div class="form-text">JPG/PNG/WebP, máx 5MB</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Crear</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: EDITAR PRODUCTO -->
<div class="modal fade" id="modalEditProduct" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="<?= url('admin_inventory.php') ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_product">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header">
          <h5 class="modal-title">Editar producto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Nombre</label>
              <input class="form-control" name="name" id="edit_name" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Precio base</label>
              <input class="form-control" type="number" step="0.01" min="0" name="base_price" id="edit_price" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Categoría</label>
              <select class="form-select" name="category_id" id="edit_cat">
                <option value="">(Ninguna)</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Descripción</label>
              <textarea class="form-control" name="description" rows="3" id="edit_desc"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Imagen principal</label>
              <input class="form-control" type="file" name="image" accept="image/*">
              <div class="form-text">Deja vacío para conservar la actual.</div>
              <div class="mt-2" id="edit_img_preview"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: ELIMINAR PRODUCTO -->
<div class="modal fade" id="modalDeleteProduct" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= url('admin_inventory.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_product">
        <input type="hidden" name="id" id="del_id">
        <div class="modal-header">
          <h5 class="modal-title">Eliminar producto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p>¿Seguro que deseas eliminar el producto <strong id="del_name"></strong>?
             <br><small class="text-muted">Se eliminarán también sus variantes.</small>
          </p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-danger" type="submit">Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: VARIANTES -->
<div class="modal fade" id="modalVariants" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Variantes del producto <span id="vprod_name" class="fw-semibold"></span> (ID <span id="vprod_id"></span>)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          <strong>¿Qué es una variante?</strong> Una combinación de opciones (p. ej. <em>Talla</em>=M, <em>Color</em>=Azul)
          con su propio <em>stock</em>, <em>SKU</em> y <em>precio opcional</em>.
          Si la variante no tiene precio, se usará el precio base del producto.
        </p>

        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle" id="tblVariants">
            <thead>
              <tr>
                <th>ID</th>
                <th>Opción 1</th>
                <th>Opción 2</th>
                <th>SKU</th>
                <th class="text-end">Stock</th>
                <th class="text-end">Precio</th>
                <th>Activa</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody><!-- filas por JS --></tbody>
          </table>
        </div>

        <div class="card">
          <div class="card-body">
            <form method="post" action="<?= url('admin_inventory.php') ?>" id="formVariant" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_variant" id="variant_action">
            <input type="hidden" name="product_id" id="variant_product_id">
            <input type="hidden" name="variant_id" id="variant_id">
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label">Opción 1 (ej. Talla)</label>
                  <input class="form-control" name="option1_name" id="v_o1n" placeholder="Talla">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Valor 1 (ej. M)</label>
                  <input class="form-control" name="option1_value" id="v_o1v" placeholder="M">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Opción 2 (ej. Color)</label>
                  <input class="form-control" name="option2_name" id="v_o2n" placeholder="Color">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Valor 2 (ej. Azul)</label>
                  <input class="form-control" name="option2_value" id="v_o2v" placeholder="Azul">
                </div>

                <div class="col-md-3">
                  <label class="form-label">SKU</label>
                  <input class="form-control" name="sku" id="v_sku">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Stock</label>
                  <input class="form-control" type="number" min="0" name="stock" id="v_stock" value="0">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Precio (opcional)</label>
                  <input class="form-control" type="number" step="0.01" min="0" name="price" id="v_price" placeholder="(vacío = base)">
                </div>
                <div class="col-md-3">
                  <label class="form-label d-block">Activa</label>
                  <select class="form-select" name="active" id="v_active">
                    <option value="1">Sí</option>
                    <option value="0">No</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Imagen (opcional)</label>
                  <input class="form-control" type="file" name="v_image" accept="image/*">
                  <div class="form-text">JPG/PNG/WebP, máx 5MB</div>
                  <div class="mt-2" id="v_img_preview"></div>
                </div>
              </div>
              <div class="mt-3 d-flex justify-content-end gap-2">
                <button class="btn btn-secondary" type="button" id="btnVariantReset">Limpiar</button>
                <button class="btn btn-primary" type="submit" id="btnVariantSubmit">Añadir variante</button>
              </div>
            </form>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
// Datos server-side
const VARIANTS = <?= json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PRODUCTS = <?= json_encode(array_column($products, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

// Variantes (abrir modal)
let variantsModal = document.getElementById('modalVariants');
variantsModal.addEventListener('show.bs.modal', function (ev) {
  const btn = ev.relatedTarget;
  const pid = parseInt(btn.getAttribute('data-pid'), 10);
  const prod = PRODUCTS[pid] || {};
  document.getElementById('vprod_id').textContent = pid;
  document.getElementById('vprod_name').textContent = prod.name || '';

  const fv = document.getElementById('formVariant');
  fv.reset();
  document.getElementById('variant_product_id').value = pid;
  document.getElementById('variant_action').value = 'add_variant';
  document.getElementById('btnVariantSubmit').textContent = 'Añadir variante';
  document.getElementById('variant_id').value = '';
  document.getElementById('v_active').value = '1';

  const tbody = document.querySelector('#tblVariants tbody');
  tbody.innerHTML = '';
  (VARIANTS[pid] || []).forEach(v => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
  <td>${v.id}</td>
  <td>${(v.option1_name||'')}: <strong>${(v.option1_value||'')}</strong></td>
  <td>${(v.option2_name||'')}: <strong>${(v.option2_value||'')}</strong></td>
  <td>${v.sku||'—'}</td>
  <td class="text-end">${parseInt(v.stock||0,10)}</td>
  <td class="text-end">${v.price !== null ? '$'+Number(v.price).toLocaleString('es-CO') : '—'}</td>
  <td>${(parseInt(v.active,10) ? 'Sí' : 'No')}</td>
  <td class="text-end">
    ${v.image ? `<img src="<?= url('uploads/') ?>${v.image}" alt="" class="rounded me-2" style="height:34px;object-fit:cover" onerror="this.style.display='none'">` : ''}
    <button class="btn btn-sm btn-outline-primary me-1" type="button"
      onclick="editVariant(${pid}, ${v.id})">Editar</button>
    <form class="d-inline" method="post" action="<?= url('admin_inventory.php') ?>" onsubmit="return confirm('¿Eliminar variante?')">
      <?= str_replace("\n", "", csrf_field()) ?>
      <input type="hidden" name="action" value="delete_variant">
      <input type="hidden" name="product_id" value="${pid}">
      <input type="hidden" name="variant_id" value="${v.id}">
      <button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>
    </form>
  </td>
`;
    tbody.appendChild(tr);
  });
});

function editVariant(pid, vid) {
  const list = VARIANTS[pid] || [];
  const v = list.find(x => parseInt(x.id,10) === parseInt(vid,10));
  if (!v) return;
  document.getElementById('variant_action').value = 'update_variant';
  document.getElementById('btnVariantSubmit').textContent = 'Guardar cambios';
  document.getElementById('variant_id').value = v.id;
  document.getElementById('variant_product_id').value = pid;

  document.getElementById('v_o1n').value = v.option1_name || '';
  document.getElementById('v_o1v').value = v.option1_value || '';
  document.getElementById('v_o2n').value = v.option2_name || '';
  document.getElementById('v_o2v').value = v.option2_value || '';
  document.getElementById('v_sku').value = v.sku || '';
  document.getElementById('v_stock').value = parseInt(v.stock||0,10);
  document.getElementById('v_price').value = (v.price !== null && v.price !== undefined) ? v.price : '';
  document.getElementById('v_active').value = (parseInt(v.active,10) ? '1' : '0');

  const prev = document.getElementById('v_img_preview');
  if (prev) {
    prev.innerHTML = v.image
      ? `<img src="<?= url('uploads/') ?>${v.image}" class="rounded border" style="max-height:80px;object-fit:cover" onerror="this.style.display='none'">`
      : `<span class="text-muted small">Sin imagen</span>`;
  }
}

// Modal EDITAR PRODUCTO
let editModal = document.getElementById('modalEditProduct');
editModal.addEventListener('show.bs.modal', function (ev) {
  const b = ev.relatedTarget;
  document.getElementById('edit_id').value    = b.getAttribute('data-pid');
  document.getElementById('edit_name').value  = b.getAttribute('data-name') || '';
  document.getElementById('edit_desc').value  = b.getAttribute('data-desc') || '';
  document.getElementById('edit_price').value = b.getAttribute('data-price') || '0';
  document.getElementById('edit_cat').value   = b.getAttribute('data-cat') || '';

  // Vista previa si hay data-image
  const img = b.getAttribute('data-image') || '';
  const box = document.getElementById('edit_img_preview');
  if (box) {
    box.innerHTML = img
      ? `<img src="<?= url('uploads/') ?>${img}" alt="" class="rounded border" style="max-height:80px"
             onerror="this.src='<?= url('uploads/placeholder.png') ?>'">`
      : `<span class="text-muted small">Sin imagen actual</span>`;
  }
});

// Modal ELIMINAR PRODUCTO
let delModal = document.getElementById('modalDeleteProduct');
delModal.addEventListener('show.bs.modal', function (ev) {
  const b = ev.relatedTarget;
  document.getElementById('del_id').value = b.getAttribute('data-pid');
  document.getElementById('del_name').textContent = b.getAttribute('data-name') || '';
});
</script>
<?php
$content = ob_get_clean();
render('Inventario', __DIR__ . '/../views/pages/_blank.php', [
  'content' => $content,
  'cats'    => $cats,
]);
