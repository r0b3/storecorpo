-- =====================================================================
--  Esquema de la Tienda Stand 2025 (Corpo Store)
--  RECONSTRUIDO desde el código de la app (no había SQL en el repo).
--  Objetivo: instalación limpia y vacía en un servidor nuevo (host02).
--
--  No incluye CREATE DATABASE ni USE: cárgalo YA situado dentro de la
--  base de la app, p. ej.:
--     sudo mysql c0rp0tur1sm0_<slug> < schema.sql
--
--  El código usa detección de columnas en caliente (has_column/…), así
--  que las columnas OPCIONALES (active, stock, for_artesanas,
--  cancelled_at/by) se incluyen para habilitar todas las rutas: stock e
--  inventario, anulación con auditoría y reporte "para artesanas".
--  MariaDB 10.11 / utf8mb4.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Espejo de usuarios del SSO (NO se crean aquí) ----------
-- La plataforma hija no crea usuarios: los referencia. El Panel SSO
-- (svc/public/api/platform_access.php) hace upsert aquí al otorgar acceso:
--   INSERT INTO <db>.usuarios (id, nombre_usuario, nombre, apellido, rol,
--                              contrasena_hash) VALUES (…, '*SSO*')
-- `id` es el id del usuario en el SSO (mismo criterio que `accesos.id`), y
-- `contrasena_hash` es siempre el centinela '*SSO*': aquí nunca hay claves.
-- Esta tabla solo sirve para resolver NOMBRES (quién registró cada venta).
-- Las columnas que el panel no escribe deben admitir NULL o tener default,
-- o el upsert falla en silencio (va dentro de un try/catch en el panel).
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`              INT(11)      NOT NULL,
  `nombre_usuario`  VARCHAR(50)  NOT NULL,
  `contrasena_hash` VARCHAR(255) NOT NULL DEFAULT '*SSO*',
  `rol`             VARCHAR(50)  DEFAULT NULL,
  `nombre`          VARCHAR(50)  DEFAULT NULL,
  `apellido`        VARCHAR(50)  DEFAULT NULL,
  `cargo`           VARCHAR(50)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_nombre_usuario` (`nombre_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Categorías, con UN nivel de subcategoría (parent_id).
-- Sirve para taxonomía: Camisetas > Hombre / Mujer. NO para talla ni color:
-- esos son variantes (product_variants.option1/2), que además llevan SKU,
-- precio y stock por combinación. Meterlos aquí duplicaría el dato y se
-- perdería el stock por talla.
-- ON DELETE SET NULL, no CASCADE: borrar una categoría padre no debe llevarse
-- por delante sus hijas en silencio; quedan como categorías de primer nivel.
CREATE TABLE IF NOT EXISTS `categories` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(120) NOT NULL,
  `slug`      VARCHAR(140) NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `ix_categories_parent` (`parent_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`)
      REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Productos ----------
CREATE TABLE IF NOT EXISTS `products` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(180) NOT NULL,
  `slug`        VARCHAR(200) DEFAULT NULL,
  `description` TEXT         DEFAULT NULL,
  `base_price`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `image`       VARCHAR(255) DEFAULT NULL,      -- solo el nombre de archivo (public/uploads/)
  `stock`       INT          DEFAULT NULL,      -- opcional: stock a nivel de producto sin variantes
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_slug` (`slug`),
  KEY `ix_products_category` (`category_id`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`)
      REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Variantes de producto ----------
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    INT UNSIGNED NOT NULL,
  `sku`           VARCHAR(80)  DEFAULT NULL,
  `price`         DECIMAL(12,2) DEFAULT NULL,   -- NULL => usa products.base_price
  `stock`         INT          NOT NULL DEFAULT 0,
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `option1_name`  VARCHAR(60)  DEFAULT NULL,
  `option1_value` VARCHAR(120) DEFAULT NULL,
  `option2_name`  VARCHAR(60)  DEFAULT NULL,
  `option2_value` VARCHAR(120) DEFAULT NULL,
  `image`         VARCHAR(255) DEFAULT NULL,   -- foto propia (p. ej. el color); si falta, la del producto
  PRIMARY KEY (`id`),
  KEY `ix_variants_product` (`product_id`),
  KEY `ix_variants_sku` (`sku`),
  CONSTRAINT `fk_variants_product` FOREIGN KEY (`product_id`)
      REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Opciones de variante 3 en adelante ----------
-- Las opciones 1 y 2 viven en product_variants.option1_*/option2_*; de la
-- tercera en adelante (Manga, Material, Género…) van aquí, una fila por opción.
CREATE TABLE IF NOT EXISTS `product_variant_options` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` INT UNSIGNED NOT NULL,
  `position`   TINYINT UNSIGNED NOT NULL,        -- 3, 4, 5…
  `name`       VARCHAR(60)  NOT NULL,
  `value`      VARCHAR(120) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_vopt_variant` (`variant_id`, `position`),
  KEY `ix_vopt_name_value` (`name`, `value`),
  CONSTRAINT `fk_vopt_variant` FOREIGN KEY (`variant_id`)
      REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Carritos reservados (en espera) ----------
-- Reservar APARTA stock (se descuenta al reservar); retomar o liberar lo
-- devuelve. `held` dice si esa línea apartó stock de verdad.
CREATE TABLE IF NOT EXISTS `saved_carts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label`      VARCHAR(120) NOT NULL,              -- nombre o seña del cliente
  `user_id`    INT UNSIGNED DEFAULT NULL,          -- id SSO de quien reservó (sin FK, ver orders)
  `units`      INT          NOT NULL DEFAULT 0,
  `total`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_scarts_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `saved_cart_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id`       INT UNSIGNED NOT NULL,
  `product_id`    INT UNSIGNED NOT NULL,
  `variant_id`    INT UNSIGNED DEFAULT NULL,
  `name`          VARCHAR(180) NOT NULL,
  `variant_label` VARCHAR(255) DEFAULT NULL,
  `sku`           VARCHAR(80)  DEFAULT NULL,
  `image`         VARCHAR(255) DEFAULT NULL,
  `unit_price`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `quantity`      INT          NOT NULL DEFAULT 1,
  `held`          TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_sitems_cart` (`cart_id`),
  KEY `ix_sitems_variant` (`variant_id`),
  CONSTRAINT `fk_sitems_cart` FOREIGN KEY (`cart_id`)
      REFERENCES `saved_carts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Órdenes / ventas ----------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT(11)      DEFAULT NULL,   -- id del usuario SSO que registró la venta
  `customer_type`    ENUM('natural','empresa') NOT NULL DEFAULT 'natural',
  `company_name`     VARCHAR(180) DEFAULT NULL,
  `company_nit`      VARCHAR(40)  DEFAULT NULL,
  `person_name`      VARCHAR(180) DEFAULT NULL,
  `person_id`        VARCHAR(40)  DEFAULT NULL,
  `customer_email`   VARCHAR(190) DEFAULT NULL,
  `customer_phone`   VARCHAR(40)  DEFAULT NULL,
  `shipping_address` VARCHAR(255) DEFAULT NULL,
  `payment_method`   ENUM('efectivo','transferencia','por_pagar') NOT NULL DEFAULT 'efectivo',
  `payment_ref`      VARCHAR(120) DEFAULT NULL,
  `for_artesanas`    TINYINT(1)   NOT NULL DEFAULT 0,
  `subtotal`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax`              DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- la app fuerza 0
  `total`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  -- gift = obsequio: el stock sigue descontado pero no suma como venta.
  `status`           ENUM('pending','paid','cancelled','gift') NOT NULL DEFAULT 'pending',
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cancelled_at`     DATETIME     DEFAULT NULL,
  `cancelled_by`     INT UNSIGNED DEFAULT NULL,
  `paid_at`          DATETIME     DEFAULT NULL,   -- cuándo se dio por cobrada
  `paid_by`          INT(11)      DEFAULT NULL,   -- id del usuario SSO que la cobró
  -- Comprobante del cobro. Aparte de payment_ref, que es la referencia
  -- capturada en el checkout: son dos momentos distintos del mismo pago.
  `paid_ref`         VARCHAR(120) DEFAULT NULL,
  `notas`            TEXT         DEFAULT NULL,   -- observación libre sobre la venta
  `notas_at`         DATETIME     DEFAULT NULL,
  `notas_by`         INT(11)      DEFAULT NULL,
  `gift_at`          DATETIME     DEFAULT NULL,   -- cuándo se dio como obsequio
  `gift_by`          INT(11)      DEFAULT NULL,   -- id SSO de quien lo autorizó
  `gift_note`        VARCHAR(255) DEFAULT NULL,   -- motivo o beneficiario
  PRIMARY KEY (`id`),
  KEY `ix_orders_user` (`user_id`),
  KEY `ix_orders_status` (`status`),
  KEY `ix_orders_created` (`created_at`)
  -- SIN clave foránea a `usuarios` a propósito: el espejo lo puebla el panel
  -- en modo best-effort (try/catch). Si alguien tiene acceso pero su fila aún
  -- no se replicó, un FK haría fallar la venta entera. `user_id` guarda el id
  -- del SSO y los nombres se resuelven con LEFT JOIN.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Líneas de orden ----------
CREATE TABLE IF NOT EXISTS `order_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `product_id`    INT UNSIGNED DEFAULT NULL,
  `variant_id`    INT UNSIGNED DEFAULT NULL,
  `sku`           VARCHAR(80)  DEFAULT NULL,
  `product_name`  VARCHAR(180) NOT NULL,
  `variant_label` VARCHAR(180) DEFAULT NULL,
  `unit_price`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `quantity`      INT          NOT NULL DEFAULT 1,
  `line_total`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `ix_items_order` (`order_id`),
  KEY `ix_items_product` (`product_id`),
  CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`)
      REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
