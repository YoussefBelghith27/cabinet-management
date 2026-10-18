<?php
// "Dr. Karim Trabelsi" → "Dr Karim", "Sana Mejri" → "Sana" (as in the mockup greeting)
$isDoctor = (bool) preg_match('/^(Dr|Pr)\.?\s+/i', $user['name']);
$firstName = ($isDoctor ? 'Dr ' : '') . strtok((string) preg_replace('/^(Dr|Pr)\.?\s+/i', '', $user['name']), ' ');
$now = time();
?>
<div class="page-head reveal">
  <div>
    <p class="eyebrow"><?= icon('calendar') ?><?= e(ucfirst(fr_date())) ?> — <?= e($clinic['name']) ?></p>
    <h1>Bonjour, <?= e($firstName) ?></h1>
    <p class="muted">Voici le programme de la journée et les demandes à traiter.</p>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="/patients/new"><?= icon('plus') ?>Nouveau patient</a>
    <a class="btn btn-primary" href="/appointments/new"><?= icon('calendar') ?>Nouveau rendez-vous</a>
  </div>
</div>

<div class="kpis">
  <div class="kpi reveal">
    <div class="kpi-top"><span class="kpi-label">Rendez-vous aujourd’hui</span><span class="kpi-icon tone-blue"><?= icon('calendar') ?></span></div>
    <div class="kpi-value" data-count="<?= (int) $today['total'] ?>"><?= (int) $today['total'] ?></div>
    <div class="kpi-note"><?= (int) $stats['patients'] ?> patients au total, <?= (int) $stats['new_patients'] ?> nouveaux ce mois</div>
  </div>
  <div class="kpi reveal">
    <div class="kpi-top"><span class="kpi-label">Confirmés</span><span class="kpi-icon tone-green"><?= icon('check') ?></span></div>
    <div class="kpi-value" data-count="<?= (int) $today['confirmed'] ?>"><?= (int) $today['confirmed'] ?></div>
    <div class="progress" role="progressbar" aria-valuenow="<?= (int) $today['confirmed_pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Part des rendez-vous confirmés">
      <span data-progress="<?= (int) $today['confirmed_pct'] ?>"></span>
    </div>
    <div class="kpi-note tone-green-text"><?= (int) $today['confirmed_pct'] ?> % du planning du jour</div>
  </div>
  <div class="kpi reveal">
    <div class="kpi-top"><span class="kpi-label">À confirmer</span><span class="kpi-icon tone-amber"><?= icon('inbox') ?></span></div>
    <div class="kpi-value" data-count="<?= (int) $stats['pending'] ?>"><?= (int) $stats['pending'] ?></div>
    <div class="kpi-note">Demandes en ligne à venir</div>
  </div>
  <div class="kpi reveal">
    <div class="kpi-top"><span class="kpi-label">Annulations & absences</span><span class="kpi-icon tone-red"><?= icon('ban') ?></span></div>
    <div class="kpi-value" data-count="<?= (int) $today['cancelled'] ?>"><?= (int) $today['cancelled'] ?></div>
    <div class="kpi-note <?= $today['cancelled'] ? 'tone-red-text' : '' ?>"><?= $today['cancelled'] ? 'Créneaux à réattribuer' : 'Aucune aujourd’hui' ?></div>
  </div>
</div>

<div class="dash-grid">
  <section class="card reveal" aria-labelledby="today-title">
    <div class="card-head">
      <div>
        <h2 id="today-title">Rendez-vous du jour</h2>
        <p class="muted small"><?= count($todayList) ?> rendez-vous programmés</p>
      </div>
      <?php if (count($practitioners) > 1): ?>
        <div class="chips" role="group" aria-label="Filtrer par praticien" data-filter-group="today">
          <button type="button" class="chip is-on" data-filter="all" aria-pressed="true">Tous</button>
          <?php foreach ($practitioners as $id => $name): ?>
            <button type="button" class="chip" data-filter="<?= (int) $id ?>" aria-pressed="false"><?= e($name) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if (!$todayList): ?>
      <p class="empty"><?= icon('calendar') ?><br>Aucun rendez-vous aujourd’hui. <a href="/appointments/new">Planifier un rendez-vous</a></p>
    <?php else: ?>
      <div class="table-wrap"><table class="rdv-table">
        <thead><tr><th>Heure</th><th>Patient & motif</th><th>Praticien</th><th>Statut</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($todayList as $a):
            $start = strtotime($a['starts_at']);
            $isNow = $start <= $now && strtotime($a['ends_at']) > $now && in_array($a['status'], ['confirmed', 'pending'], true);
        ?>
          <tr data-row-filter="<?= (int) $a['dentist_id'] ?>" class="<?= $a['status'] === 'cancelled' || $a['status'] === 'no_show' ? 'is-muted' : '' ?><?= $isNow ? ' is-now' : '' ?>">
            <td class="nowrap time-cell"><?= icon('clock') ?><?= e(date('H:i', $start)) ?></td>
            <td>
              <div class="person">
                <span class="avatar avatar-sm"><?= e(initials($a['first_name'] . ' ' . $a['last_name'])) ?></span>
                <span>
                  <a href="/patients/<?= (int) $a['patient_id'] ?>"><strong><?= e($a['last_name'] . ' ' . $a['first_name']) ?></strong></a>
                  <small class="muted"><?= e($a['treatment_name'] ?? $a['reason'] ?? 'Consultation') ?></small>
                </span>
              </div>
            </td>
            <td class="muted"><?= e($a['dentist_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= e($a['status']) ?>"><?= e(status_label($a['status'])) ?></span></td>
            <td class="right nowrap">
              <?php if (in_array($a['status'], ['confirmed', 'pending'], true)): ?>
                <form method="post" action="/appointments/<?= (int) $a['id'] ?>/status" class="inline">
                  <?= csrf_field() ?><input type="hidden" name="back" value="/dashboard">
                  <button class="icon-btn tone-green" name="status" value="completed" title="Marquer terminé" aria-label="Marquer terminé"><?= icon('check') ?></button>
                  <button class="icon-btn tone-red" name="status" value="no_show" title="Marquer absent" aria-label="Marquer absent"><?= icon('ban') ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>

  <div class="dash-side">
    <section class="card reveal" aria-labelledby="pending-title">
      <div class="card-head">
        <h2 id="pending-title">À confirmer <span class="count-pill"><?= count($pendingList) ?></span></h2>
      </div>
      <?php if (!$pendingList): ?>
        <p class="empty small">Aucune demande en ligne en attente.</p>
      <?php else: ?>
        <ul class="side-list">
          <?php foreach ($pendingList as $a): ?>
            <li>
              <span class="avatar avatar-sm tone-amber"><?= e(initials($a['first_name'] . ' ' . $a['last_name'])) ?></span>
              <div class="side-main">
                <a href="/patients/<?= (int) $a['patient_id'] ?>"><strong><?= e($a['last_name'] . ' ' . $a['first_name']) ?></strong></a>
                <small class="muted"><?= e(fmt_dt($a['starts_at'])) ?><?= $a['reason'] ? ', ' . e($a['reason']) : '' ?></small>
              </div>
              <div class="side-actions">
                <?php if ($a['phone']): ?>
                  <a class="icon-btn" href="tel:<?= e(preg_replace('/[^+\d]/', '', $a['phone'])) ?>" title="Appeler <?= e($a['phone']) ?>" aria-label="Appeler"><?= icon('phone') ?></a>
                <?php endif; ?>
                <form method="post" action="/appointments/<?= (int) $a['id'] ?>/status" class="inline">
                  <?= csrf_field() ?><input type="hidden" name="back" value="/dashboard">
                  <button class="icon-btn tone-green" name="status" value="confirmed" title="Confirmer" aria-label="Confirmer"><?= icon('check') ?></button>
                  <button class="icon-btn tone-red" name="status" value="cancelled" title="Refuser" aria-label="Refuser"><?= icon('x') ?></button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card reveal" aria-labelledby="slots-title">
      <div class="card-head"><h2 id="slots-title">Créneaux libres aujourd’hui</h2></div>
      <?php if (!$freeSlots): ?>
        <p class="empty small">Plus de créneau libre aujourd’hui.</p>
      <?php else: ?>
        <div class="slot-grid">
          <?php foreach ($freeSlots as $slot): ?>
            <a class="slot-chip" href="/appointments/new?date=<?= e(date('Y-m-d')) ?>&amp;time=<?= e($slot) ?>"><?= e($slot) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php if ($finance): ?>
  <?php $max = max(1, ...array_values($finance['months'])); ?>
  <div class="grid grid-2">
    <section class="card reveal">
      <div class="card-head"><h2>Finances</h2><span class="kpi-icon tone-teal"><?= icon('wallet') ?></span></div>
      <div class="grid grid-2">
        <div><div class="kpi-label">Encaissé ce mois</div><div class="value-lg"><?= e(money($finance['month_revenue'], $user['currency'])) ?></div></div>
        <div><div class="kpi-label">Reste à encaisser</div><div class="value-lg"><?= e(money($finance['outstanding'], $user['currency'])) ?></div></div>
      </div>
    </section>
    <section class="card reveal">
      <h2>Encaissements — 6 derniers mois</h2>
      <div class="bars">
        <?php foreach ($finance['months'] as $ym => $amount): ?>
          <div class="bar">
            <span class="bar-value"><?= e(number_format($amount, 0, ',', ' ')) ?></span>
            <div class="bar-fill" data-pct="<?= e(round($amount / $max * 100, 1)) ?>"></div>
            <span class="bar-label"><?= e(date('m/y', strtotime($ym . '-01'))) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
<?php endif; ?>
