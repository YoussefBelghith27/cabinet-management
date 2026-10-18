<?php $val = fn (string $k) => old($k, (string) ($clinic[$k] ?? '')); ?>
<div class="page-head"><h1>Paramètres du cabinet</h1></div>

<form method="post" action="/settings">
  <?= csrf_field() ?>
  <div class="card">
    <h2>Informations</h2>
    <div class="form-grid">
      <div class="full">
        <label for="name">Nom du cabinet</label>
        <input type="text" id="name" name="name" value="<?= e($val('name')) ?>" required maxlength="150">
        <?= field_error('name') ?>
      </div>
      <div>
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= e($val('email')) ?>" maxlength="190">
        <?= field_error('email') ?>
      </div>
      <div>
        <label for="phone">Téléphone</label>
        <input type="tel" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="20">
        <?= field_error('phone') ?>
      </div>
      <div class="full">
        <label for="address">Adresse</label>
        <input type="text" id="address" name="address" value="<?= e($val('address')) ?>" maxlength="255">
      </div>
      <div>
        <label for="currency">Devise</label>
        <select id="currency" name="currency">
          <?php foreach (['TND', 'EUR', 'MAD', 'DZD', 'USD', 'CAD', 'CHF'] as $c): ?>
            <option<?= $val('currency') === $c ? ' selected' : '' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Prise de rendez-vous en ligne</h2>
    <p class="checkbox"><input type="checkbox" id="portal_enabled" name="portal_enabled" value="1"<?= $clinic['portal_enabled'] ? ' checked' : '' ?>>
      <label for="portal_enabled">Activer le portail patient</label></p>
    <p class="muted">Lien à partager avec vos patients : <a class="mono" href="/p/<?= e($clinic['slug']) ?>"><?= e(rtrim((string) env('APP_URL', ''), '/') . '/p/' . $clinic['slug']) ?></a></p>
    <div class="form-grid">
      <div>
        <label for="slot_minutes">Durée d’un créneau (min)</label>
        <select id="slot_minutes" name="slot_minutes">
          <?php foreach ([10, 15, 20, 30, 45, 60] as $m): ?>
            <option<?= (int) $val('slot_minutes') === $m ? ' selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="chairs">Fauteuils (RDV simultanés)</label>
        <select id="chairs" name="chairs">
          <?php for ($c = 1; $c <= 8; $c++): ?>
            <option<?= (int) $val('chairs') === $c ? ' selected' : '' ?>><?= $c ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Horaires d’ouverture</h2>
    <?= field_error('hours') ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Jour</th><th>Ouvert</th><th>Matin</th><th>Après-midi</th></tr></thead>
      <tbody>
      <?php foreach ($days as $n => $label):
          $ranges = $hours[$n] ?? [];
          $am = $ranges[0] ?? ['', ''];
          $pm = $ranges[1] ?? ['', ''];
      ?>
        <tr>
          <td><?= e($label) ?></td>
          <td><input type="checkbox" name="open[<?= $n ?>]" value="1"<?= $ranges ? ' checked' : '' ?> aria-label="<?= e($label) ?> ouvert"></td>
          <td class="nowrap">
            <input type="time" name="am_from[<?= $n ?>]" value="<?= e($am[0]) ?>" aria-label="Début matin"> –
            <input type="time" name="am_to[<?= $n ?>]" value="<?= e($am[1]) ?>" aria-label="Fin matin">
          </td>
          <td class="nowrap">
            <input type="time" name="pm_from[<?= $n ?>]" value="<?= e($pm[0]) ?>" aria-label="Début après-midi"> –
            <input type="time" name="pm_to[<?= $n ?>]" value="<?= e($pm[1]) ?>" aria-label="Fin après-midi">
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="form-actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
</form>
