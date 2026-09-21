<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
  foreach ($_SESSION['cart'] as $ci) $cartCount += (int)($ci['qty'] ?? 0);
}
$u = function_exists('auth_user') ? auth_user() : null; // ← necesitamos el rol
$urole = strtolower($u['role'] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= isset($title)?htmlspecialchars($title, ENT_QUOTES, 'UTF-8'):'Tienda Stand 2025' ?></title>
  <!-- Mismo glifo que la tarjeta del panel (bi-shop) en alto contraste. SVG:
       escala a cualquier densidad y pesa ~1 KB. -->
  <link rel="icon" type="image/svg+xml" href="<?= url('favicon.svg') ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .brand-logo {
    max-height: 32px;   /* ajusta a tu gusto: 28–40px */
    width: auto;
    object-fit: contain;
  }
  @media (max-width: 576px){
    .brand-logo { max-height: 28px; }
  }
</style>
  <?php
  // helpers.php ya fue cargado ANTES de renderizar la vista (por index/product)
  // así que csrf_token() está disponible y el cookie ya existe.
  ?>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">

  <script>
  (function () {
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    window.csrfToken = CSRF;

    // Convierte objeto plano a FormData
    function toFormData(obj) {
      if (!obj || typeof obj !== 'object' || obj instanceof FormData) return obj;
      const fd = new FormData();
      for (const [k, v] of Object.entries(obj)) fd.append(k, v);
      return fd;
    }

    window.csrfFetch = function (input, init) {
      init = init || {};
      const method = (init.method || 'GET').toUpperCase();

      // Asegura cabeceras útiles para detectar AJAX
      init.headers = Object.assign({}, init.headers, {
        'X-Requested-With': 'fetch',
        'Accept': 'application/json'
      });

      // Si es método con cuerpo y body es un objeto, pásalo a FormData
      if (['POST','PUT','PATCH','DELETE'].includes(method)) {
        let body = init.body;
        body = toFormData(body);

        // Inyecta CSRF en el body si es FormData
        if (body instanceof FormData && CSRF && !body.has('csrf')) {
          body.set('csrf', CSRF);
        }

        init.body = body;
      }

      return fetch(input, init);
    };
  })();
</script>

</head>
<body class="bg-light">
