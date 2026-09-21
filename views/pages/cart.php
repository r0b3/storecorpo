<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);
 // views/pages/cart.php ?>
<h1 class="h3 mb-3">Carrito</h1>

<?php if (!empty($flash_msg)): ?>
  <div class="alert <?= !empty($flash_ok) ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show" role="alert">
    <?= e($flash_msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
  </div>
<?php endif; ?>

<?php if (empty($_SESSION['cart'])): ?>
  <div class="alert alert-info">Tu carrito está vacío.</div>
  <a class="btn btn-secondary" href="<?= url('index.php') ?>">Seguir comprando</a>
<?php else: ?>

<form method="post" action="<?= url('cart.php') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update">
  <div class="table-responsive">
    <table class="table align-middle">
      <thead>
        <tr>
          <th>Producto</th>
          <th style="width:120px">Cantidad</th>
          <th>Precio</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($_SESSION['cart'] as $key => $it): ?>
          <?php
            $img = $it['image'] ?? 'placeholder.png';
            $variantLabel = $it['variant'] ?? '';
            $qty = (int)($it['qty'] ?? 0);
            $price = (float)($it['price'] ?? 0);
          ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <img
                  src="<?= url('uploads/' . $img) ?>"
                  width="48" height="48" class="rounded"
                  onerror="this.src='<?= url('uploads/placeholder.png') ?>'">
                <div>
                  <?= e($it['name'] ?? 'Producto') ?>
                  <?php if ($variantLabel !== ''): ?>
                    <span class="text-muted">(<?= e($variantLabel) ?>)</span>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <input class="form-control" type="number" min="0"
                     name="qty[<?= e($key) ?>]" value="<?= $qty ?>">
            </td>
            <td>$<?= money($price) ?></td>
            <td>$<?= money($price * $qty) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
  <tr><th colspan="3" class="text-end">Subtotal</th><th>$<?= money($subtotal) ?></th></tr>
  <tr><th colspan="3" class="text-end">Total</th><th>$<?= money($total) ?></th></tr>
</tfoot>

    </table>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" type="submit">Actualizar cantidades</button>
  </div>
</form>

<form class="mt-3 d-flex gap-2" method="post" action="<?= url('cart.php') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="clear">
  <a class="btn btn-secondary" href="<?= url('index.php') ?>">Seguir comprando</a>
  <button class="btn btn-outline-danger" type="submit">Vaciar carrito</button>
<a class="btn btn-success ms-auto" href="<?= url('checkout.php') ?>">Continuar a pago</a>
</form>
<?php endif; ?>
