<?php
$v = fn (string $k) => (string) ($values[$k] ?? '');
$durations = [];
foreach ($treatments as $t) {
    $durations[$t['id']] = (int) $t['duration_minutes'];
}
?>
<div class="page-head">
  <h1><?= e($title) ?></h1>
  <a class="btn btn-ghost" href="/appointments">← Agenda</a>
</div>

<div class="card">
  <form method="post" action="<?= $appointment ? '/appointments/' . (int) $appointment['id'] : '/appointments' ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="full">
        <label for="patient_id">Patient</label>
        <select id="patient_id" name="patient_id" required>
          <option value="">— Choisir un patient —</option>
          <?php foreach ($patients as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= $v('patient_id') === (string) $p['id'] ? ' selected' : '' ?>>
              <?= e($p['last_name'] . ' ' . $p['first_name'] . ($p['phone'] ? ' — ' . $p['phone'] : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?= field_error('patient_id') ?>
        <span class="muted small">Nouveau patient ? <a href="/patients/new">Créer sa fiche</a> d’abord.</span>
      </div>
      <div>
        <label for="dentist_id">Praticien</label>
        <select id="dentist_id" name="dentist_id">
          <option value="">— Non assigné —</option>
          <?php foreach ($dentists as $d): ?>
            <option value="<?= (int) $d['id'] ?>"<?= $v('dentist_id') === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error('dentist_id') ?>
      </div>
      <div>
        <label for="treatment_id">Acte prévu</label>
        <select id="treatment_id" name="treatment_id" data-durations="<?= e(json_encode($durations)) ?>">
          <option value="">— Aucun —</option>
          <?php foreach ($treatments as $t): ?>
            <option value="<?= (int) $t['id'] ?>"<?= $v('treatment_id') === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error('treatment_id') ?>
      </div>
      <div>
        <label for="date">Date</label>
        <input type="date" id="date" name="date" value="<?= e($v('date')) ?>" required>
        <?= field_error('date') ?>
      </div>
      <div>
        <label for="time">Heure</label>
        <input type="time" id="time" name="time" value="<?= e($v('time')) ?>" required step="300">
        <?= field_error('time') ?>
      </div>
      <div>
        <label for="duration">Durée (minutes)</label>
        <input type="number" id="duration" name="duration" value="<?= e($v('duration')) ?>" min="5" max="480" step="5" required>
        <?= field_error('duration') ?>
      </div>
      <div>
        <label for="status">Statut</label>
        <select id="status" name="status">
          <?php foreach (['pending', 'confirmed', 'completed', 'cancelled', 'no_show'] as $s): ?>
            <option value="<?= $s ?>"<?= $v('status') === $s ? ' selected' : '' ?>><?= e(status_label($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="full">
        <label for="reason">Motif</label>
        <input type="text" id="reason" name="reason" value="<?= e($v('reason')) ?>" maxlength="255">
        <?= field_error('reason') ?>
      </div>
      <div class="full">
        <label for="notes">Notes internes</label>
        <textarea id="notes" name="notes" maxlength="2000"><?= e($v('notes')) ?></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Enregistrer</button>
      <a class="btn btn-ghost" href="/appointments">Annuler</a>
    </div>
  </form>
</div>
