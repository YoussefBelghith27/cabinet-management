<?php
$val = fn (string $k) => old($k, $patient[$k] ?? '');
?>
<div class="page-head">
  <h1><?= e($title) ?></h1>
  <a class="btn btn-ghost" href="<?= $patient ? '/patients/' . (int) $patient['id'] : '/patients' ?>">← Retour</a>
</div>

<form method="post" action="<?= $patient ? '/patients/' . (int) $patient['id'] : '/patients' ?>">
  <?= csrf_field() ?>
  <div class="card">
    <h2>Identité & contact</h2>
    <div class="form-grid">
      <div>
        <label for="last_name">Nom</label>
        <input type="text" id="last_name" name="last_name" value="<?= e($val('last_name')) ?>" required maxlength="80">
        <?= field_error('last_name') ?>
      </div>
      <div>
        <label for="first_name">Prénom</label>
        <input type="text" id="first_name" name="first_name" value="<?= e($val('first_name')) ?>" required maxlength="80">
        <?= field_error('first_name') ?>
      </div>
      <div>
        <label for="birth_date">Date de naissance</label>
        <input type="date" id="birth_date" name="birth_date" value="<?= e($val('birth_date')) ?>" max="<?= date('Y-m-d') ?>">
        <?= field_error('birth_date') ?>
      </div>
      <div>
        <label for="gender">Sexe</label>
        <select id="gender" name="gender">
          <option value="">—</option>
          <?php foreach (['F' => 'Femme', 'M' => 'Homme', 'O' => 'Autre'] as $k => $label): ?>
            <option value="<?= $k ?>"<?= $val('gender') === $k ? ' selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="phone">Téléphone</label>
        <input type="tel" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="20">
        <?= field_error('phone') ?>
      </div>
      <div>
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= e($val('email')) ?>" maxlength="190">
        <?= field_error('email') ?>
      </div>
      <div>
        <label for="address">Adresse</label>
        <input type="text" id="address" name="address" value="<?= e($val('address')) ?>" maxlength="255">
      </div>
      <div>
        <label for="insurance_number">N° assurance / CNAM</label>
        <input type="text" id="insurance_number" name="insurance_number" value="<?= e($val('insurance_number')) ?>" maxlength="50">
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Informations médicales</h2>
    <div class="form-grid">
      <div class="full">
        <label for="allergies">Allergies (affichées en alerte sur le dossier)</label>
        <textarea id="allergies" name="allergies" maxlength="1000" placeholder="Pénicilline, latex, anesthésiques…"><?= e($val('allergies')) ?></textarea>
        <?= field_error('allergies') ?>
      </div>
      <?php if ($clinical): ?>
        <div class="full">
          <label for="medical_history">Antécédents médicaux</label>
          <textarea id="medical_history" name="medical_history" maxlength="5000" placeholder="Diabète, hypertension, traitements en cours, grossesse…"><?= e($val('medical_history')) ?></textarea>
          <?= field_error('medical_history') ?>
        </div>
      <?php endif; ?>
      <div class="full">
        <label for="notes">Notes administratives</label>
        <textarea id="notes" name="notes" maxlength="5000"><?= e($val('notes')) ?></textarea>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Enregistrer</button>
  </div>
</form>
