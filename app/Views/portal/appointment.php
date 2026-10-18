<?php
$a = $appointment;
$base = '/p/' . $clinic['slug'];
$minutes = (int) round((strtotime($a['ends_at']) - strtotime($a['starts_at'])) / 60);
$hint = [
    'pending' => 'Votre demande a bien été reçue. Le cabinet doit encore la confirmer.',
    'confirmed' => 'Votre rendez-vous est confirmé. Merci d’arriver quelques minutes en avance.',
    'completed' => 'Ce rendez-vous a eu lieu. Les soins réalisés apparaissent dans « Mes soins ».',
    'cancelled' => 'Ce rendez-vous a été annulé.',
    'no_show' => 'Vous étiez absent à ce rendez-vous. Contactez le cabinet pour en fixer un nouveau.',
][$a['status']] ?? '';
?>
<div class="page-head">
  <div>
    <h1>Rendez-vous du <?= e(fmt_date($a['starts_at'])) ?></h1>
    <span class="badge badge-<?= e($a['status']) ?>"><?= e(status_label($a['status'])) ?></span>
  </div>
  <a class="btn btn-ghost" href="<?= e($base) ?>/account">Mes rendez-vous</a>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Détails</h2>
    <dl class="details">
      <dt>Date</dt><dd><?= e(fmt_date($a['starts_at'])) ?></dd>
      <dt>Heure</dt><dd><?= e(date('H:i', strtotime($a['starts_at']))) ?> – <?= e(date('H:i', strtotime($a['ends_at']))) ?> (<?= $minutes ?> min)</dd>
      <dt>Acte prévu</dt><dd><?= e($a['treatment_name'] ?? 'Consultation') ?></dd>
      <dt>Praticien</dt><dd><?= e($a['dentist_name'] ?? 'Attribué par le cabinet') ?></dd>
      <dt>Motif</dt><dd><?= e($a['reason'] ?: '—') ?></dd>
    </dl>
    <p class="muted"><?= e($hint) ?></p>
    <?php if ($cancellable): ?>
      <form method="post" action="<?= e($base) ?>/appointments/<?= (int) $a['id'] ?>/cancel" data-confirm="Annuler ce rendez-vous ?">
        <?= csrf_field() ?><button class="btn btn-ghost" type="submit">Annuler ce rendez-vous</button>
      </form>
    <?php elseif (in_array($a['status'], ['pending', 'confirmed'], true)): ?>
      <p class="muted small">Moins de 2 h avant le rendez-vous : pour annuler, appelez le cabinet.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Le cabinet</h2>
    <dl class="details">
      <dt>Cabinet</dt><dd><?= e($clinic['name']) ?></dd>
      <dt>Adresse</dt><dd><?= e($clinic['address'] ?: '—') ?></dd>
      <dt>Téléphone</dt><dd><?= $clinic['phone'] ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', $clinic['phone'])) . '">' . e($clinic['phone']) . '</a>' : '—' ?></dd>
      <dt>E-mail</dt><dd><?= e($clinic['email'] ?: '—') ?></dd>
    </dl>
  </div>
</div>
