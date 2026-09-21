<?php
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/auth.php';

$u = auth_user();                                   // <- usa SIEMPRE $u
$urole = strtolower(trim($u['role'] ?? ''));

// Roles
$esAdmin   = in_array($urole, ['admin','administrador'], true);
$esBilling = in_array($urole, ['billing','facturador','facturación','facturacion'], true);
$esSeller  = in_array($urole, ['seller','vendedor','staff'], true);

// Normaliza categorías (si la vista no las pasó)
$cats = isset($cats) && is_array($cats) ? $cats : [];

// Conteo robusto del carrito
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
  foreach ($_SESSION['cart'] as $ci) { $cartCount += (int)($ci['qty'] ?? 0); }
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container">

    <!-- Logo + nombre -->
<a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="<?= url('index.php') ?>">
  <img
    src="<?= url('uploads/logo_nav.png') ?>"
    alt="Logo"
    class="brand-logo d-inline-block align-text-top"
    onerror="this.style.display='none'">
  <span>Tienda</span>
</a>


    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="topNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($u && ($esAdmin || $esBilling || $esSeller)): ?>
          <li class="nav-item">
            <a class="nav-link <?= (($_GET['cat'] ?? '') === '') ? 'active' : '' ?>" href="<?= url('index.php') ?>">Inicio</a>
          </li>

          <!-- Categorías -->
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Categorías</a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="<?= url('index.php') ?>">Todas</a></li>
              <?php if ($cats): foreach ($cats as $c): ?>
                <li>
                  <a class="dropdown-item" href="<?= url('index.php') . '?cat=' . urlencode((string)$c['slug']) ?>">
                    <?= e($c['name']) ?>
                  </a>
                </li>
              <?php endforeach; endif; ?>
            </ul>
          </li>

          <li class="nav-item"><a class="nav-link" href="<?= url('cart.php') ?>">Carrito</a></li>
        <?php endif; ?>

        <?php if ($u && ($esAdmin || $esBilling)): ?>
          <li class="nav-item"><a class="nav-link" href="<?= url('admin_sales.php') ?>">Ventas</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= url('admin_sales_report.php') ?>">Consolidado</a></li>
        <?php endif; ?>
      </ul>

      <!-- Buscador -->
      <form class="d-flex" role="search" method="get" action="<?= url('index.php') ?>">
        <input class="form-control me-2" type="search" placeholder="Buscar" name="q" value="<?= isset($_GET['q']) ? e($_GET['q']) : '' ?>">
        <button class="btn btn-outline-light" type="submit">Buscar</button>
      </form>

      <!-- Menú Admin (si aplica) -->
      <?php if ($u && $esAdmin): ?>
        <ul class="navbar-nav ms-3">
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Admin</a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= url('admin_inventory.php') ?>">Inventario</a></li>
            </ul>
          </li>
        </ul>
      <?php endif; ?>

      <!-- Usuario (SSO) + volver al panel + salir central + carrito -->
      <div class="ms-3 d-flex align-items-center gap-2">
        <?php if ($u): ?>
          <span class="text-white-50 small d-none d-md-inline">Hola, <?= e($u['username'] ?? 'Usuario') ?></span>
          <a class="btn btn-outline-light btn-sm" href="<?= e(sso_panel_url()) ?>/index.php">Panel</a>
          <a class="btn btn-outline-light btn-sm" href="<?= e(sso_logout_url()) ?>">Salir</a>
        <?php endif; ?>

        <a class="btn btn-outline-light position-relative" href="<?= url('cart.php') ?>" aria-label="Ver carrito">
          <span class="me-1">Carrito</span>
          <span id="cartBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
            <?= (int)$cartCount ?>
          </span>
        </a>
      </div>

    </div>
  </div>
</nav>
