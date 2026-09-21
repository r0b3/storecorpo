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

-- ---------- Usuarios del portal (personal, no clientes) ----------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  DEFAULT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `full_name`     VARCHAR(150) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          VARCHAR(30)  NOT NULL DEFAULT 'Seller',   -- Admin | Billing | Seller
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Categorías ----------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(140) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`)
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
  PRIMARY KEY (`id`),
  KEY `ix_variants_product` (`product_id`),
  KEY `ix_variants_sku` (`sku`),
  CONSTRAINT `fk_variants_product` FOREIGN KEY (`product_id`)
      REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Órdenes / ventas ----------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED DEFAULT NULL,   -- vendedor que registró la venta
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
  `status`           ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cancelled_at`     DATETIME     DEFAULT NULL,
  `cancelled_by`     INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_orders_user` (`user_id`),
  KEY `ix_orders_status` (`status`),
  KEY `ix_orders_created` (`created_at`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`)
      REFERENCES `users` (`id`) ON DELETE SET NULL
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
