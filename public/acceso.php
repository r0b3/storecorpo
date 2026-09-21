<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../views/main.php';
ensure_csrf();

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  if ($email && $pass) {
    if (auth_login($email, $pass)) {
      redirect(safe_next($_GET['next'] ?? null));
    } else {
      $err = "Credenciales inválidas";
    }
  } else {
    $err = "Completa los campos";
  }
}

ob_start(); ?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header">Iniciar sesión</div>
      <div class="card-body">
        <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
        <form method="post" action="<?= url('acceso.php') ?>">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input class="form-control" type="password" name="password" required>
          </div>
          <button class="btn btn-primary" type="submit">Ingresar</button>
          <a class="btn btn-link" href="<?= url('index.php') ?>">Volver</a>
        </form>
      </div>
    </div>
  </div>
</div>
<?php $content = ob_get_clean(); render('Iniciar sesión', __DIR__ . '/../views/pages/_blank.php', ['content'=>$content, 'cats'=>[]]); ?>
