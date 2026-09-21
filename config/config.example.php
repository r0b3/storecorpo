<?php
// Plantilla de configuración. Copiar a config/config.php y rellenar.
// config/config.php está en .gitignore: nunca se versiona.
return [
  'db' => [
    'host'    => 'localhost',
    'port'    => 3306,
    'name'    => 'nombre_de_la_base',
    'user'    => 'usuario',
    'pass'    => 'contraseña',
    'charset' => 'utf8mb4'
  ],

  // Declarado pero desactivado: cart.php y checkout.php fuerzan tax = 0.
  'tax_rate' => 0.19,

  // Ruta pública de la app. Debe coincidir con el path del cookie de sesión.
  // Si public/ es el docroot, usar '' (cadena vacía).
  'base_url' => '/str/public',

  // 🔐 Clave que firma el cookie de autenticación (HMAC-SHA256).
  // Generar una cadena aleatoria larga (32–64+ chars), p. ej.:
  //   php -r "echo bin2hex(random_bytes(32));"
  // Quien la conozca puede falsificar una sesión con rol Admin.
  'app_key' => 'GENERAR_UNA_CADENA_ALEATORIA_LARGA_Y_UNICA'
];
