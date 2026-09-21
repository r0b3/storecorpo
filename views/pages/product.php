<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);
 // views/pages/product.php ?>
<div class="row">
  <div class="col-md-6">
    <img class="img-fluid rounded shadow-sm" src="<?= url('uploads/' . ($p['image'] ?: 'placeholder.png')) ?>" alt="<?= e($p['name']) ?>" onerror="this.src='<?= url('uploads/placeholder.png') ?>'">
  </div>
  <div class="col-md-6">
    <h1 class="h3"><?= e($p['name']) ?></h1>
    <p class="text-muted"><?= e($p['category'] ?? 'Sin categoría') ?></p>
    <p><?= nl2br(e($p['description'])) ?></p>
    <p class="h4">Desde $<?= money($p['base_price']) ?></p>

    <form method="post" action="<?= url('cart.php') ?>" class="mt-3" id="variantForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <input type="hidden" name="variant_id" id="variant_id">

      <?php if (!empty($opt1_name)): ?>
        <div class="mb-3">
          <label class="form-label"><?= e($opt1_name) ?></label>
          <select class="form-select" id="opt1">
            <option value="">Selecciona</option>
            <?php foreach ($opt1_values as $v): ?>
              <option value="<?= e($v) ?>"><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <?php if (!empty($opt2_name)): ?>
        <div class="mb-3">
          <label class="form-label"><?= e($opt2_name) ?></label>
          <select class="form-select" id="opt2">
            <option value="">Selecciona</option>
            <?php foreach ($opt2_values as $v): ?>
              <option value="<?= e($v) ?>"><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="mb-2">
        <span id="priceLabel" class="h5"></span>
        <span id="stockLabel" class="badge bg-secondary ms-2"></span>
        <div id="skuLabel" class="text-muted small mt-1"></div>
      </div>

      <div class="input-group" style="max-width:280px;">
        <input type="number" min="1" name="qty" value="1" class="form-control">
        <button class="btn btn-primary" type="submit" id="addBtn" disabled>Agregar</button>
      </div>
      <div class="text-danger small mt-2" id="noStockMsg" style="display:none;">Sin stock</div>
    </form>
  </div>
</div>

<script>
const VARIANTS = <?= json_encode($variants ?? []) ?>;
const BASE_PRICE = <?= json_encode((float)$p['base_price']) ?>;
const opt1 = document.getElementById('opt1');
const opt2 = document.getElementById('opt2');
const priceLabel = document.getElementById('priceLabel');
const stockLabel = document.getElementById('stockLabel');
const skuLabel = document.getElementById('skuLabel');
const addBtn = document.getElementById('addBtn');
const variantId = document.getElementById('variant_id');
const noStockMsg = document.getElementById('noStockMsg');

function matchVariant() {
  let o1 = opt1 ? opt1.value : null;
  let o2 = opt2 ? opt2.value : null;
  let found = null;
  for (const v of VARIANTS) {
    const v1ok = (v.option1_name === null) || (o1 && v.option1_value === o1);
    const v2ok = (v.option2_name === null) || (o2 && v.option2_value === o2);
    if (v1ok && v2ok) { found = v; break; }
  }
  if (found) {
    const price = (found.price !== null) ? parseFloat(found.price) : BASE_PRICE;
    priceLabel.textContent = '$' + new Intl.NumberFormat('es-CO').format(price);
    stockLabel.textContent = 'Stock: ' + (found.stock ?? 0);
    stockLabel.className = 'badge ' + ((found.stock ?? 0) > 0 ? 'bg-success':'bg-secondary') + ' ms-2';
    skuLabel.textContent = found.sku ? ('SKU: ' + found.sku) : '';
    variantId.value = found.id;
    addBtn.disabled = !(found.stock > 0);
    noStockMsg.style.display = (found.stock > 0) ? 'none' : 'block';
  } else {
    priceLabel.textContent = 'Selecciona opciones';
    stockLabel.textContent = '';
    skuLabel.textContent = '';
    addBtn.disabled = true;
    variantId.value = '';
    noStockMsg.style.display = 'none';
  }
}
if (opt1) opt1.addEventListener('change', matchVariant);
if (opt2) opt2.addEventListener('change', matchVariant);
matchVariant();
</script>
