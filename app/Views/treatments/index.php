<div class="page-head"><h1>Actes &amp; tarifs</h1></div>

<div class="card">
  <h2>Ajouter un acte</h2>
  <form method="post" action="/treatments" class="form-grid">
    <?= csrf_field() ?>
    <div class="full">
      <label for="name">Nom de l’acte</label>
      <input type="text" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="120">
      <?= field_error('name') ?>
    </div>
    <div>
      <label for="price">Prix (<?= e($currency) ?>)</label>
      <input type="text" id="price" name="price" inputmode="decimal" value="<?= e(old('price')) ?>" required>
      <?= field_error('price') ?>
    </div>
    <div>
      <label for="duration_minutes">Durée (min)</label>
      <input type="number" id="duration_minutes" name="duration_minutes" value="<?= e(old('duration_minutes', '30')) ?>" min="5" max="480" required>
      <?= field_error('duration_minutes') ?>
    </div>
    <div class="full"><button class="btn btn-primary" type="submit">Ajouter</button></div>
  </form>
</div>

<div class="card">
  <h2>Catalogue</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Acte</th><th>Prix (<?= e($currency) ?>)</th><th>Durée (min)</th><th>Actif</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($treatments as $t): $fid = 'tr' . (int) $t['id']; ?>
      <tr>
        <td><input form="<?= $fid ?>" type="text" name="name" value="<?= e($t['name']) ?>" maxlength="120" required aria-label="Nom"></td>
        <td><input form="<?= $fid ?>" type="text" name="price" value="<?= e($t['price'] + 0) ?>" inputmode="decimal" required aria-label="Prix"></td>
        <td><input form="<?= $fid ?>" type="number" name="duration_minutes" value="<?= (int) $t['duration_minutes'] ?>" min="5" max="480" required aria-label="Durée"></td>
        <td><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1"<?= $t['is_active'] ? ' checked' : '' ?> aria-label="Actif"></td>
        <td>
          <form id="<?= $fid ?>" method="post" action="/treatments/<?= (int) $t['id'] ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit">Enregistrer</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
