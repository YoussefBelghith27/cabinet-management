<div class="page-head"><h1>Mon compte &amp; sécurité</h1></div>

<?php if ($recoveryCodes): ?>
  <div class="card">
    <h2>Vos codes de secours</h2>
    <p>Chaque code permet <strong>une</strong> connexion si vous perdez votre téléphone. Notez-les et rangez-les en lieu sûr : ils ne seront plus affichés.</p>
    <div class="codes"><?php foreach ($recoveryCodes as $c): ?><span><?= e($c) ?></span><?php endforeach; ?></div>
  </div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2>Double authentification (2FA)</h2>
    <?php if ($user['mfa_enabled']): ?>
      <p><span class="badge badge-paid">Activée</span> Un code de votre application vous est demandé à chaque connexion.</p>
      <?php if (!$mfaRequired): ?>
        <form method="post" action="/security/mfa/disable" data-confirm="Désactiver la double authentification ?">
          <?= csrf_field() ?>
          <p><label for="dis_pw">Mot de passe</label><input type="password" id="dis_pw" name="password" required autocomplete="current-password"></p>
          <p><label for="dis_code">Code actuel</label><input type="text" id="dis_code" name="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"></p>
          <button class="btn btn-danger" type="submit">Désactiver</button>
        </form>
      <?php else: ?>
        <p class="muted small">Obligatoire pour les administrateurs.</p>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($mfaRequired): ?><div class="flash flash-warning">Obligatoire pour votre rôle : activez-la pour accéder à l’application.</div><?php endif; ?>
      <ol class="mfa-steps">
        <li>Installez une application d’authentification sur votre téléphone (Google Authenticator, Microsoft Authenticator, Aegis…).</li>
        <li>Dans l’application, touchez <strong>+</strong> puis <strong>Scanner un QR code</strong>, et visez le code ci-dessous.</li>
        <li>Saisissez le code à 6 chiffres qui s’affiche.</li>
      </ol>
      <div class="mfa-qr"><?= $qrSvg ?></div>
      <details class="small muted">
        <summary>Impossible de scanner ? Saisir la clé à la main</summary>
        <p>Ajoutez un compte « basé sur le temps » avec cette clé :</p>
        <p class="secret"><?= e(trim(chunk_split((string) $secret, 4, ' '))) ?></p>
        <p>Lien otpauth (gestionnaire de mots de passe) : <span class="mono"><?= e($otpUri) ?></span></p>
      </details>
      <form method="post" action="/security/mfa/enable">
        <?= csrf_field() ?>
        <p><label for="code">Code à 6 chiffres</label><input type="text" id="code" name="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"></p>
        <button class="btn btn-primary" type="submit">Activer la 2FA</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Changer mon mot de passe</h2>
    <?php if ($user['must_change_password']): ?><div class="flash flash-warning">Mot de passe temporaire : choisissez-en un nouveau.</div><?php endif; ?>
    <form method="post" action="/security/password">
      <?= csrf_field() ?>
      <p><label for="current_password">Mot de passe actuel</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        <?= field_error('current_password') ?></p>
      <p><label for="password">Nouveau mot de passe</label>
        <input type="password" id="password" name="password" required minlength="10" maxlength="128" autocomplete="new-password">
        <span class="muted small">10 caractères minimum, lettres et chiffres.</span>
        <?= field_error('password') ?></p>
      <p><label for="password_confirmation">Confirmer</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required maxlength="128" autocomplete="new-password">
        <?= field_error('password_confirmation') ?></p>
      <button class="btn btn-primary" type="submit">Mettre à jour</button>
    </form>
    <p class="muted small">Changer de mot de passe ferme vos sessions ouvertes sur les autres appareils.</p>
  </div>
</div>
