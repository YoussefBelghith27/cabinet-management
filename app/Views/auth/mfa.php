<div class="card auth-card">
  <h1>Vérification en deux étapes</h1>
  <p class="muted">Saisissez le code à 6 chiffres affiché dans votre application d’authentification, ou un code de secours.</p>
  <form method="post" action="/mfa" autocomplete="off">
    <?= csrf_field() ?>
    <p>
      <label for="code">Code</label>
      <input type="text" id="code" name="code" inputmode="numeric" required autofocus maxlength="11" autocomplete="one-time-code">
    </p>
    <button class="btn btn-primary btn-block" type="submit">Vérifier</button>
  </form>
</div>
