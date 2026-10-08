<?php
// lib/variantes.php — Opciones de variante sin límite fijo.
//
// Las dos primeras opciones de una variante siguen viviendo en las columnas
// option1_name/value y option2_name/value de product_variants (así no hubo que
// migrar nada y lo que ya lee esas columnas sigue funcionando). De la tercera
// en adelante van en product_variant_options (variant_id, position, name,
// value). Cada posición tiene UNA sola fuente: columnas para 1-2, tabla para 3+.

/** ¿Existe la tabla de opciones extra? (cacheado por petición) */
function variantes_extra_disponible(PDO $pdo): bool {
  static $hay = null;
  if ($hay === null) {
    $st = $pdo->query("SELECT 1 FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_variant_options' LIMIT 1");
    $hay = (bool)$st->fetchColumn();
  }
  return $hay;
}

/**
 * Opciones de cada variante, en orden: [variant_id => [['n'=>nombre,'v'=>valor], …]].
 * Recibe las filas de product_variants ya leídas (con option1/2_*) y solo
 * consulta la tabla extra para completar.
 */
function variantes_opciones(PDO $pdo, array $variantes): array {
  $out = [];
  foreach ($variantes as $v) {
    $lista = [];
    foreach ([1, 2] as $n) {
      $nom = trim((string)($v["option{$n}_name"] ?? ''));
      $val = trim((string)($v["option{$n}_value"] ?? ''));
      if ($nom !== '' || $val !== '') { $lista[] = ['n' => $nom, 'v' => $val]; }
    }
    $out[(int)$v['id']] = $lista;
  }
  if ($out && variantes_extra_disponible($pdo)) {
    $ids = array_keys($out);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT variant_id, name, value FROM product_variant_options
                           WHERE variant_id IN ($in) ORDER BY variant_id, position, id");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $out[(int)$r['variant_id']][] = ['n' => (string)$r['name'], 'v' => (string)$r['value']];
    }
  }
  return $out;
}

/** "Talla: M / Color: Azul / Manga: Larga" */
function variante_etiqueta(array $opciones): string {
  $partes = [];
  foreach ($opciones as $o) {
    $t = ($o['n'] !== '' ? $o['n'] . ': ' : '') . $o['v'];
    if (trim($t) !== '') { $partes[] = $t; }
  }
  return implode(' / ', $partes);
}

/**
 * Pares nombre/valor enviados por el formulario (opt_name[] / opt_value[]).
 * Descarta filas vacías; un nombre sin valor (o al revés) es un error, porque
 * una opción a medias no se puede elegir en el catálogo.
 */
function variantes_opciones_del_post(): array {
  $noms = (array)($_POST['opt_name'] ?? []);
  $vals = (array)($_POST['opt_value'] ?? []);
  $pares = []; $vistos = [];
  foreach ($noms as $i => $nom) {
    $nom = mb_substr(trim((string)$nom), 0, 60);
    $val = mb_substr(trim((string)($vals[$i] ?? '')), 0, 120);
    if ($nom === '' && $val === '') continue;
    if ($nom === '' || $val === '') {
      throw new RuntimeException('Cada opción necesita nombre y valor (ej. Talla = M).');
    }
    $clave = mb_strtolower($nom);
    if (isset($vistos[$clave])) {
      throw new RuntimeException('La opción "' . $nom . '" está repetida en la misma variante.');
    }
    $vistos[$clave] = true;
    $pares[] = ['n' => $nom, 'v' => $val];
  }
  return $pares;
}

/** Reescribe las opciones 3+ de una variante (las 1-2 van en sus columnas). */
function variantes_guardar_extra(PDO $pdo, int $vid, array $pares): void {
  $extra = array_slice($pares, 2);
  if (!variantes_extra_disponible($pdo)) {
    if ($extra) { throw new RuntimeException('Esta instalación solo admite dos opciones por variante.'); }
    return;
  }
  $pdo->prepare("DELETE FROM product_variant_options WHERE variant_id = :v")->execute([':v' => $vid]);
  $ins = $pdo->prepare("INSERT INTO product_variant_options (variant_id, position, name, value)
                        VALUES (:v, :p, :n, :val)");
  foreach ($extra as $i => $o) {
    $ins->execute([':v' => $vid, ':p' => $i + 3, ':n' => $o['n'], ':val' => $o['v']]);
  }
}
