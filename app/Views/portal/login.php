<div class="card auth-card">
  <h1>Connexion patient</h1>
  <form method="post" action="/p/<?= e($clinic['slug']) ?>/login">
    <?= csrf_field() ?>
    <p><label for="email">E-mail</label>
      <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autofocus maxlength="190" autocomplete="username"></p>
    <p><label for="password">Mot de passe</label>
      <input type="password" id="password" name="password" required maxlength="128" autocomplete="current-password"></p>
    <button class="btn btn-primary btn-block" type="submit">Se connecter</button>
  </form>
  <p class="muted small">Première visite ? <a href="/p/<?= e($clinic['slug']) ?>/register">Créer mon compte</a></p>
</div>
