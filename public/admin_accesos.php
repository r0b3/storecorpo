<?php
// public/admin_accesos.php — Accesos a la tienda (solo Admin).
//
// La plataforma NO crea usuarios: los toma del SSO. Aquí se eligen usuarios
// ACTIVOS del panel y se les aplica un rol PROPIO DE LA TIENDA, igual que
// hacen las demás plataformas hijas (bde usa guard/rh/asignador…).
//
//   - `accesos(id, rol, estado)` → autorización. `id` = id del usuario SSO.
//   - `usuarios`                 → espejo de nombres (contrasena_hash '*SSO*').
//
// Revocar es SIEMPRE lógico (estado='inactivo'), nunca borrado físico: así se
// conserva la referencia de quién registró ventas antiguas.
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../views/main.php';
require_once __DIR__ . '/../lib/db.php';
// La puerta va ANTES de verificar: es la que deja $usuario_sso y $sso_pdo.
require_once __DIR__ . '/_gate_private.php';

require_roles(['Admin']);

$pdo = get_pdo();
$yo  = auth_user();

// Roles propios de la tienda. La clave es lo que se guarda en accesos.rol y
// lo que sso_map_role() traduce; el texto es lo que ve el administrador.
const ROLES_TIENDA = [
  'admin'   => 'Administrador — inventario, ventas, consolidado y accesos',
  'billing' => 'Facturación — ventas y consolidado',
  'seller'  => 'Vendedor — catálogo y carrito',
];

/** Cuántos administradores activos quedan (para no dejar la tienda sin Admin). */
function admins_activos(PDO $pdo): int {
  return (int)$pdo->query("SELECT COUNT(*) FROM accesos WHERE rol='admin' AND estado='activo'")->fetchColumn();
}

/** Copia la referencia del SSO al espejo local. No crea usuarios. */
function espejar_usuario(PDO $pdo, ?PDO $sso, int $id, string $rol): void {
  if (!$sso) return;
  $s = $sso->prepare("SELECT usuario, nombre, apellido FROM usuarios WHERE id = ?");
  $s->execute([$id]);
  $u = $s->fetch();
  if (!$u) return;
  $pdo->prepare(
    "INSERT INTO usuarios (id, nombre_usuario, contrasena_hash, rol, nombre, apellido)
     VALUES (?, ?, '*SSO*', ?, ?, ?)
     ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), apellido=VALUES(apellido), rol=VALUES(rol)"
  )->execute([$id, $u['usuario'], $rol, $u['nombre'], $u['apellido']]);
}

/* ===== Acciones ===== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  ensure_csrf();
  $accion = $_POST['accion'] ?? '';
  $uid    = (int)($_POST['usuario_id'] ?? 0);

  try {
    if ($uid <= 0) throw new RuntimeException('Usuario inválido.');

    if ($accion === 'conceder') {
      $rol = (string)($_POST['rol'] ?? 'seller');
      if (!isset(ROLES_TIENDA[$rol])) throw new RuntimeException('Rol no válido.');

      // Verifica contra el SSO que el usuario exista y esté activo.
      $chk = $sso_pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND activo = 1");
      $chk->execute([$uid]);
      if (!$chk->fetchColumn()) throw new RuntimeException('El usuario no existe o está inactivo en el SSO.');

      // Si se degrada al último admin activo, se bloquea.
      $era = $pdo->prepare("SELECT rol, estado FROM accesos WHERE id = ?");
      $era->execute([$uid]);
      $prev = $era->fetch();
      if ($prev && ($prev['rol'] ?? '') === 'admin' && ($prev['estado'] ?? '') === 'activo'
          && $rol !== 'admin' && admins_activos($pdo) <= 1) {
        throw new RuntimeException('Es el único administrador activo: asigna otro antes de cambiarle el rol.');
      }

      $pdo->prepare(
        "INSERT INTO accesos (id, rol, estado) VALUES (?, ?, 'activo')
         ON DUPLICATE KEY UPDATE rol = VALUES(rol), estado = 'activo'"
      )->execute([$uid, $rol]);
      espejar_usuario($pdo, $sso_pdo ?? null, $uid, $rol);

      redirect('admin_accesos.php?ok=1&msg=' . urlencode('Acceso actualizado.'));
    }

    if ($accion === 'revocar') {
      $era = $pdo->prepare("SELECT rol, estado FROM accesos WHERE id = ?");
      $era->execute([$uid]);
      $prev = $era->fetch();
      if (!$prev || ($prev['estado'] ?? '') !== 'activo') {
        throw new RuntimeException('Ese usuario no tiene acceso activo.');
      }
      if (($prev['rol'] ?? '') === 'admin' && admins_activos($pdo) <= 1) {
        throw new RuntimeException('Es el único administrador activo: no puedes revocarlo.');
      }
      // Baja lógica: conserva el histórico de ventas.
      $pdo->prepare("UPDATE accesos SET estado='inactivo' WHERE id = ?")->execute([$uid]);
      redirect('admin_accesos.php?ok=1&msg=' . urlencode('Acceso revocado.'));
    }

    throw new RuntimeException('Acción no reconocida.');

  } catch (Throwable $e) {
    error_log('[admin_accesos] ' . $e->getMessage());
    redirect('admin_accesos.php?ok=0&msg=' . urlencode($e->getMessage()));
  }
}

/* ===== Datos ===== */
// Universo: usuarios ACTIVOS del panel. Se listan desde el SSO, no de aquí.
$ssoUsers = [];
if (isset($sso_pdo) && $sso_pdo instanceof PDO) {
  $ssoUsers = $sso_pdo->query(
    "SELECT id, nombre, apellido, usuario, rol AS rol_sso
       FROM usuarios WHERE activo = 1 ORDER BY nombre, apellido"
  )->fetchAll(PDO::FETCH_ASSOC);
}

// Estado local de acceso, indexado por id de usuario SSO.
$accesos = [];
foreach ($pdo->query("SELECT id, rol, estado FROM accesos")->fetchAll(PDO::FETCH_ASSOC) as $a) {
  $accesos[(int)$a['id']] = $a;
}

$conAcceso = array_values(array_filter($ssoUsers, fn($u) => (($accesos[(int)$u['id']]['estado'] ?? '') === 'activo')));
$sinAcceso = array_values(array_filter($ssoUsers, fn($u) => (($accesos[(int)$u['id']]['estado'] ?? '') !== 'activo')));

$flash_ok  = ($_GET['ok'] ?? '') === '1';
$flash_msg = trim($_GET['msg'] ?? '');

/* ===== Render ===== */
ob_start(); ?>
<div class="d-flex align-items-center mb-3">
  <h4 class="mb-0">Accesos a la tienda</h4>
  <span class="badge bg-secondary ms-2"><?= count($conAcceso) ?> con acceso</span>
</div>

<?php if ($flash_msg !== ''): ?>
  <div class="alert <?= $flash_ok ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show">
    <?= e($flash_msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<p class="text-muted small">
  Los usuarios vienen del <strong>Panel General</strong>: aquí no se crean ni se eliminan,
  solo se les concede acceso a la tienda con un rol propio de esta plataforma.
  Revocar es una baja lógica, para conservar quién registró cada venta.
</p>

<div class="card mb-4">
  <div class="card-header">Con acceso</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Usuario</th><th>Rol en la tienda</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$conAcceso): ?>
        <tr><td colspan="3" class="text-muted">Nadie tiene acceso todavía.</td></tr>
      <?php endif; ?>
      <?php foreach ($conAcceso as $u):
        $uid = (int)$u['id']; $rolActual = $accesos[$uid]['rol'] ?? 'seller';
        if (!isset(ROLES_TIENDA[$rolActual])) $rolActual = 'seller';
      ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= e(trim($u['nombre'].' '.$u['apellido'])) ?></div>
            <div class="small text-muted"><?= e($u['usuario']) ?><?= $uid === (int)$yo['id'] ? ' · tú' : '' ?></div>
          </td>
          <td>
            <form method="post" class="d-flex gap-2 align-items-center">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="conceder">
              <input type="hidden" name="usuario_id" value="<?= $uid ?>">
              <select class="form-select form-select-sm" name="rol" style="max-width:320px">
                <?php foreach (ROLES_TIENDA as $k => $label): ?>
                  <option value="<?= e($k) ?>" <?= $k === $rolActual ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary" type="submit">Guardar</button>
            </form>
          </td>
          <td class="text-end">
            <form method="post" onsubmit="return confirm('¿Revocar el acceso a la tienda?');">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="revocar">
              <input type="hidden" name="usuario_id" value="<?= $uid ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">Revocar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header">Dar acceso a un usuario del panel</div>
  <div class="card-body">
    <?php if (!$sinAcceso): ?>
      <div class="text-muted">Todos los usuarios activos del panel ya tienen acceso.</div>
    <?php else: ?>
      <form method="post" class="row g-2 align-items-center">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="conceder">
        <div class="col-md-5">
          <select class="form-select" name="usuario_id" required>
            <option value="">Selecciona un usuario…</option>
            <?php foreach ($sinAcceso as $u): ?>
              <option value="<?= (int)$u['id'] ?>">
                <?= e(trim($u['nombre'].' '.$u['apellido'])) ?> (<?= e($u['usuario']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <select class="form-select" name="rol">
            <?php foreach (ROLES_TIENDA as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $k === 'seller' ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-primary" type="submit">Dar acceso</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
render('Accesos', __DIR__ . '/../views/pages/_blank.php', ['content' => $content, 'cats' => []]);
