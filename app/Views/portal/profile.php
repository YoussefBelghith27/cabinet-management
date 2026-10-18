<?php
$base = '/p/' . $clinic['slug'];
$val = fn (string $k) => old($k, (string) ($patient[$k] ?? ''));
?>
<div class="page-head"><h1>Mon profil</h1></div>

<div class="grid grid-2">
  <div class="card">
    <h2>Mes coordonnées</h2>
    <p class="muted small"><?= e($patient['last_name'] . ' ' . $patient['first_name']) ?><?= $patient['birth_date'] ? ', né(e) le ' . e(fmt_date($patient['birth_date'])) : '' ?>. Pour corriger votre nom ou votre date de naissance, adressez-vous au cabinet.</p>
    <form method="post" action="<?= e($base) ?>/profile">
      <?= csrf_field() ?>
      <p><label for="phone">Téléphone</label>
        <input type="tel" id="phone" name="phone" value="<?= e($val('phone')) ?>" required maxlength="20" autocomplete="tel"><?= field_error('phone') ?></p>
      <p><label for="email">E-mail (sert aussi à vous connecter)</label>
        <input type="email" id="email" name="email" value="<?= e(old('email', (string) $user['email'])) ?>" required maxlength="190" autocomplete="email"><?= field_error('email') ?></p>
      <p><label for="address">Adresse</label>
        <input type="text" id="address" name="address" value="<?= e($val('address')) ?>" maxlength="255" autocomplete="street-address"><?= field_error('address') ?></p>
      <p><label for="profile_current_password">Mot de passe actuel <span class="muted small">(seulement si vous changez d’e-mail)</span></label>
        <input type="password" id="profile_current_password" name="current_password" maxlength="128" autocomplete="current-password"><?= field_error('current_password') ?></p>
      <button class="btn btn-primary" type="submit">Enregistrer mes coordonnées</button>
    </form>
  </div>

  <div class="card">
    <h2>Changer mon mot de passe</h2>
    <form method="post" action="<?= e($base) ?>/password">
      <?= csrf_field() ?>
      <p><label for="current_password">Mot de passe actuel</label>
        <input type="password" id="current_password" name="current_password" required maxlength="128" autocomplete="current-password"></p>
      <p><label for="password">Nouveau mot de passe</label>
        <input type="password" id="password" name="password" required minlength="10" maxlength="128" autocomplete="new-password">
        <span class="muted small">10 caractères minimum, lettres et chiffres.</span><?= field_error('password') ?></p>
      <p><label for="password_confirmation">Confirmer</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required maxlength="128" autocomplete="new-password"><?= field_error('password_confirmation') ?></p>
      <button class="btn btn-primary" type="submit">Changer le mot de passe</button>
    </form>
    <p class="muted small">Changer de mot de passe ferme vos sessions ouvertes sur les autres appareils.</p>
  </div>
</div>
