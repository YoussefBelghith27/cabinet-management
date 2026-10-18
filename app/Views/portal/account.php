<?php
$now = time();
$upcoming = array_filter($appointments, fn ($a) => strtotime($a['starts_at']) >= $now && in_array($a['status'], ['pending', 'confirmed'], true));
$past = array_filter($appointments, fn ($a) => !in_array($a, $upcoming, true));
$base = '/p/' . $clinic['slug'];
?>
<div class="page-head"><h1>Bonjour <?= e($user['name']) ?></h1></div>

<div class="grid grid-2">
  <div class="card">
    <h2>Prendre rendez-vous</h2>
    <form method="post" action="<?= e($base) ?>/book" data-booking data-slots-url="/api/portal/<?= e($clinic['slug']) ?>/slots">
      <?= csrf_field() ?>
      <p><label for="date">Jour souhaité</label>
        <input type="date" id="date" name="date" min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" required><?= field_error('date') ?></p>
      <label>Créneaux disponibles</label>
      <div class="slots" data-slots><span class="muted">Choisissez d’abord un jour.</span></div>
      <input type="hidden" name="time" required>
      <?= field_error('time') ?>
      <p><label for="reason">Motif (facultatif)</label>
        <input type="text" id="reason" name="reason" maxlength="255" placeholder="Contrôle, douleur, détartrage…"></p>
      <button class="btn btn-primary" type="submit" disabled>Demander ce rendez-vous</button>
      <p class="muted small">Votre demande sera confirmée par le cabinet.</p>
    </form>
  </div>

  <div class="card">
    <h2>Mes prochains rendez-vous</h2>
    <ul class="timeline">
      <?php if (!$upcoming): ?><li class="muted">Aucun rendez-vous à venir.</li><?php endif; ?>
      <?php foreach (array_reverse($upcoming) as $a): ?>
        <li>
          <a href="<?= e($base) ?>/appointments/<?= (int) $a['id'] ?>"><strong><?= e(fmt_dt($a['starts_at'])) ?></strong></a>
          <span class="badge badge-<?= e($a['status']) ?>"><?= e(status_label($a['status'])) ?></span>
          <div class="muted small"><?= e($a['treatment_name'] ?? $a['reason'] ?? 'Consultation') ?><?= $a['dentist_name'] ? ', avec ' . e($a['dentist_name']) : '' ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<?php if ($past): ?>
  <div class="card">
    <h2>Historique de mes rendez-vous</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Acte / motif</th><th>Praticien</th><th>Statut</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($past as $a): ?>
        <tr>
          <td class="nowrap"><?= e(fmt_dt($a['starts_at'])) ?></td>
          <td><?= e($a['treatment_name'] ?? $a['reason'] ?? '—') ?></td>
          <td><?= e($a['dentist_name'] ?? '—') ?></td>
          <td><span class="badge badge-<?= e($a['status']) ?>"><?= e(status_label($a['status'])) ?></span></td>
          <td class="right"><a class="btn btn-sm btn-ghost" href="<?= e($base) ?>/appointments/<?= (int) $a['id'] ?>">Détails</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>
