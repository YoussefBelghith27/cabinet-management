<div class="card auth-card center">
  <h1><?= (int) $status ?></h1>
  <p><?= e($message) ?></p>
  <?php if (!empty($ref)): ?>
    <p class="muted small">Référence de l’erreur : <span class="mono"><?= e($ref) ?></span></p>
  <?php endif; ?>
  <a class="btn btn-primary" href="/">Retour à l’accueil</a>
</div>
