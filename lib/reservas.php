<?php
// lib/reservas.php — Carritos reservados ("en espera").
//
// Cuando un cliente no se decide, el vendedor reserva su carrito y atiende a
// otro. Reservar APARTA el stock: se descuenta en ese momento, igual que una
// venta, para que nadie más venda esas unidades. Retomar o liberar el carrito
// lo devuelve. Por eso cada línea guarda `held`: si se apartó stock de verdad
// (variante, o producto con columna stock) o no; al devolver solo se repone lo
// que se apartó.
//
// Todas las funciones que tocan stock esperan una transacción ABIERTA por el
// llamador: reservar el carrito en curso y retomar otro tiene que ser atómico.

function reservas_disponible(PDO $pdo): bool {
  static $hay = null;
  if ($hay === null) {
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('saved_carts','saved_cart_items')");
    $hay = (int)$st->fetchColumn() === 2;
  }
  return $hay;
}

/** ¿products tiene columna stock? (productos sin variantes) */
function reservas_stock_producto(PDO $pdo): bool {
  static $hay = null;
  if ($hay === null) {
    $st = $pdo->query("SHOW COLUMNS FROM products LIKE 'stock'");
    $hay = (bool)$st->fetch();
  }
  return $hay;
}

/**
 * Reserva las líneas de un carrito de sesión: aparta stock y guarda el
 * carrito. Lanza si alguna línea pide más de lo que hay (sin tocar nada,
 * porque el llamador hace rollback). Devuelve el id del carrito reservado.
 */
function reservas_apartar(PDO $pdo, array $cart, string $nombre, ?int $uid): int {
  if (!$cart) { throw new RuntimeException('El carrito está vacío: no hay nada que reservar.'); }

  $lockVar  = $pdo->prepare("SELECT stock FROM product_variants WHERE id = :id FOR UPDATE");
  $decVar   = $pdo->prepare("UPDATE product_variants SET stock = stock - :q WHERE id = :id");
  $conProd  = reservas_stock_producto($pdo);
  $lockProd = $conProd ? $pdo->prepare("SELECT stock FROM products WHERE id = :id FOR UPDATE") : null;
  $decProd  = $conProd ? $pdo->prepare("UPDATE products SET stock = stock - :q WHERE id = :id") : null;

  $lineas = []; $unidades = 0; $total = 0.0;
  foreach ($cart as $it) {
    $pid = (int)($it['pid'] ?? 0);
    $vid = (int)($it['vid'] ?? 0);
    $q   = (int)($it['qty'] ?? 0);
    if ($pid <= 0 || $q <= 0) continue;
    $etiqueta = trim(($it['name'] ?? 'Producto') . (($it['variant'] ?? '') !== '' ? ' (' . $it['variant'] . ')' : ''));

    $held = 0;
    if ($vid > 0) {
      $lockVar->execute([':id' => $vid]);
      $stock = $lockVar->fetchColumn();
      if ($stock === false) { throw new RuntimeException("La variante de {$etiqueta} ya no existe."); }
      if ((int)$stock < $q) {
        throw new RuntimeException("Solo quedan " . (int)$stock . " de {$etiqueta}; ajusta la cantidad antes de reservar.");
      }
      $decVar->execute([':q' => $q, ':id' => $vid]);
      $held = 1;
    } elseif ($conProd) {
      $lockProd->execute([':id' => $pid]);
      $stock = $lockProd->fetchColumn();
      // stock NULL = el producto no lleva inventario propio: no hay qué apartar.
      if ($stock !== false && $stock !== null) {
        if ((int)$stock < $q) {
          throw new RuntimeException("Solo quedan " . (int)$stock . " de {$etiqueta}; ajusta la cantidad antes de reservar.");
        }
        $decProd->execute([':q' => $q, ':id' => $pid]);
        $held = 1;
      }
    }

    $precio = (float)($it['price'] ?? 0);
    $unidades += $q; $total += $q * $precio;
    $lineas[] = [$pid, $vid ?: null, (string)($it['name'] ?? 'Producto'), (string)($it['variant'] ?? ''),
                 $it['sku'] ?? null, $it['image'] ?? null, $precio, $q, $held];
  }
  if (!$lineas) { throw new RuntimeException('El carrito está vacío: no hay nada que reservar.'); }

  $nombre = mb_substr(trim($nombre), 0, 120);
  $pdo->prepare("INSERT INTO saved_carts (label, user_id, units, total, created_at)
                 VALUES (:l, :u, :n, :t, NOW())")
      ->execute([':l' => $nombre !== '' ? $nombre : 'Cliente en espera', ':u' => $uid, ':n' => $unidades, ':t' => $total]);
  $cid = (int)$pdo->lastInsertId();

  $ins = $pdo->prepare("INSERT INTO saved_cart_items
      (cart_id, product_id, variant_id, name, variant_label, sku, image, unit_price, quantity, held)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
  foreach ($lineas as $l) { $ins->execute(array_merge([$cid], $l)); }
  return $cid;
}

/**
 * Libera un carrito reservado: devuelve al inventario lo apartado y lo borra.
 * Devuelve sus líneas (para pasarlas al carrito de sesión al retomar).
 */
function reservas_liberar(PDO $pdo, int $cid): array {
  $st = $pdo->prepare("SELECT id, label FROM saved_carts WHERE id = :id FOR UPDATE");
  $st->execute([':id' => $cid]);
  $cab = $st->fetch(PDO::FETCH_ASSOC);
  // Otro vendedor pudo retomarlo o liberarlo un instante antes.
  if (!$cab) { throw new RuntimeException('Ese carrito ya no está reservado (otro vendedor pudo retomarlo).'); }

  $it = $pdo->prepare("SELECT * FROM saved_cart_items WHERE cart_id = :id ORDER BY id");
  $it->execute([':id' => $cid]);
  $lineas = $it->fetchAll(PDO::FETCH_ASSOC);

  $incVar  = $pdo->prepare("UPDATE product_variants SET stock = stock + :q WHERE id = :id");
  $incProd = reservas_stock_producto($pdo) ? $pdo->prepare("UPDATE products SET stock = stock + :q WHERE id = :id") : null;
  foreach ($lineas as $l) {
    if (!(int)$l['held']) continue;
    if ($l['variant_id']) { $incVar->execute([':q' => (int)$l['quantity'], ':id' => (int)$l['variant_id']]); }
    elseif ($incProd)     { $incProd->execute([':q' => (int)$l['quantity'], ':id' => (int)$l['product_id']]); }
  }

  $pdo->prepare("DELETE FROM saved_cart_items WHERE cart_id = :id")->execute([':id' => $cid]);
  $pdo->prepare("DELETE FROM saved_carts WHERE id = :id")->execute([':id' => $cid]);
  return ['label' => (string)$cab['label'], 'lineas' => $lineas];
}

/** Carritos reservados con sus líneas y quién los reservó, más antiguos primero. */
function reservas_listar(PDO $pdo): array {
  if (!reservas_disponible($pdo)) return [];
  $tieneUsuarios = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES
                                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'")->fetchColumn();
  $sql = $tieneUsuarios
    ? "SELECT c.*, TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) AS minutos,
              COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)),''), u.nombre_usuario) AS quien
         FROM saved_carts c LEFT JOIN usuarios u ON u.id = c.user_id ORDER BY c.created_at, c.id"
    : "SELECT c.*, TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) AS minutos, NULL AS quien
         FROM saved_carts c ORDER BY c.created_at, c.id";
  $carritos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  if (!$carritos) return [];

  $porId = [];
  foreach ($carritos as $c) { $c['lineas'] = []; $porId[(int)$c['id']] = $c; }
  $in = implode(',', array_keys($porId));   // enteros propios: sin riesgo de inyección
  foreach ($pdo->query("SELECT * FROM saved_cart_items WHERE cart_id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $porId[(int)$l['cart_id']]['lineas'][] = $l;
  }
  return array_values($porId);
}

/** Cuántos carritos hay en espera (para el aviso de la navbar). */
function reservas_contar(PDO $pdo): int {
  if (!reservas_disponible($pdo)) return 0;
  return (int)$pdo->query("SELECT COUNT(*) FROM saved_carts")->fetchColumn();
}

/** Unidades apartadas por variante y por producto, para mostrarlas en Inventario. */
function reservas_apartadas(PDO $pdo): array {
  $out = ['variante' => [], 'producto' => []];
  if (!reservas_disponible($pdo)) return $out;
  foreach ($pdo->query("SELECT product_id, variant_id, SUM(quantity) q FROM saved_cart_items
                         WHERE held = 1 GROUP BY product_id, variant_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['variant_id']) { $out['variante'][(int)$r['variant_id']] = (int)$r['q']; }
    $out['producto'][(int)$r['product_id']] = ($out['producto'][(int)$r['product_id']] ?? 0) + (int)$r['q'];
  }
  return $out;
}
