<?php
// lib/xlsx.php — Generador mínimo de .xlsx con ZipArchive (el proyecto no usa
// Composer, así que no hay PhpSpreadsheet). Un .xlsx es un zip de XML: esto
// escribe solo lo necesario para que abra limpio en Excel, LibreOffice y Sheets.
//
// Los textos van como cadena en línea (inlineStr), nunca como fórmula: un valor
// que empiece por "=" se muestra tal cual y no se ejecuta, y un documento como
// 0000088500 conserva sus ceros.

// Índices de cellXfs en xlsx_estilos(). Cambiar uno exige cambiar el otro.
const XLSX_TEXTO = 0, XLSX_ENCABEZADO = 1, XLSX_ENTERO = 2, XLSX_MONEDA = 3, XLSX_FECHA = 4,
      XLSX_TITULO = 5, XLSX_NOTA = 6, XLSX_B_TEXTO = 7, XLSX_B_ENTERO = 8, XLSX_B_MONEDA = 9;

function xlsx_columna(int $n): string {
  $s = '';
  for (; $n > 0; $n = intdiv($n - 1, 26)) { $s = chr(65 + ($n - 1) % 26) . $s; }
  return $s;
}

/** Texto apto para XML: quita los caracteres de control, que invalidan el archivo. */
function xlsx_escapar(string $s): string {
  $s = mb_scrub($s, 'UTF-8');
  $s = preg_replace('/[^\x{09}\x{0A}\x{0D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
  return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** 'Y-m-d H:i:s' → número de serie de Excel. La hora se escribe tal como viene. */
function xlsx_fecha(?string $s): ?float {
  $s = trim((string)$s);
  if ($s === '') return null;
  $utc = new DateTimeZone('UTC');
  $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($s, 0, 19), $utc)
    ?: DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10), $utc);
  return $d ? $d->getTimestamp() / 86400 + 25569 : null;
}

function xlsx_celda(string $ref, $valor, string $tipo, bool $negrita): string {
  if ($valor === null || $valor === '') return '';
  switch ($tipo) {
    case 'entero': $s = $negrita ? XLSX_B_ENTERO : XLSX_ENTERO; break;
    case 'moneda': $s = $negrita ? XLSX_B_MONEDA : XLSX_MONEDA; break;
    case 'fecha':
      $serie = xlsx_fecha((string)$valor);
      if ($serie === null) { $tipo = 'texto'; $s = XLSX_TEXTO; break; }
      return '<c r="' . $ref . '" s="' . XLSX_FECHA . '"><v>' . $serie . '</v></c>';
    default: $s = $negrita ? XLSX_B_TEXTO : XLSX_TEXTO;
  }
  if ($tipo === 'entero' || $tipo === 'moneda') {
    return '<c r="' . $ref . '" s="' . $s . '"><v>' . (float)$valor . '</v></c>';
  }
  return '<c r="' . $ref . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">'
       . xlsx_escapar((string)$valor) . '</t></is></c>';
}

/**
 * Una hoja: ['nombre', 'columnas' => [['t'=>título,'tipo'=>texto|entero|moneda|fecha,'ancho'=>n]],
 *            'filas' => [[valores…]], 'notas' => [título, nota…], 'tabla' => bool].
 * Una fila con la clave '_negrita' se escribe resaltada. 'tabla' agrega
 * autofiltro y encabezado fijo.
 */
function xlsx_hoja(array $h, bool $primera): string {
  $cols = $h['columnas'];
  $nCols = count($cols);
  $filasXml = '';
  $r = 0;

  foreach ($h['notas'] ?? [] as $i => $nota) {
    $r++;
    $estilo = $i === 0 ? XLSX_TITULO : XLSX_NOTA;
    $filasXml .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr" s="' . $estilo . '"><is><t xml:space="preserve">'
               . xlsx_escapar((string)$nota) . '</t></is></c></row>';
  }
  if ($r > 0) { $r++; }   // renglón en blanco antes de la tabla

  $r++;
  $filaEnc = $r;
  $celdas = '';
  foreach ($cols as $i => $c) {
    $celdas .= '<c r="' . xlsx_columna($i + 1) . $r . '" t="inlineStr" s="' . XLSX_ENCABEZADO . '"><is><t>'
             . xlsx_escapar($c['t']) . '</t></is></c>';
  }
  $filasXml .= '<row r="' . $r . '">' . $celdas . '</row>';

  foreach ($h['filas'] as $fila) {
    $r++;
    $negrita = !empty($fila['_negrita']);
    unset($fila['_negrita']);
    $celdas = '';
    foreach (array_values($fila) as $i => $v) {
      $celdas .= xlsx_celda(xlsx_columna($i + 1) . $r, $v, $cols[$i]['tipo'] ?? 'texto', $negrita);
    }
    $filasXml .= '<row r="' . $r . '">' . $celdas . '</row>';
  }

  $tabla = !empty($h['tabla']);
  $vista = $primera ? ' tabSelected="1"' : '';
  $panel = $tabla
    ? '<pane ySplit="' . $filaEnc . '" topLeftCell="A' . ($filaEnc + 1) . '" activePane="bottomLeft" state="frozen"/>'
    : '';
  $anchos = '';
  foreach ($cols as $i => $c) {
    $n = $i + 1;
    $anchos .= '<col min="' . $n . '" max="' . $n . '" width="' . (float)($c['ancho'] ?? 14) . '" customWidth="1"/>';
  }
  $ultima = xlsx_columna($nCols);
  $filtro = $tabla ? '<autoFilter ref="A' . $filaEnc . ':' . $ultima . $r . '"/>' : '';

  return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<dimension ref="A1:' . $ultima . $r . '"/>'
    . '<sheetViews><sheetView workbookViewId="0"' . $vista . '>' . $panel . '</sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="15"/>'
    . '<cols>' . $anchos . '</cols>'
    . '<sheetData>' . $filasXml . '</sheetData>'
    . $filtro
    . '</worksheet>';
}

function xlsx_estilos(): string {
  return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="2">'
    . '<numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0"/>'
    . '<numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm"/>'
    . '</numFmts>'
    . '<fonts count="5">'
    . '<font><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
    . '<font><sz val="10"/><color rgb="FF595959"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="3">'
    . '<fill><patternFill patternType="none"/></fill>'
    . '<fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF343A40"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="10">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
    . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';
}

/** Escribe el libro en $ruta. Lanza RuntimeException si no se puede. */
function xlsx_guardar(array $hojas, string $ruta): void {
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException('El servidor no tiene la extensión zip de PHP.');
  }
  $zip = new ZipArchive();
  if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('No se pudo crear el archivo de Excel.');
  }

  $tipos = ''; $libro = ''; $rels = '';
  foreach (array_values($hojas) as $i => $h) {
    $n = $i + 1;
    $zip->addFromString("xl/worksheets/sheet{$n}.xml", xlsx_hoja($h, $i === 0));
    $tipos .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $libro .= '<sheet name="' . xlsx_escapar(mb_substr($h['nombre'], 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
    $rels  .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
  }
  $idEstilos = count($hojas) + 1;
  $cab = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

  $zip->addFromString('[Content_Types].xml', $cab
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . $tipos . '</Types>');
  $zip->addFromString('_rels/.rels', $cab
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>');
  $zip->addFromString('xl/workbook.xml', $cab
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<bookViews><workbookView activeTab="0"/></bookViews>'
    . '<sheets>' . $libro . '</sheets></workbook>');
  $zip->addFromString('xl/_rels/workbook.xml.rels', $cab
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
    . '<Relationship Id="rId' . $idEstilos . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>');
  $zip->addFromString('xl/styles.xml', xlsx_estilos());

  if (!$zip->close()) {
    throw new RuntimeException('No se pudo escribir el archivo de Excel.');
  }
}
