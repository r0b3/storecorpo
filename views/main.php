<?php
// views/main.php — Layout principal
if (!function_exists('render')) {
  function render(string $title, string $contentFile, array $data = []): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    extract($data, EXTR_SKIP);
    $title = $title;
    include __DIR__ . '/partials/header.php';
    include __DIR__ . '/partials/navbar.php';
    echo '<main class="container">';
    include $contentFile;
    echo '</main>';
    include __DIR__ . '/partials/footer.php';
  }
}
