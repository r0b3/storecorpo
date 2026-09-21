<?php
// public/login.php
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); ini_set('log_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../views/main.php';

// Si ya está logeado, manda a inicio (o next)
if (auth_user()) {
  redirect(safe_next($_GET['next'] ?? null));
}

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  // Si tu hosting dio guerra con CSRF, cambia por: ensure_csrf_exempt(['login.php']);
  ensure_csrf();

  $user = trim($_POST['user'] ?? '');
  $pass = (string)($_POST['pass'] ?? '');
  $next = safe_next($_POST['next'] ?? null);

  if ($user === '' || $pass === '') {
    $err = 'Usuario/Email y contraseña son obligatorios.';
  } else {
    if (auth_login($user, $pass)) {
      redirect($next);
    } else {
      $err = 'Credenciales inválidas.';
    }
  }
}

ob_start(); ?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h4 class="mb-3">Iniciar sesión</h4>
        <?php if ($err): ?>
          <div class="alert alert-danger"><?= e($err) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= url('login.php') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="<?= e(safe_next($_GET['next'] ?? null)) ?>">
          <div class="mb-3">
            <label class="form-label">Usuario o Email</label>
            <input class="form-control" name="user" autocomplete="username" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input class="form-control" type="password" name="pass" autocomplete="current-password" required>
          </div>
          <div class="d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="<?= url('index.php') ?>">Volver</a>
            <button class="btn btn-primary" type="submit">Entrar</button>
          </div>
        </form>
        <p class="small text-muted mt-3 mb-0">
          * Acceso privado para personal autorizado.
        </p>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
render('Acceso', __DIR__ . '/../views/pages/_blank.php', ['content'=>$content]);
