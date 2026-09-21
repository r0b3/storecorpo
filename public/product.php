<?php
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../views/main.php';
require __DIR__ . '/_gate_private.php'; // ← añade esta línea

$pdo = get_pdo();

$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("SELECT p.*, c.name AS category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.slug = :slug");
$stmt->execute([':slug'=>$slug]);
$p = $stmt->fetch();

if (!$p) { http_response_code(404); exit('Producto no encontrado'); }

$variants = $pdo->prepare("SELECT * FROM product_variants WHERE product_id=:pid AND active=1 ORDER BY option1_value, option2_value");
$variants->execute([':pid'=>$p['id']]);
$variants = $variants->fetchAll();

// Construir mapas de opciones
$opt1_name = null; $opt2_name = null;
$opt1_values = []; $opt2_values = [];
foreach ($variants as $v) {
  if ($v['option1_name']) { $opt1_name = $v['option1_name']; $opt1_values[$v['option1_value']] = true; }
  if ($v['option2_name']) { $opt2_name = $v['option2_name']; $opt2_values[$v['option2_value']] = true; }
}
$opt1_values = array_keys($opt1_values);
$opt2_values = array_keys($opt2_values);

$cats = $pdo->query("SELECT name, slug FROM categories ORDER BY name")->fetchAll();
render('Tienda - ' . $p['name'], __DIR__ . '/../views/pages/product.php', compact('p','cats','variants','opt1_name','opt2_name','opt1_values','opt2_values'));
