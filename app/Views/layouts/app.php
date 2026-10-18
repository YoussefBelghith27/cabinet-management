<?php
use App\Core\Auth;
use App\Core\Session;

$me = Auth::user();
$flashes = Session::takeFlash();
$role = $me['role'] ?? '';
$space = ['admin' => 'Espace administrateur', 'dentist' => 'Espace praticien', 'secretary' => 'Espace secrétariat'][$role] ?? '';
$navItem = fn (string $href, string $iconName, string $label) => '<a href="' . e($href) . '"' . nav_active($href) . '>' . icon($iconName) . '<span>' . e($label) . '</span></a>';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e(($title ?? '') . ' · ' . ($me['clinic_name'] ?? env('APP_NAME', 'GestionCabinet'))) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app">
  <?php require BASE_PATH . '/app/Views/partials/icons.php'; ?>
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="/dashboard">
      <span class="brand-mark"><?= icon('tooth') ?></span>
      <span class="brand-text">
        <strong class="brand-name"><?= e($me['clinic_name'] ?? '') ?></strong>
        <small><?= e(env('APP_NAME', 'GestionCabinet')) ?></small>
      </span>
    </a>
    <div class="nav-section"><?= e($space) ?></div>
    <nav class="nav" aria-label="Navigation principale">
      <?= $navItem('/dashboard', 'grid', 'Tableau de bord') ?>
      <?= $navItem('/appointments', 'calendar', 'Agenda') ?>
      <?= $navItem('/patients', 'users', 'Patients') ?>
      <?php if (in_array($role, ['admin', 'secretary'], true)): ?>
        <?= $navItem('/invoices', 'receipt', 'Facturation') ?>
      <?php endif; ?>
      <?php if ($role === 'admin'): ?>
        <div class="nav-section">Administration</div>
        <?= $navItem('/treatments', 'clipboard', 'Actes & tarifs') ?>
        <?= $navItem('/staff', 'team', 'Équipe') ?>
        <?= $navItem('/settings', 'settings', 'Paramètres') ?>
        <?= $navItem('/security/events', 'shield', 'Journal de sécurité') ?>
      <?php endif; ?>
    </nav>
    <div class="user-card">
      <a href="/security" class="user-link">
        <span class="avatar"><?= e(initials($me['name'] ?? '')) ?></span>
        <span class="user-meta">
          <strong><?= e($me['name'] ?? '') ?></strong>
          <small><?= e(role_label($role)) ?><?php if (!empty($me['mfa_enabled'])): ?> <span class="chip-2fa">2FA</span><?php endif; ?></small>
        </span>
      </a>
      <form method="post" action="/logout">
        <?= csrf_field() ?>
        <button class="icon-btn" type="submit" title="Déconnexion" aria-label="Déconnexion"><?= icon('logout') ?></button>
      </form>
    </div>
  </aside>

  <div class="main-wrap">
    <header class="topbar">
      <button class="icon-btn menu-toggle" type="button" data-toggle="sidebar" aria-label="Ouvrir le menu"><?= icon('menu') ?></button>
      <form class="topbar-search" method="get" action="/patients" role="search">
        <?= icon('search') ?>
        <input type="search" name="q" placeholder="Rechercher un patient (nom, téléphone, e-mail)" aria-label="Rechercher un patient" maxlength="80">
      </form>
      <span class="date-pill topbar-end"><?= icon('calendar') ?><?= e(ucfirst(fr_date())) ?></span>
    </header>
    <main class="main">
      <?php foreach ($flashes as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
  <script src="/assets/app.js"></script>
</body>
</html>
