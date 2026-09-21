<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tienda Stand 2025</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg bg-dark navbar-dark mb-4">
  <div class="container">
<a class="navbar-brand" href="<?= url('index.php') ?>">Tienda</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <form class="d-flex ms-auto" role="search" method="get" action="/index.php">
        <input class="form-control me-2" type="search" placeholder="Buscar" name="q" value="<?= isset($_GET['q'])?e($_GET['q']):'' ?>">
        <button class="btn btn-outline-light" type="submit">Buscar</button>
      </form>
      <a class="btn btn-warning ms-3" href="<?= url('cart.php') ?>">
        🛒 Carrito (<?= array_sum(array_column($_SESSION['cart'] ?? [], 'qty')) ?: 0 ?>)
      </a>
    </div>
  </div>
</nav>
<div class="container mb-5">
