<?php
$pxPerMinute = 1.2; // 72px per hour, matches .agenda-col background grid
$colHeight = ($endHour - $startHour) * 60 * $pxPerMinute;
$prev = $monday->modify('-7 days')->format('Y-m-d');
$next = $monday->modify('+7 days')->format('Y-m-d');
$dayNames = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
$dentistQs = $dentistId ? '&dentist=' . (int) $dentistId : '';
?>
<div class="page-head">
  <h1>Agenda — semaine du <?= e($monday->format('d/m/Y')) ?></h1>
  <div class="actions">
    <a class="btn btn-ghost btn-sm" href="/appointments?week=<?= e($prev . $dentistQs) ?>">← Précédente</a>
    <a class="btn btn-ghost btn-sm" href="/appointments<?= $dentistId ? '?dentist=' . (int) $dentistId : '' ?>">Aujourd’hui</a>
    <a class="btn btn-ghost btn-sm" href="/appointments?week=<?= e($next . $dentistQs) ?>">Suivante →</a>
    <form method="get" action="/appointments" class="inline">
      <input type="hidden" name="week" value="<?= e($monday->format('Y-m-d')) ?>">
      <select name="dentist" data-autosubmit aria-label="Praticien">
        <option value="">Tous les praticiens</option>
        <?php foreach ($dentists as $d): ?>
          <option value="<?= (int) $d['id'] ?>"<?= $dentistId === (int) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-sm">Filtrer</button></noscript>
    </form>
    <a class="btn btn-primary" href="/appointments/new">+ Rendez-vous</a>
  </div>
</div>

<div class="agenda" data-agenda data-start-hour="<?= (int) $startHour ?>" data-end-hour="<?= (int) $endHour ?>" data-px-per-minute="<?= e($pxPerMinute) ?>">
  <div class="agenda-corner"></div>
  <?php $i = 0; foreach ($days as $key => $day): ?>
    <div class="agenda-head<?= $key === date('Y-m-d') ? ' today' : '' ?>">
      <span><?= e($dayNames[$i++] . ' ' . $day['date']->format('d/m')) ?></span>
      <a href="/appointments/new?date=<?= e($key) ?>" title="Ajouter un rendez-vous" aria-label="Ajouter un rendez-vous le <?= e($day['date']->format('d/m')) ?>">+</a>
    </div>
  <?php endforeach; ?>

  <div class="agenda-hours" data-col-height="<?= e($colHeight) ?>">
    <?php for ($h = $startHour; $h < $endHour; $h++): ?>
      <div class="agenda-hour" data-top="<?= e(($h - $startHour) * 60 * $pxPerMinute) ?>"><?= sprintf('%02d:00', $h) ?></div>
    <?php endfor; ?>
  </div>

  <?php foreach ($days as $key => $day): ?>
    <div class="agenda-col<?= $key === date('Y-m-d') ? ' is-today' : '' ?>" data-col-height="<?= e($colHeight) ?>">
      <?php foreach ($day['items'] as $a):
          $start = strtotime($a['starts_at']);
          $minutesFromTop = ((int) date('G', $start) - $startHour) * 60 + (int) date('i', $start);
          $duration = max(15, (strtotime($a['ends_at']) - $start) / 60);
      ?>
        <a class="appt appt-<?= e($a['status']) ?><?= $a['lanes'] > 1 ? ' is-narrow' : '' ?>" href="/appointments/<?= (int) $a['id'] ?>/edit"
           data-top="<?= e($minutesFromTop * $pxPerMinute) ?>" data-height="<?= e($duration * $pxPerMinute - 2) ?>" data-lane="<?= (int) $a['lane'] ?>" data-lanes="<?= (int) $a['lanes'] ?>"
           title="<?= e(date('H:i', $start) . ' — ' . $a['last_name'] . ' ' . $a['first_name'] . ' (' . status_label($a['status']) . ')') ?>">
          <strong><span class="appt-time"><?= e(date('H:i', $start)) ?> </span><?= e($a['last_name']) ?></strong>
          <span class="muted"><?= e($a['treatment_name'] ?? $a['reason'] ?? '') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<p class="muted small">
  <span class="badge badge-confirmed">Confirmé</span>
  <span class="badge badge-pending">En attente (portail)</span>
  <span class="badge badge-completed">Terminé</span>
  <span class="badge badge-no_show">Absent</span>
  — cliquez sur un rendez-vous pour le modifier.
</p>
