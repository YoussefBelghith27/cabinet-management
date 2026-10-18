<div class="card auth-card">
  <h1>Créer mon compte patient</h1>
  <p class="muted">Pour prendre rendez-vous chez <?= e($clinic['name']) ?>.</p>
  <form method="post" action="/p/<?= e($clinic['slug']) ?>/register">
    <?= csrf_field() ?>
    <p><label for="last_name">Nom</label>
      <input type="text" id="last_name" name="last_name" value="<?= e(old('last_name')) ?>" required maxlength="80" autocomplete="family-name"><?= field_error('last_name') ?></p>
    <p><label for="first_name">Prénom</label>
      <input type="text" id="first_name" name="first_name" value="<?= e(old('first_name')) ?>" required maxlength="80" autocomplete="given-name"><?= field_error('first_name') ?></p>
    <p><label for="birth_date">Date de naissance</label>
      <input type="date" id="birth_date" name="birth_date" value="<?= e(old('birth_date')) ?>" required max="<?= date('Y-m-d') ?>" autocomplete="bday"><?= field_error('birth_date') ?></p>
    <p><label for="phone">Téléphone</label>
      <input type="tel" id="phone" name="phone" value="<?= e(old('phone')) ?>" required maxlength="20" autocomplete="tel"><?= field_error('phone') ?></p>
    <p><label for="email">E-mail</label>
      <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required maxlength="190" autocomplete="email"><?= field_error('email') ?></p>
    <p><label for="password">Mot de passe</label>
      <input type="password" id="password" name="password" required minlength="10" maxlength="128" autocomplete="new-password">
      <span class="muted small">10 caractères minimum, lettres et chiffres.</span><?= field_error('password') ?></p>
    <p><label for="password_confirmation">Confirmer le mot de passe</label>
      <input type="password" id="password_confirmation" name="password_confirmation" required maxlength="128" autocomplete="new-password"><?= field_error('password_confirmation') ?></p>
    <button class="btn btn-primary btn-block" type="submit">Créer mon compte</button>
  </form>
</div>
