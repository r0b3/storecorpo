<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);
 // views/pages/home.php ?>
<div class="row">
  <div class="col-lg-3 mb-4">
    <div class="list-group">
      <a href="<?= url('index.php') ?>" class="list-group-item list-group-item-action <?= ($cat??'')===''?'active':'' ?>">Todas</a>
      <?php foreach (($cats??[]) as $c): ?>
        <a href="<?= url('index.php') . '?cat=' . e($c['slug']) ?>" class="list-group-item list-group-item-action <?= ($cat??'')===$c['slug']?'active':'' ?>">
          <?= e($c['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-lg-9">
    <div class="row g-3">
      <?php foreach (($products??[]) as $p): ?>
        <div class="col-sm-6 col-md-4">
          <div class="card h-100 shadow-sm">
            <img src="<?= e(url('uploads/' . ($p['image'] ?: 'placeholder.png'))) ?>" class="card-img-top" alt="<?= e($p['name']) ?>" onerror="this.src='<?= url('uploads/placeholder.png') ?>'">
            <div class="card-body d-flex flex-column">
              <h5 class="card-title"><?= e($p['name']) ?></h5>
              <p class="card-text small text-muted mb-1"><?= e($p['category'] ?? 'Sin categoría') ?></p>
              <div class="mt-auto">
                <p class="h6 mb-1">$<?= money($p['base_price']) ?>+</p>
                <span class="badge bg-<?= ($p['total_stock']??0)>0?'success':'secondary' ?>">Stock: <?= (int)($p['total_stock']??0) ?></span>
              </div>
              <div class="mt-2 d-grid gap-2">
                <a class="btn btn-outline-primary" href="<?= url('product.php') . '?slug=' . e($p['slug']) ?>">Ver</a>
                <form method="post" action="<?= url('cart.php') ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-primary" type="submit" <?= ($p['total_stock']??0)<=0?'disabled':'' ?>>
                    Agregar al carrito
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($products)): ?>
        <div class="col-12"><div class="alert alert-info">No se encontraron productos.</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>
