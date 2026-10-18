<div class="page-head"><h1>Mes soins</h1></div>

<div class="card">
  <?php if (!$procedures): ?>
    <p class="empty">Aucun soin enregistré pour le moment. Après chaque séance, le dentiste ajoute ici ce qui a été fait.</p>
  <?php else: ?>
    <ul class="timeline">
      <?php foreach ($procedures as $pr): ?>
        <li>
          <strong><?= e(fmt_date($pr['performed_on'])) ?></strong>
          <?= $pr['treatment_name'] ? ' — ' . e($pr['treatment_name']) : '' ?>
          <?= $pr['tooth'] ? '<span class="badge">dent ' . e($pr['tooth']) . '</span>' : '' ?>
          <div class="muted small"><?= e($pr['dentist_name'] ?? '') ?></div>
          <p class="care-report"><?= nl2br(e($pr['description'])) ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="muted small">Les dents sont numérotées selon la notation internationale (FDI) : le premier chiffre indique le quadrant, le second la dent. Une question sur un soin ? Contactez le cabinet<?= $clinic['phone'] ? ' au ' . e($clinic['phone']) : '' ?>.</p>
  <?php endif; ?>
</div>
