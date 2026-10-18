<?php
use App\Core\Session;

$flashes = Session::takeFlash();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($title ?? '') . ' · ' . env('APP_NAME', 'GestionCabinet')) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="public">
  <header class="public-header">
    <a href="/" class="brand"><span class="brand-mark" aria-hidden="true">◗</span> <?= e(env('APP_NAME', 'GestionCabinet')) ?></a>
    <nav><a href="/login">Connexion</a> <?php if (registration_open()): ?><a class="btn btn-primary btn-sm" href="/register">Créer mon cabinet</a><?php endif; ?></nav>
  </header>
  <main class="public-main">
    <?php foreach ($flashes as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
  <script src="/assets/app.js"></script>
</body>
</html>
