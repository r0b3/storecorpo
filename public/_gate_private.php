<?php
// public/_gate_private.php — puerta privada del portal, delegada al SSO central.
//
// Incluye el validador del Panel General EN SCOPE GLOBAL (este archivo se
// incluye a su vez en scope global desde cada página), de modo que las
// variables $usuario_sso, $rol_local, $estado_local y $panel_base queden
// disponibles para auth_user()/require_roles() y para las vistas.
//
// El validador se encarga de:
//   - redirigir al Panel General si no hay sesión SSO válida,
//   - exigir la cookie de entrada `pg_entry_store` (acceso solo desde el
//     panel; por URL directa devuelve 403),
//   - resolver el rol local desde `{db}.accesos`.
//
// Debe ejecutarse ANTES de cualquier salida de la página.
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';

// Ruta al validador central: la app vive en /var/www/c2206/store/public,
// el panel en /var/www/c2206/svc (dos niveles arriba + svc/).
$__sso_validator = __DIR__ . '/../../svc/src/auth/validador.php';
if (!is_file($__sso_validator)) {
  http_response_code(500);
  exit('Validador SSO no disponible.');
}
require $__sso_validator;

require_login();
