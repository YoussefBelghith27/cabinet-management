<?php require BASE_PATH . '/app/Views/partials/icons.php'; ?>
<div class="login-shell">
  <div class="login-intro reveal">
    <span class="secure-pill"><?= icon('shield') ?>Accès sécurisé au cabinet</span>
    <div class="login-brand">
      <span class="brand-mark brand-mark-lg"><?= icon('tooth') ?></span>
      <strong><?= e(env('APP_NAME', 'GestionCabinet')) ?></strong>
    </div>
    <p class="muted">Espace professionnel des dentistes et secrétaires.</p>
  </div>

  <div class="card login-card reveal">
    <h1 class="login-title">Connexion</h1>
    <form method="post" action="/login" autocomplete="on">
      <?= csrf_field() ?>
      <label for="email">Adresse e-mail</label>
      <div class="input-icon">
        <?= icon('mail') ?>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autofocus maxlength="190" autocomplete="username" placeholder="vous@cabinet.tn">
      </div>
      <label for="password">Mot de passe</label>
      <div class="input-icon">
        <?= icon('lock') ?>
        <input type="password" id="password" name="password" required maxlength="128" autocomplete="current-password">
      </div>
      <button class="btn btn-primary btn-block login-submit" type="submit">Se connecter</button>
    </form>
    <?php if (registration_open()): ?>
    <p class="muted small login-alt">Pas encore de compte ? <a href="/register">Créer mon cabinet</a></p>
    <?php endif; ?>
  </div>

  <ul class="trust-row reveal" aria-label="Sécurité">
    <li><?= icon('shield') ?>Double authentification</li>
    <li><?= icon('lock') ?>Connexions chiffrées</li>
    <li><?= icon('check') ?>Données hébergées en Europe</li>
  </ul>
</div>
