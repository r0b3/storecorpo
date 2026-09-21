<?php
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../views/main.php';
require_once __DIR__ . '/_gate_private.php';

if (session_status() === PHP_SESSION_NONE) @session_start();
$total = $_SESSION['last_order_total'] ?? 0;
$order_id = $_SESSION['last_order_id'] ?? null;
unset($_SESSION['last_order_total'], $_SESSION['last_order_id']);

$content = '<div class="alert alert-success">'
  . '<h4 class="alert-heading">¡Gracias por tu compra!</h4>'
  . '<p>Tu pedido fue registrado exitosamente.</p>'
  . ($order_id ? '<p><strong>Número de orden:</strong> #'.(int)$order_id.'</p>' : '')
  . '<hr><p class="mb-0">Total: $'.money($total).'</p></div>'
  . '<a class="btn btn-primary" href="'.url('index.php').'">Volver al catálogo</a>';

render('Pedido confirmado', __DIR__ . '/../views/pages/_blank.php', [
  'content' => $content,
  'cats'    => [],
]);
