<?php
// Plantilla de configuración. Copiar a config/config.php y rellenar.
// config/config.php está en .gitignore: nunca se versiona.
//
// La autenticación la maneja el SSO central (ver lib/auth.php), así que aquí
// NO hay app_key ni nada de login: solo la conexión a la BD de DATOS de la
// tienda (catálogo, variantes, órdenes). El usuario de BD es propio de la
// plataforma, con permisos solo sobre su base (patrón host02).
return [
  'db' => [
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'name'    => 'c0rp0tur1sm0_corpo_store',
    'user'    => 'c0rp0tur1sm0_corpo_store',
    'pass'    => 'PON_AQUI_LA_CLAVE_DEL_USUARIO_DE_BD',
    'charset' => 'utf8mb4'
  ],

  // Declarado pero desactivado: cart.php y checkout.php fuerzan tax = 0.
  'tax_rate' => 0.19,

  // Ruta pública de la plataforma (coincide con url_base en el registro SSO).
  'base_url' => '/corpo_store',
];
