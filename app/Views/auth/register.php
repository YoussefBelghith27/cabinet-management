<div class="card auth-card">
  <h1>Créer mon cabinet</h1>
  <p class="muted">Vous serez l’administrateur du cabinet et pourrez ensuite inviter votre équipe.</p>
  <form method="post" action="/register">
    <?= csrf_field() ?>
    <p>
      <label for="clinic_name">Nom du cabinet</label>
      <input type="text" id="clinic_name" name="clinic_name" value="<?= e(old('clinic_name')) ?>" required maxlength="150" placeholder="Cabinet Dentaire Dr. Ben Salah">
      <?= field_error('clinic_name') ?>
    </p>
    <p>
      <label for="name">Votre nom</label>
      <input type="text" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="120" autocomplete="name">
      <?= field_error('name') ?>
    </p>
    <p>
      <label for="email">Adresse e-mail</label>
      <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required maxlength="190" autocomplete="username">
      <?= field_error('email') ?>
    </p>
    <p>
      <label for="password">Mot de passe</label>
      <input type="password" id="password" name="password" required minlength="10" maxlength="128" autocomplete="new-password">
      <span class="muted small">10 caractères minimum, avec lettres et chiffres.</span>
      <?= field_error('password') ?>
    </p>
    <p>
      <label for="password_confirmation">Confirmer le mot de passe</label>
      <input type="password" id="password_confirmation" name="password_confirmation" required maxlength="128" autocomplete="new-password">
      <?= field_error('password_confirmation') ?>
    </p>
    <button class="btn btn-primary btn-block" type="submit">Créer mon cabinet</button>
  </form>
</div>
