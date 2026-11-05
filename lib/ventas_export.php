<?php
// lib/ventas_export.php — Arma el Excel de la vista de Ventas. Lo llama
// public/admin_sales.php, que ya aplicó rol y filtros, y del que usa pick(),
// METODOS_PAGO y ESTADOS_VENTA.
require_once __DIR__ . '/xlsx.php';

/** Hojas: Ventas (una fila por venta), Detalle (una por producto) y Resumen. */
function ventas_hojas(array $orders, array $itemsByOrder, ?string $dateCol, array $F, array $sellers): array {
  $filasVentas = []; $filasDetalle = [];
  $porEstado = [];
  foreach (array_keys(ESTADOS_VENTA) as $k) { $porEstado[$k] = ['n' => 0, 'u' => 0, 't' => 0.0]; }

  foreach ($orders as $o) {
    $oid    = $o['id'] ?? null;
    $fecha  = pick($o, [$dateCol ?: 'created_at', 'order_date', 'fecha', 'created', 'date']);
    $status = strtolower((string)($o['status'] ?? ''));
    $estado = ESTADOS_VENTA[$status] ?? ucfirst($status);
    $metodo = (string)pick($o, ['payment_method', 'metodo_pago', 'pago'], '');
    $metodo = METODOS_PAGO[strtolower($metodo)] ?? ucfirst($metodo);
    $tipo   = ucfirst((string)pick($o, ['customer_type', 'buyer_type', 'tipo_cliente', 'tipo'], ''));

    $empresa = pick($o, ['company_name', 'empresa', 'business_name']);
    $cliente = $empresa ?: pick($o, ['person_name', 'full_name', 'customer_name', 'nombre'], '');
    $doc     = $empresa ? pick($o, ['company_nit', 'nit', 'tax_id'], '')
                        : pick($o, ['person_id', 'cedula', 'dni', 'document', 'doc_id'], '');

    $vendedor = ($o['seller_full_name'] ?? '') ?: ($o['seller_username'] ?? '');
    if ($vendedor === '' && !empty($o['user_id'])) { $vendedor = 'ID ' . $o['user_id']; }

    $subtotal = (float)pick($o, ['subtotal', 'subtotal_amount', 'sub_total'], 0);
    $tax      = (float)pick($o, ['tax', 'tax_amount', 'iva', 'impuesto'], 0);
    $total    = (float)pick($o, ['total', 'total_amount', 'grand_total'], $subtotal + $tax);

    $resumen = []; $unidades = 0;
    foreach ($itemsByOrder[$oid] ?? [] as $it) {
      $nombre  = (string)pick($it, ['product_name', 'name', 'item_name'], '');
      $variant = (string)pick($it, ['variant_label', 'variante'], '');
      $qty     = (int)pick($it, ['qty', 'quantity', 'cantidad'], 0);
      $unit    = (float)pick($it, ['unit_price', 'price', 'precio'], 0);
      $unidades += $qty;
      $resumen[] = $qty . ' × ' . $nombre . ($variant !== '' ? " ({$variant})" : '');
      $filasDetalle[] = [
        (int)$oid, $fecha, $estado, $metodo, $cliente, $vendedor,
        $nombre, $variant, (string)pick($it, ['sku'], ''), $qty, $unit,
        (float)pick($it, ['line_total', 'subtotal', 'total_line'], $qty * $unit),
      ];
    }

    $filasVentas[] = [
      (int)$oid, $fecha, $estado, $metodo, $tipo, $cliente, (string)$doc,
      (string)pick($o, ['customer_email', 'email', 'correo'], ''),
      (string)pick($o, ['customer_phone', 'phone', 'telefono', 'tel'], ''),
      $vendedor, implode('; ', $resumen), $unidades, $subtotal, $tax, $total,
      $o['paid_at'] ?? null, (string)($o['paid_ref'] ?? ''), (string)pick($o, ['payment_ref'], ''),
      (string)($o['gift_note'] ?? ''), (string)($o['notas'] ?? ''),
    ];

    if (isset($porEstado[$status])) {
      $porEstado[$status]['n']++;
      $porEstado[$status]['u'] += $unidades;
      $porEstado[$status]['t'] += $total;
    }
  }

  // Resumen: mismos criterios del Consolidado (anuladas y obsequios no suman).
  $netoN = $porEstado['pending']['n'] + $porEstado['paid']['n'];
  $netoU = $porEstado['pending']['u'] + $porEstado['paid']['u'];
  $netoT = $porEstado['pending']['t'] + $porEstado['paid']['t'];
  $filasResumen = [
    ['Ventas netas (pagadas + pendientes)', $netoN, $netoU, $netoT, '_negrita' => true],
    ['Pagadas', $porEstado['paid']['n'], $porEstado['paid']['u'], $porEstado['paid']['t']],
    ['Pendientes (por cobrar)', $porEstado['pending']['n'], $porEstado['pending']['u'], $porEstado['pending']['t']],
    ['Obsequios (no suman)', $porEstado['gift']['n'], $porEstado['gift']['u'], $porEstado['gift']['t']],
    ['Anuladas (no suman)', $porEstado['cancelled']['n'], $porEstado['cancelled']['u'], $porEstado['cancelled']['t']],
  ];

  $ahora = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));

  $desc = [];
  if (!empty($F['from'])) { $desc[] = 'Desde ' . $F['from']; }
  if (!empty($F['to']))   { $desc[] = 'Hasta ' . $F['to']; }
  if (!empty($F['st']))   { $desc[] = 'Estado: ' . ESTADOS_VENTA[$F['st']]; }
  if (!empty($F['pm']))   { $desc[] = 'Método al vender: ' . METODOS_PAGO[$F['pm']]; }
  if (!empty($F['bt']))   { $desc[] = 'Cliente: ' . ucfirst($F['bt']); }
  if (!empty($F['q']))    { $desc[] = 'Búsqueda: ' . $F['q']; }
  if (!empty($F['seller_id'])) {
    $nombreV = 'ID ' . $F['seller_id'];
    foreach ($sellers as $s) { if ((string)$s['id'] === (string)$F['seller_id']) { $nombreV = $s['label']; break; } }
    $desc[] = 'Vendedor: ' . $nombreV;
  }

  return [
    [
      'nombre' => 'Ventas', 'tabla' => true, 'filas' => $filasVentas,
      'columnas' => [
        ['t' => 'N.° venta', 'tipo' => 'entero', 'ancho' => 10],
        ['t' => 'Fecha', 'tipo' => 'fecha', 'ancho' => 17],
        ['t' => 'Estado', 'ancho' => 11],
        ['t' => 'Método al vender', 'ancho' => 16],
        ['t' => 'Tipo de cliente', 'ancho' => 14],
        ['t' => 'Cliente', 'ancho' => 30],
        ['t' => 'Cédula / NIT', 'ancho' => 15],
        ['t' => 'Email', 'ancho' => 26],
        ['t' => 'Teléfono', 'ancho' => 14],
        ['t' => 'Vendedor', 'ancho' => 28],
        ['t' => 'Productos', 'ancho' => 50],
        ['t' => 'Unidades', 'tipo' => 'entero', 'ancho' => 10],
        ['t' => 'Subtotal', 'tipo' => 'moneda', 'ancho' => 13],
        ['t' => 'Impuesto', 'tipo' => 'moneda', 'ancho' => 11],
        ['t' => 'Total', 'tipo' => 'moneda', 'ancho' => 13],
        ['t' => 'Fecha de cobro', 'tipo' => 'fecha', 'ancho' => 17],
        ['t' => 'N.° comprobante', 'ancho' => 17],
        ['t' => 'Ref. de pago', 'ancho' => 15],
        ['t' => 'Motivo del obsequio', 'ancho' => 26],
        ['t' => 'Observación', 'ancho' => 40],
      ],
    ],
    [
      'nombre' => 'Detalle', 'tabla' => true, 'filas' => $filasDetalle,
      'columnas' => [
        ['t' => 'N.° venta', 'tipo' => 'entero', 'ancho' => 10],
        ['t' => 'Fecha', 'tipo' => 'fecha', 'ancho' => 17],
        ['t' => 'Estado', 'ancho' => 11],
        ['t' => 'Método al vender', 'ancho' => 16],
        ['t' => 'Cliente', 'ancho' => 30],
        ['t' => 'Vendedor', 'ancho' => 28],
        ['t' => 'Producto', 'ancho' => 30],
        ['t' => 'Variante', 'ancho' => 34],
        ['t' => 'SKU', 'ancho' => 14],
        ['t' => 'Cantidad', 'tipo' => 'entero', 'ancho' => 10],
        ['t' => 'Unitario', 'tipo' => 'moneda', 'ancho' => 13],
        ['t' => 'Subtotal', 'tipo' => 'moneda', 'ancho' => 13],
      ],
    ],
    [
      'nombre' => 'Resumen', 'filas' => $filasResumen,
      'notas' => [
        'Resumen de ventas',
        'Generado el ' . $ahora->format('Y-m-d H:i'),
        'Filtros: ' . ($desc ? implode(' · ', $desc) : 'ninguno (todas las ventas)'),
        'La columna Total de la hoja Ventas incluye anuladas y obsequios: filtra por Estado antes de sumarla.',
      ],
      'columnas' => [
        ['t' => 'Concepto', 'ancho' => 38],
        ['t' => 'Ventas', 'tipo' => 'entero', 'ancho' => 10],
        ['t' => 'Unidades', 'tipo' => 'entero', 'ancho' => 10],
        ['t' => 'Total', 'tipo' => 'moneda', 'ancho' => 15],
      ],
    ],
  ];
}

/** Genera el archivo y lo envía como descarga. Termina la petición si todo sale bien. */
function ventas_descargar_xlsx(array $hojas): void {
  $tmp = tempnam(sys_get_temp_dir(), 'ventas_');
  if ($tmp === false) { throw new RuntimeException('No se pudo crear el archivo temporal.'); }
  try {
    xlsx_guardar($hojas, $tmp);
    $hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ventas_' . $hoy . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    // Lleva datos de clientes: que no quede en cachés intermedias.
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
  } finally {
    @unlink($tmp);
  }
  exit;
}
