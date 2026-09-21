<?php
// lib/auth.php — Autenticación delegada al SSO central (Panel General).
//
// Ya NO hay login local, ni cookie firmada, ni app_key: el validador central
// (svc/src/auth/validador.php) valida la sesión, el acceso a la plataforma y
// el rol, y deja en el scope GLOBAL de la página estas variables:
//   $usuario_sso   → ['id','nombre','rol']   (rol global del SSO)
//   $rol_local     → rol dentro de esta plataforma (tabla `accesos`)
//   $estado_local  → estado local del usuario
//   $panel_base    → URL base del Panel General
//
// El validador se incluye desde public/_gate_private.php EN SCOPE GLOBAL, para
// que esas variables lleguen a las páginas; estas funciones las leen con
// `global`. Ver [[_gate_private]].
require_once __DIR__ . '/helpers.php';

/**
 * Mapea el rol del SSO a los roles de la tienda (Admin | Billing | Seller).
 * - Admin global del SSO ($usuario_sso['rol']==='admin') => Admin siempre.
 * - Rol local (accesos.rol) es texto libre: se normaliza con sinónimos.
 * - Cualquier rol local activo no reconocido cae a Seller (piso de venta).
 */
function sso_map_role(?string $rolLocal, ?string $rolGlobal): string {
  if (strtolower(trim((string)$rolGlobal)) === 'admin') return 'Admin';
  $r = strtolower(trim((string)$rolLocal));
  if (in_array($r, ['admin', 'administrador'], true)) return 'Admin';
  if (in_array($r, ['billing', 'facturador', 'facturacion', 'facturación'], true)) return 'Billing';
  return 'Seller';
}

/**
 * Usuario autenticado (o null). Construido a partir de las variables que dejó
 * el validador central. `email` no lo expone el JWT del SSO, va a null.
 */
function auth_user(): ?array {
  global $usuario_sso, $rol_local;
  if (empty($usuario_sso) || !is_array($usuario_sso)) return null;
  return [
    'id'        => (int)($usuario_sso['id'] ?? 0),
    'username'  => $usuario_sso['nombre'] ?? null,
    'email'     => null,
    'full_name' => $usuario_sso['nombre'] ?? null,
    'role'      => sso_map_role($rol_local ?? null, $usuario_sso['rol'] ?? null),
  ];
}

/** URL del Panel General (para "volver") y de cierre de sesión central. */
function sso_panel_url(): string {
  global $panel_base;
  $base = (string)($panel_base ?? '');
  if ($base === '' && class_exists('Auth')) { $base = Auth::panelBaseUrl(); }
  return rtrim($base, '/');
}
function sso_logout_url(): string {
  return sso_panel_url() . '/api/auth_logout.php';
}

/**
 * El validador ya redirige al panel si no hay sesión válida; si aun así se
 * llega aquí sin usuario, se corta. No hace login: solo verifica.
 */
function require_login(): void {
  // Si la puerta no corrió, esto NO es un problema de permisos: es que la
  // página no incluyó _gate_private.php antes de verificar. Fallar con
  // "Acceso denegado" ahí manda a depurar roles en vez del orden de includes.
  if (!defined('SSO_GATE_OK')) {
    error_log('[auth] require_login() sin _gate_private.php previo en ' . ($_SERVER['SCRIPT_NAME'] ?? '?'));
    http_response_code(500);
    exit('Error de configuración: la puerta SSO no se cargó antes de verificar el acceso.');
  }
  if (!auth_user()) {
    http_response_code(403);
    exit('Acceso denegado');
  }
}

/**
 * Exige que el rol (mapeado) esté entre los permitidos. Mantiene los sinónimos
 * en español por si el rol viene ya normalizado desde otra parte.
 */
function require_roles(array $roles): void {
  $u = auth_user();
  if (!$u) { require_login(); return; }
  $want = array_map(fn($r) => strtolower(trim($r)), $roles);
  $role = strtolower(trim($u['role'] ?? ''));
  $syn  = [
    'admin'   => ['admin', 'administrador'],
    'seller'  => ['seller', 'vendedor', 'staff', 'empleado'],
    'billing' => ['billing', 'facturador', 'facturación', 'facturacion'],
  ];
  foreach ($want as $w) {
    $set = $syn[$w] ?? [$w];
    if (in_array($role, $set, true)) return;
  }
  http_response_code(403);
  exit('Acceso denegado');
}
