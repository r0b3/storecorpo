<?php
// views/pages/cart.php — carrito en curso + carritos reservados (en espera).
// (Se quitó el display_errors=1 que tenía este archivo: mostraba errores
// internos al vendedor; los errores van al log.)

/** "hace 5 min", "hace 2 h", "hace 3 días" */
function hace_cuanto(int $min): string {
  if ($min < 1)    return 'hace un momento';
  if ($min < 60)   return "hace {$min} min";
  if ($min < 1440) return 'hace ' . intdiv($min, 60) . ' h';
  $d = intdiv($min, 1440);
  return "hace {$d} día" . ($d === 1 ? '' : 's');
}
$reservas   = $reservas ?? [];
$reservasOn = $reservasOn ?? false;
$hayCarrito = !empty($_SESSION['cart']);
?>
<h1 class="h3 mb-3">Carrito</h1>

<?php if (!empty($flash_msg)): ?>
  <div class="alert <?= !empty($flash_ok) ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show" role="alert">
    <?= e($flash_msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
  </div>
<?php endif; ?>

<?php if (!$hayCarrito): ?>
  <div class="alert alert-info">El carrito está vacío.</div>
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
                  src="<?= e(url('uploads/' . $img)) ?>"
                  width="48" height="48" class="rounded" alt=""
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
                     name="qty[<?= e($key) ?>]" value="<?= $qty ?>" aria-label="Cantidad">
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

<form class="mt-3 d-flex flex-wrap gap-2" method="post" action="<?= url('cart.php') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="clear">
  <a class="btn btn-secondary" href="<?= url('index.php') ?>">Seguir comprando</a>
  <button class="btn btn-outline-danger" type="submit">Vaciar carrito</button>
  <a class="btn btn-success ms-auto" href="<?= url('checkout.php') ?>">Continuar a pago</a>
</form>

<?php if ($reservasOn): ?>
  <!-- Reservar: el cliente no se decide o hay que esperar. El stock queda
       apartado y el carrito se vacía para atender al siguiente. -->
  <div class="card mt-3">
    <div class="card-body">
      <form method="post" action="<?= url('cart.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reserve">
        <label class="form-label fw-semibold mb-1" for="reserva_nombre">Reservar este carrito</label>
        <div class="form-text mt-0 mb-2">
          Para cuando el cliente no se decide o hay que esperar: el stock queda apartado y puedes atender a otro cliente.
        </div>
        <div class="d-flex flex-wrap gap-2">
          <input class="form-control" id="reserva_nombre" name="reserva_nombre" maxlength="120"
                 style="max-width:360px" placeholder="Nombre del cliente o una seña (ej. señora de gorra roja)">
          <button class="btn btn-warning" type="submit">Reservar y atender a otro</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php if ($reservas): ?>
  <h2 class="h5 mt-4 mb-2">Carritos en espera <span class="badge text-bg-warning"><?= count($reservas) ?></span></h2>
  <p class="small text-muted mb-2">
    Tienen el stock apartado. Al <strong>retomar</strong> uno, sus productos vuelven al carrito
    <?= $hayCarrito ? '(y el que tienes ahora queda reservado)' : '' ?>; al <strong>liberar</strong>, el stock vuelve al inventario.
  </p>
  <div class="row g-3">
    <?php foreach ($reservas as $r):
      $min = (int)($r['minutos'] ?? 0);
      $viejo = $min >= 1440;
    ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card h-100 <?= $viejo ? 'border-warning' : '' ?>">
          <div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="fw-semibold"><?= e($r['label']) ?></div>
              <div class="fw-semibold text-nowrap">$<?= money($r['total']) ?></div>
            </div>
            <div class="small text-muted mb-2">
              <?= (int)$r['units'] ?> unidad<?= (int)$r['units'] === 1 ? '' : 'es' ?>
              · <?= e(hace_cuanto($min)) ?>
              <?= !empty($r['quien']) ? ' · por ' . e($r['quien']) : '' ?>
            </div>
            <?php if ($viejo): ?>
              <div class="small text-warning-emphasis mb-2">Lleva más de un día apartando stock.</div>
            <?php endif; ?>
            <ul class="small ps-3 mb-3">
              <?php foreach ($r['lineas'] as $l): ?>
                <li>
                  <?= (int)$l['quantity'] ?> × <?= e($l['name']) ?>
                  <?php if (($l['variant_label'] ?? '') !== ''): ?>
                    <span class="text-muted">(<?= e($l['variant_label']) ?>)</span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="mt-auto d-flex gap-2">
              <form method="post" action="<?= url('cart.php') ?>" class="flex-grow-1"
                    <?= $hayCarrito ? "onsubmit=\"return confirm('El carrito que tienes ahora quedará reservado y se retomará este. ¿Continuar?');\"" : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resume">
                <input type="hidden" name="cart_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-primary btn-sm w-100" type="submit">Retomar</button>
              </form>
              <form method="post" action="<?= url('cart.php') ?>"
                    onsubmit="return confirm('¿Liberar esta reserva? El stock vuelve al inventario y el carrito se descarta.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="discard">
                <input type="hidden" name="cart_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit">Liberar</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
