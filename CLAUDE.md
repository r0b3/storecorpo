# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es

Punto de venta / tienda interna ("Tienda Stand 2025") para CORPOTURISMO: catálogo con
variantes, carrito, checkout que impacta inventario, e informes de ventas. **Todo el
portal es privado**: no hay tienda pública ni clientes con cuenta; los usuarios son
personal (Admin / Billing / Seller) que registra ventas de un stand.

PHP procedural + PDO/MySQL, Bootstrap 5 por CDN. Sin Composer, sin build, sin tests,
sin framework, sin repositorio git.

## Ejecutar

No hay `build`, `lint` ni suite de pruebas. La app espera vivir en la ruta
`/str/public` (ver `base_url` en `config/config.php`), así que para desarrollo local
conviene servir **desde el directorio padre** de `str/`:

```bash
php -S localhost:8000            # ejecutar en el padre de str/
# → http://localhost:8000/str/public/index.php
```

Si se sirve `public/` como docroot, hay que cambiar `base_url` a `''` — de lo contrario
todos los enlaces y, sobre todo, el `path` del cookie de autenticación quedan mal y el
login entra en bucle.

`config/config.php` **no está versionado** (`.gitignore`): partir de
`config/config.example.php` y rellenar credenciales, `base_url` y `app_key`.

Se requiere una base MySQL ya creada con las tablas `users`,
`categories`, `products`, `product_variants`, `orders`, `order_items`. **No existe
esquema SQL ni migraciones en el repo**; el esquema vive solo en el servidor.

## Arquitectura

### Sin front controller
Cada archivo de `public/` es un endpoint independiente que se auto-carga sus
dependencias con `require_once`. El `.htaccess` solo desactiva el listado de
directorios y sirve archivos reales — no reescribe a un router. Añadir una pantalla =
añadir un `.php` en `public/`.

Orden de `require` habitual (importa, porque `helpers.php` tiene efectos al cargarse):

```php
require_once __DIR__ . '/../lib/helpers.php';   // define url(), e(), money(), CSRF…
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../views/main.php';    // define render()
require_once __DIR__ . '/_gate_private.php';    // exige login
```

`lib/helpers.php` llama a `csrf_token()` al final del archivo: cargarlo ya emite el
cookie CSRF. Por eso debe ir **antes** de cualquier salida.

### Renderizado
`views/main.php` define `render($title, $archivoContenido, $data)`, que incluye
`views/partials/header.php` + `navbar.php` + el contenido + `footer.php`.

El patrón dominante en `public/` es construir el HTML con búfer de salida y pasarlo por
la vista comodín:

```php
ob_start(); ?> …html… <?php
$content = ob_get_clean();
render('Título', __DIR__ . '/../views/pages/_blank.php', ['content'=>$content, 'cats'=>$cats]);
```

`$cats` (lista de categorías) se pasa **solo** para que la navbar pinte su desplegable;
si se omite, el menú de categorías sale vacío sin error.

Archivos huérfanos, no los uses como referencia ni asumas que están activos:
`views/header.php`, `views/footer.php` (sustituidos por `views/partials/`),
`views/pages/home.php` (el catálogo se arma inline en `public/index.php`) y
`public/acceso.php` (login duplicado de `login.php`).

### Autenticación: cookie firmada, no sesión
`lib/auth.php` implementa login *stateless*: el cookie `STRAUTH` lleva
`base64url(json).base64url(HMAC-SHA256)` firmado con `app_key` de la config, con
expiración de 8 h y renovación automática cuando quedan menos de 30 min. **Los datos de
usuario y el rol viven dentro del cookie**, no se releen de BD en cada request: cambiar
el rol de alguien en `admin_users.php` no surte efecto hasta que vuelva a iniciar
sesión. El `path` del cookie es exactamente `base_url()`.

`$_SESSION` se usa únicamente para el carrito (`$_SESSION['cart']`) y el flash de orden
(`last_order_id`, `last_order_total`).

`require_roles()` acepta sinónimos en español: `['Admin']` también deja pasar a
`administrador`; `Seller` cubre `vendedor`/`staff`; `Billing` cubre
`facturador`/`facturación`. Comparación en minúsculas.

`public/_gate_private.php` exige login en todo archivo que lo incluya salvo
`login.php`/`logout.php`. **No protege por sí solo**: hay endpoints en `public/` que no
lo incluyen (ver "Deuda" abajo).

### CSRF: double-submit cookie
Cookie `STRCSRF` con `path=/` (deliberado: con el path acotado se perdían peticiones en
rutas hermanas y aparecían 403). `ensure_csrf()` valida POST/PUT/PATCH/DELETE leyendo el
token de la cabecera `X-CSRF-Token`, de `$_POST['csrf']` o del JSON del cuerpo.

En formularios: `<?= csrf_field() ?>`. En AJAX: usar `window.csrfFetch(url, init)`,
definido en `views/partials/header.php`, que convierte un objeto plano en `FormData`,
inyecta el token y añade `X-Requested-With: fetch`. No usar `fetch()` directo.

### Esquema tolerante (patrón central del código)
Los endpoints **no asumen el esquema**: lo interrogan en caliente con `has_column()`,
`table_exists()`, `columns()` y `first_existing_column()` (redefinidos localmente en
varios archivos, unos con `SHOW COLUMNS` y otros con `information_schema`). Ejemplos
reales: la imagen de producto puede llamarse `image|cover|photo|picture|img`; la tabla
de ventas puede ser `orders` o `sales` (e ítems `order_items` o `sales_items`);
`orders.for_artesanas`, `products.stock` y `users.active` pueden no existir y el código
ramifica en cada caso.

Al tocar consultas, **mantener ese estilo**: detectar la columna antes de usarla en vez
de asumirla, o romperás instalaciones con esquema distinto.

### Flujo de venta e inventario
1. `index.php` lista productos con sus `product_variants` activas y publica al carrito
   por AJAX contra `cart.php` (`action=add`, responde JSON si es AJAX, si no PRG).
   La clave de línea del carrito es `"{product_id}:{variant_id|0}"`.
2. `cart.php` fija el precio desde la variante (o `base_price`) y topa la cantidad
   contra `stock`.
3. `checkout.php` crea `orders` + `order_items` en una transacción y **descuenta stock**
   (`GREATEST(stock - q, 0)`) de la variante, o del producto si no hay variante.
   Estado `pending`, y pasa a `paid` automáticamente si el pago es `efectivo`.
   Métodos válidos: `efectivo`, `transferencia`, `por_pagar`. Cliente `natural`
   (nombre + cédula) o `empresa` (razón social + NIT).
4. `admin_sales.php` (Admin/Billing) permite **anular**: `SELECT … FOR UPDATE`, devuelve
   el stock, marca `status='cancelled'` y registra `cancelled_at`/`cancelled_by` si esas
   columnas existen.
5. `admin_sales_report.php` consolida: **excluye las anuladas de los totales** pero las
   incluye en el listado de órdenes.

`admin_inventory.php` (solo Admin) hace el CRUD de productos y variantes; las imágenes
pasan por `save_uploaded_image()`, que valida JPG/PNG/WebP hasta 5 MB, las guarda en
`public/uploads/` con nombre aleatorio y **en BD se almacena solo el nombre de archivo**
(las vistas componen la URL con `url('uploads/'.$img)`).

### Impuestos: desactivados a propósito
`config.php` declara `tax_rate => 0.19`, pero `cart.php` y `checkout.php` fuerzan
`tax = 0` y `total = subtotal`, y guardan `tax = 0.0` en la orden. Es intencional para
este stand — no "arreglarlo" reconectando `tax_rate` sin confirmarlo con el usuario.

## Convenciones

- Escapar **siempre** la salida con `e()`; formatear precios con `money()` (formato
  colombiano, sin decimales: `1.234.567`).
- Enlaces y redirecciones con `url('archivo.php')` / `redirect('archivo.php')`. `url()`
  antepone `base_url`; `redirect()` deja pasar tal cual lo que empiece por `/` o `http`,
  así que pasarle `url(...)` y una ruta relativa no es equivalente — no encadenar ambos.
- Mensajes de usuario, comentarios y nombres de acción en español; código y esquema en
  inglés.
- Feedback entre páginas vía PRG con `?ok=1|0&msg=…` en la query.

## Deuda conocida (no introducir más, y avisar si se toca)

- En la instalación de producción, `app_key` sigue siendo el **placeholder de ejemplo**:
  quien lo conozca puede firmar un cookie `STRAUTH` con rol Admin. Cualquier trabajo
  sobre auth debería empezar por rotar esa clave (y las credenciales de MySQL, que
  estuvieron en el árbol de trabajo antes de ignorarse).
- Endpoints de depuración presentes en el servidor pero **fuera del repo**
  (`.gitignore`), sin `_gate_private.php`: `test.php` (`phpinfo()`), `whoami.php`,
  `check_session.php`, `test_after_login.php` y `create_admin.php` (con correo y
  contraseña en claro; su propio comentario dice que debe borrarse tras usarse).
  Deberían borrarse también del hosting.
- Todos los endpoints activan `display_errors=1`, y varios `catch` imprimen
  `$e->getMessage()` al usuario.
