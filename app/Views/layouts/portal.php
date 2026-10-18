<?php
use App\Core\Auth;
use App\Core\Session;

$me = Auth::user();
$flashes = Session::takeFlash();
$isPatient = $me && $me['role'] === 'patient' && (int) $me['clinic_id'] === (int) $clinic['id'];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e(($title ?? '') . ' · ' . $clinic['name']) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="public portal">
  <header class="public-header">
    <a href="/p/<?= e($clinic['slug']) ?>" class="brand"><span class="brand-mark" aria-hidden="true">◗</span> <?= e($clinic['name']) ?></a>
    <nav>
      <?php if ($isPatient): ?>
        <span class="muted small"><?= e($me['name']) ?></span>
        <form method="post" action="/logout" class="inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Déconnexion</button></form>
      <?php else: ?>
        <a href="/p/<?= e($clinic['slug']) ?>/login">Connexion</a>
        <a class="btn btn-primary btn-sm" href="/p/<?= e($clinic['slug']) ?>/register">Prendre rendez-vous</a>
      <?php endif; ?>
    </nav>
  </header>
  <?php if ($isPatient): $p = '/p/' . $clinic['slug']; ?>
    <nav class="portal-tabs" aria-label="Mon espace patient">
      <a href="<?= e($p) ?>/account"<?= nav_active("$p/account") ?: nav_active("$p/appointments") ?>>Mes rendez-vous</a>
      <a href="<?= e($p) ?>/care"<?= nav_active("$p/care") ?>>Mes soins</a>
      <a href="<?= e($p) ?>/invoices"<?= nav_active("$p/invoices") ?>>Mes factures</a>
      <a href="<?= e($p) ?>/profile"<?= nav_active("$p/profile") ?>>Mon profil</a>
    </nav>
  <?php endif; ?>
  <main class="public-main">
    <?php foreach ($flashes as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
  <script src="/assets/app.js"></script>
</body>
</html>
