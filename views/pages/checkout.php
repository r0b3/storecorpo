<?php // views/pages/checkout.php ?>
<h1 class="h3 mb-3">Checkout</h1>

<?php if (!empty($form_error)): ?>
  <div class="alert alert-danger"><?= e($form_error) ?></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title mb-3">Datos del cliente</h5>

        <form method="post" action="<?= url('checkout.php') ?>" id="checkoutForm" class="row g-3">
          <?= csrf_field() ?>

          <!-- Tipo de cliente -->
          <div class="col-12">
            <label class="form-label d-block">Tipo de cliente</label>
            <div class="form-check form-check-inline">
              <input class="form-check-input" id="ct_natural" type="radio" name="customer_type" value="natural" <?= (!isset($customer_type) || $customer_type==='natural') ? 'checked' : '' ?>>
              <label class="form-check-label" for="ct_natural">Natural</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" id="ct_empresa" type="radio" name="customer_type" value="empresa" <?= (isset($customer_type) && $customer_type==='empresa') ? 'checked' : '' ?>>
              <label class="form-check-label" for="ct_empresa">Empresa</label>
            </div>
          </div>

          <!-- Campos Natural -->
          <div class="col-12 group-natural">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Nombre</label>
                <input class="form-control" name="person_name" value="<?= e($_POST['person_name'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Cédula</label>
                <input class="form-control" name="person_id" value="<?= e($_POST['person_id'] ?? '') ?>">
              </div>
            </div>
          </div>

          <!-- Campos Empresa -->
          <div class="col-12 group-empresa" style="display:none">
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Nombre de empresa</label>
                <input class="form-control" name="company_name" value="<?= e($_POST['company_name'] ?? '') ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">NIT</label>
                <input class="form-control" name="company_nit" value="<?= e($_POST['company_nit'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Correo</label>
            <input class="form-control" type="email" name="customer_email" value="<?= e($_POST['customer_email'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Teléfono</label>
            <input class="form-control" name="customer_phone" value="<?= e($_POST['customer_phone'] ?? '') ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Dirección</label>
            <input class="form-control" name="shipping_address" value="<?= e($_POST['shipping_address'] ?? '') ?>" required>
          </div>

          <!-- Método de pago -->
          <div class="mb-3">
            <label class="form-label">Método de pago</label>
            <div class="d-flex gap-3 flex-wrap">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_method" id="pm_cash" value="efectivo" required>
                <label class="form-check-label" for="pm_cash">Efectivo</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_method" id="pm_transfer" value="transferencia" required>
                <label class="form-check-label" for="pm_transfer">Transferencia</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_method" id="pm_due" value="por_pagar" required>
                <label class="form-check-label" for="pm_due">Por pagar</label>
              </div>
            </div>
          </div>

          <!-- Referencia: venta a beneficio de artesanas -->
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="chk_artesanas" name="for_artesanas">
            <label class="form-check-label" for="chk_artesanas">
              Artesanas <span class="text-muted">(esta compra beneficia a las artesanas)</span>
            </label>
          </div>


          <div class="col-12 group-transfer" style="display:none">
            <label class="form-label">Referencia de pago (transferencia)</label>
            <input class="form-control" name="payment_ref" value="<?= e($_POST['payment_ref'] ?? '') ?>">
          </div>

          <div class="col-12 d-flex gap-2">
            <a class="btn btn-secondary" href="<?= url('cart.php') ?>">Volver al carrito</a>
            <button class="btn btn-success ms-auto" type="submit">Finalizar pedido</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Resumen</h5>
          <ul class="list-group mb-3">
            <?php foreach ($_SESSION['cart'] as $it): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>
                  <?= e($it['name']) ?>
                  <?php if (!empty($it['variant'])): ?>
                    <small class="text-muted">(<?= e($it['variant']) ?>)</small>
                  <?php endif; ?>
                </span>
                <span class="text-nowrap"><?= (int)$it['qty'] ?> × $<?= money($it['price']) ?></span>
              </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between">
              <strong>Subtotal</strong><strong>$<?= money($subtotal) ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between fs-5">
              <strong>Total</strong><strong>$<?= money($total) ?></strong>
            </li>
          </ul>
          </li> 
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const ctRadios   = document.querySelectorAll('input[name="customer_type"]');
  const gNatural   = document.querySelector('.group-natural');
  const gEmpresa   = document.querySelector('.group-empresa');
  const pmRadios   = document.querySelectorAll('input[name="payment_method"]');
  const gTransfer  = document.querySelector('.group-transfer');

  function refresh() {
    const ct = document.querySelector('input[name="customer_type"]:checked')?.value || 'natural';
    gNatural.style.display = ct === 'natural' ? '' : 'none';
    gEmpresa.style.display = ct === 'empresa' ? '' : 'none';

    const pm = document.querySelector('input[name="payment_method"]:checked')?.value || 'efectivo';
    gTransfer.style.display = pm === 'transferencia' ? '' : 'none';
  }

  ctRadios.forEach(r => r.addEventListener('change', refresh));
  pmRadios.forEach(r => r.addEventListener('change', refresh));
  refresh();
})();
</script>
