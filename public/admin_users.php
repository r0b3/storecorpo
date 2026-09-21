<?php
// public/admin_users.php — Gestión de usuarios (solo Admin)
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); ini_set('log_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_login();
require_roles(['Admin']);
require_once __DIR__ . '/../views/main.php';
require __DIR__ . '/_gate_private.php'; // ← añade esta línea

$pdo = get_pdo();
$me  = auth_user(); // id, role, etc.

/* ===== Helpers ===== */
if (!function_exists('redirect')) {
  function redirect(string $to, int $status = 303): void {
    if (strpos($to,'http')!==0 && strpos($to,'https')!==0) { if (function_exists('url')) $to = url($to); }
    if (!headers_sent()) { header('Location: '.$to, true, $status); exit; }
    $toEsc = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><meta http-equiv='refresh' content='0;url={$toEsc}'>";
    echo "<p>Redirigiendo a <a href='{$toEsc}'>{$toEsc}</a>…</p>"; exit;
  }
}
function has_column(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :c");
  $st->execute([':c'=>$column]); return (bool)$st->fetch();
}
function flash_redirect(string $to, bool $ok, string $msg): void {
  $q = http_build_query(['ok'=>$ok?'1':'0','msg'=>$msg]);
  redirect($to.(str_contains($to,'?')?'&':'?').$q);
}
function count_active_admins(PDO $pdo): int {
  // si no hay columna active, considera Admins como activos por defecto
  $hasActive = has_column($pdo,'users','active');
  $sql = "SELECT COUNT(*) FROM users WHERE role='Admin'".($hasActive?" AND active=1":"");
  return (int)$pdo->query($sql)->fetchColumn();
}

/* ===== POST (acciones) ===== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  ensure_csrf();
  try {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
      $username  = trim($_POST['username'] ?? '');
      $email     = trim($_POST['email'] ?? '');
      $full_name = trim($_POST['full_name'] ?? '');
      $pass      = (string)($_POST['password'] ?? '');
      $roleIn    = strtolower(trim($_POST['role'] ?? 'seller'));
      $role      = $roleIn === 'admin' ? 'Admin' : ($roleIn === 'billing' ? 'Billing' : 'Seller');
      $active    = (int)($_POST['active'] ?? 1);

      if ($username === '' || $email === '' || $pass === '') throw new RuntimeException('Usuario, email y contraseña son obligatorios.');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email inválido.');

      $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=:u OR email=:e");
      $st->execute([':u'=>$username, ':e'=>$email]);
      if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Ya existe un usuario con ese nombre o email.');

      $hasActive  = has_column($pdo, 'users', 'active');
      $hasCreated = has_column($pdo, 'users', 'created_at');

      $sql = "INSERT INTO users (username, email, full_name, password_hash, role"
           . ($hasActive  ? ", active"     : "")
           . ($hasCreated ? ", created_at" : "")
           . ") VALUES (:u,:e,:f,:ph,:r"
           . ($hasActive  ? ", :a"         : "")
           . ($hasCreated ? ", NOW()"      : "")
           . ")";
      $pdo->prepare($sql)->execute([
        ':u'=>$username,
        ':e'=>$email,
        ':f'=>$full_name !== '' ? $full_name : null,
        ':ph'=>password_hash($pass, PASSWORD_DEFAULT),
        ':r'=>$role,
        ...( $hasActive ? [':a'=>$active?1:0] : [] ),
      ]);

      flash_redirect('admin_users.php', true, 'Usuario creado correctamente.');
    }

    if ($action === 'update') {
      $id        = (int)($_POST['id'] ?? 0);
      $username  = trim($_POST['username'] ?? '');
      $email     = trim($_POST['email'] ?? '');
      $full_name = trim($_POST['full_name'] ?? '');
      $roleIn    = strtolower(trim($_POST['role'] ?? 'seller'));
      $role      = $roleIn === 'admin' ? 'Admin' : ($roleIn === 'billing' ? 'Billing' : 'Seller');
      $activeIn  = $_POST['active'] ?? null; // puede venir o no
      $password  = (string)($_POST['password'] ?? ''); // opcional

      if ($id <= 0) throw new RuntimeException('ID inválido.');
      if ($username === '' || $email === '') throw new RuntimeException('Usuario y email son obligatorios.');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email inválido.');

      // Unicidad (excluyendo al propio id)
      $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username=:u OR email=:e) AND id<>:id");
      $st->execute([':u'=>$username, ':e'=>$email, ':id'=>$id]);
      if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Otro usuario ya usa ese nombre o email.');

      // Protecciones: no permitir que el usuario se quite su propio rol Admin si es el último Admin activo
      $isSelf = ($me && (int)$me['id'] === $id);
      $hasActive = has_column($pdo,'users','active');

      // Detecta si el usuario objetivo es Admin y activo actualmente
      $st = $pdo->prepare("SELECT role".($hasActive?", active":"")." FROM users WHERE id=:id");
      $st->execute([':id'=>$id]);
      $cur = $st->fetch(PDO::FETCH_ASSOC);
      if (!$cur) throw new RuntimeException('Usuario no encontrado.');

      $curRole = (string)($cur['role'] ?? 'Seller');
      $curAct  = $hasActive ? (int)$cur['active'] : 1;

      // Si intentan desactivar o bajar de rol al último Admin activo
      $goingInactive = $hasActive && ($activeIn !== null) ? (int)$activeIn === 0 : false;
      $goingNonAdmin = ($curRole === 'Admin' && $role !== 'Admin');

      if (($goingInactive || $goingNonAdmin) && $curRole === 'Admin' && $curAct === 1) {
        $admins = count_active_admins($pdo);
        if ($admins <= 1) throw new RuntimeException('No puedes dejar el sistema sin al menos un Admin activo.');
        // Adicional: si es su propia cuenta y se quita Admin, lo permitimos solo si hay otros admins activos
        // (que ya validamos).
      }

      // Construye UPDATE dinámico
      $set = "username=:u, email=:e, full_name=:f, role=:r";
      $args = [':u'=>$username, ':e'=>$email, ':f'=>$full_name !== '' ? $full_name : null, ':r'=>$role, ':id'=>$id];

      if ($password !== '') {
        $set .= ", password_hash=:ph";
        $args[':ph'] = password_hash($password, PASSWORD_DEFAULT);
      }
      if ($hasActive && $activeIn !== null) {
        $set .= ", active=:a";
        $args[':a'] = (int)$activeIn ? 1 : 0;
      }

      $pdo->prepare("UPDATE users SET {$set} WHERE id=:id")->execute($args);

      // Si el admin se bajó a sí mismo a no-admin, podría perder acceso a la página actual.
      if ($isSelf && $role !== 'Admin') {
        flash_redirect('index.php', true, 'Tu rol cambió; redirigido a inicio.');
      } else {
        flash_redirect('admin_users.php', true, 'Usuario actualizado.');
      }
    }

    if ($action === 'toggle_active') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID inválido.');

      $hasActive = has_column($pdo, 'users', 'active');
      if (!$hasActive) throw new RuntimeException('Esta base no soporta campo "active".');

      $st = $pdo->prepare("SELECT role, active FROM users WHERE id=:id");
      $st->execute([':id'=>$id]);
      $u = $st->fetch(PDO::FETCH_ASSOC);
      if (!$u) throw new RuntimeException('Usuario no encontrado.');

      $new = (int)$u['active'] ? 0 : 1;

      // Protecciones:
      if ((int)$me['id'] === $id && $new === 0) {
        throw new RuntimeException('No puedes desactivarte a ti mismo.');
      }
      if ($u['role'] === 'Admin' && $new === 0) {
        $admins = count_active_admins($pdo);
        if ($admins <= 1) throw new RuntimeException('No puedes desactivar al último Admin activo.');
      }

      $pdo->prepare("UPDATE users SET active=:a WHERE id=:id")->execute([':a'=>$new, ':id'=>$id]);
      flash_redirect('admin_users.php', true, 'Estado actualizado.');
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID inválido.');
      if ((int)$me['id'] === $id) throw new RuntimeException('No puedes eliminar tu propia cuenta.');

      $hasActive = has_column($pdo,'users','active');
      $st = $pdo->prepare("SELECT role".($hasActive?", active":"")." FROM users WHERE id=:id");
      $st->execute([':id'=>$id]);
      $u = $st->fetch(PDO::FETCH_ASSOC);
      if (!$u) throw new RuntimeException('Usuario no encontrado.');

      if ($u['role'] === 'Admin') {
        $admins = count_active_admins($pdo);
        // Aunque estuviera inactivo, si es el único admin (por esquema sin active), protegemos
        if ($admins <= 1) throw new RuntimeException('No puedes eliminar al último Admin activo.');
      }

      $pdo->prepare("DELETE FROM users WHERE id=:id")->execute([':id'=>$id]);
      flash_redirect('admin_users.php', true, 'Usuario eliminado.');
    }

    // Acción no reconocida
    throw new RuntimeException('Acción no reconocida.');

  } catch (Throwable $e) {
    error_log('[admin_users POST] '.$e->getMessage());
    flash_redirect('admin_users.php', false, $e->getMessage());
  }
}

/* ===== GET (listado) ===== */
$cols = ['id','username','email','full_name','role'];
$hasActive  = has_column($pdo,'users','active');
$hasCreated = has_column($pdo,'users','created_at');
if ($hasActive)  $cols[] = 'active';
if ($hasCreated) $cols[] = 'created_at';
$colList = implode(', ', $cols);

$order = $hasCreated ? "ORDER BY created_at DESC, id DESC" : "ORDER BY id DESC";
$users = $pdo->query("SELECT {$colList} FROM users {$order}")->fetchAll(PDO::FETCH_ASSOC);

/* ===== UI ===== */
ob_start();

$ok  = ($_GET['ok'] ?? '') === '1';
$msg = trim($_GET['msg'] ?? '');
if ($msg !== '') {
  echo '<div class="alert '.($ok?'alert-success':'alert-danger').' alert-dismissible fade show" role="alert">'
     . e($msg)
     . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
     . '</div>';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Usuarios</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreate">Nuevo usuario</button>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Usuario</th>
            <th>Email</th>
            <th>Nombre</th>
            <th>Rol</th>
            <?php if ($hasActive)  echo '<th>Activo</th>'; ?>
            <?php if ($hasCreated) echo '<th>Creado</th>'; ?>
            <th class="text-end" style="width:240px">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="8" class="text-muted">Sin usuarios aún.</td></tr>
        <?php else: foreach ($users as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= e($u['username']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= e($u['full_name'] ?? '') ?></td>
            <td>
              <span class="badge <?= strtolower($u['role'])==='admin'?'bg-danger':(strtolower($u['role'])==='billing'?'bg-info':'bg-secondary') ?>">
                <?= e($u['role']) ?>
              </span>
            </td>
            <?php if ($hasActive): ?>
              <td><?= (int)($u['active'] ?? 1) === 1 ? 'Sí' : 'No' ?></td>
            <?php endif; ?>
            <?php if ($hasCreated): ?>
              <td><?= e($u['created_at']) ?></td>
            <?php endif; ?>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary me-1"
                data-bs-toggle="modal" data-bs-target="#modalEdit"
                data-id="<?= (int)$u['id'] ?>"
                data-username="<?= e($u['username']) ?>"
                data-email="<?= e($u['email']) ?>"
                data-fullname="<?= e($u['full_name'] ?? '') ?>"
                data-role="<?= strtolower($u['role']) ?>"
                data-active="<?= $hasActive ? (int)$u['active'] : 1 ?>"
              >Editar</button>

              <?php if ($hasActive): ?>
                <form class="d-inline" method="post" action="<?= url('admin_users.php') ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sm <?= (int)$u['active']===1 ? 'btn-outline-warning' : 'btn-outline-success' ?>" type="submit">
                    <?= (int)$u['active']===1 ? 'Desactivar' : 'Activar' ?>
                  </button>
                </form>
              <?php endif; ?>

              <button class="btn btn-sm btn-outline-danger ms-1"
                data-bs-toggle="modal" data-bs-target="#modalDelete"
                data-id="<?= (int)$u['id'] ?>"
                data-username="<?= e($u['username']) ?>"
              >Eliminar</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL: Crear -->
<div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= url('admin_users.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title">Nuevo usuario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Usuario</label>
            <input class="form-control" name="username" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Nombre completo</label>
            <input class="form-control" name="full_name">
          </div>
          <div class="mb-2">
            <label class="form-label">Contraseña</label>
            <input class="form-control" type="password" name="password" minlength="6" required>
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Rol</label>
              <select class="form-select" name="role">
                <option value="seller">Vendedor</option>
                <option value="billing">Facturación</option>
                <option value="admin">Administrador</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Activo</label>
              <select class="form-select" name="active">
                <option value="1" selected>Sí</option>
                <option value="0">No</option>
              </select>
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

<!-- MODAL: Editar -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= url('admin_users.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header">
          <h5 class="modal-title">Editar usuario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Usuario</label>
            <input class="form-control" name="username" id="edit_username" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" id="edit_email" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Nombre completo</label>
            <input class="form-control" name="full_name" id="edit_fullname">
          </div>
          <div class="mb-2">
            <label class="form-label">Contraseña (dejar vacío para no cambiar)</label>
            <input class="form-control" type="password" name="password" id="edit_password" minlength="6" placeholder="(sin cambios)">
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Rol</label>
              <select class="form-select" name="role" id="edit_role">
                <option value="seller">Vendedor</option>
                <option value="billing">Facturación</option>
                <option value="admin">Administrador</option>
              </select>
            </div>
            <?php if ($hasActive): ?>
            <div class="col-md-6">
              <label class="form-label">Activo</label>
              <select class="form-select" name="active" id="edit_active">
                <option value="1">Sí</option>
                <option value="0">No</option>
              </select>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: Eliminar -->
<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= url('admin_users.php') ?>" onsubmit="return confirm('¿Seguro que deseas eliminar este usuario?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="del_id">
        <div class="modal-header">
          <h5 class="modal-title">Eliminar usuario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Esta acción no se puede deshacer.</p>
          <p>Usuario: <strong id="del_username"></strong></p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-danger" type="submit">Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Rellenar modal Editar
document.getElementById('modalEdit')?.addEventListener('show.bs.modal', function (ev) {
  const b = ev.relatedTarget;
  document.getElementById('edit_id').value        = b.getAttribute('data-id');
  document.getElementById('edit_username').value  = b.getAttribute('data-username') || '';
  document.getElementById('edit_email').value     = b.getAttribute('data-email') || '';
  document.getElementById('edit_fullname').value  = b.getAttribute('data-fullname') || '';
  document.getElementById('edit_role').value      = (b.getAttribute('data-role') || 'seller');
  const hasActive = <?= $hasActive ? 'true':'false' ?>;
  if (hasActive) {
    document.getElementById('edit_active').value  = String(b.getAttribute('data-active') || '1');
  }
  const pwd = document.getElementById('edit_password');
  if (pwd) pwd.value = '';
});

// Rellenar modal Eliminar
document.getElementById('modalDelete')?.addEventListener('show.bs.modal', function (ev) {
  const b = ev.relatedTarget;
  document.getElementById('del_id').value = b.getAttribute('data-id');
  document.getElementById('del_username').textContent = b.getAttribute('data-username') || '';
});
</script>
<?php
$content = ob_get_clean();
render('Usuarios', __DIR__ . '/../views/pages/_blank.php', [
  'content' => $content,
]);
