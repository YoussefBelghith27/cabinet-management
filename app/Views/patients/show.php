<?php
$p = $patient;
$age = $p['birth_date'] ? (new DateTime($p['birth_date']))->diff(new DateTime())->y : null;
$canBill = in_array($user['role'], ['admin', 'secretary'], true);
?>
<div class="page-head reveal">
  <div class="patient-head">
    <span class="avatar avatar-lg"><?= e(initials($p['first_name'] . ' ' . $p['last_name'])) ?></span>
    <div>
    <h1><?= e($p['last_name'] . ' ' . $p['first_name']) ?></h1>
    <span class="muted"><?= $age !== null ? e($age . ' ans · ') : '' ?>Dossier n° <?= (int) $p['id'] ?><?= $hasPortalAccount ? ' · compte portail actif' : '' ?></span>
    </div>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="/patients/<?= (int) $p['id'] ?>/edit">Modifier</a>
    <?php if ($canBill): ?><a class="btn btn-ghost" href="/invoices/new?patient_id=<?= (int) $p['id'] ?>">+ Facture</a><?php endif; ?>
    <a class="btn btn-primary" href="/appointments/new?patient_id=<?= (int) $p['id'] ?>">+ Rendez-vous</a>
  </div>
</div>

<?php if ($p['allergies']): ?>
  <div class="alert-box" role="alert"><?= icon('alert') ?><strong>Allergies :</strong> <?= e($p['allergies']) ?></div><br>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2>Coordonnées</h2>
    <dl class="details">
      <dt>Naissance</dt><dd><?= e(fmt_date($p['birth_date'])) ?></dd>
      <dt>Téléphone</dt><dd><?= e($p['phone'] ?? '—') ?></dd>
      <dt>E-mail</dt><dd><?= e($p['email'] ?? '—') ?></dd>
      <dt>Adresse</dt><dd><?= e($p['address'] ?? '—') ?></dd>
      <dt>Assurance</dt><dd><?= e($p['insurance_number'] ?? '—') ?></dd>
      <dt>Notes</dt><dd><?= e($p['notes'] ?? '—') ?></dd>
      <?php if ($canBill): ?>
        <dt>Reste à payer</dt><dd><strong><?= e(money($balance, $user['currency'])) ?></strong></dd>
      <?php endif; ?>
    </dl>
  </div>
  <?php if ($clinical): ?>
    <div class="card">
      <h2>Antécédents médicaux</h2>
      <p class="details"><?= $p['medical_history'] ? nl2br(e($p['medical_history'])) : '<span class="muted">Non renseignés.</span>' ?></p>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Rendez-vous</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Acte / motif</th><th>Praticien</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php if (!$appointments): ?><tr><td colspan="5" class="empty">Aucun rendez-vous.</td></tr><?php endif; ?>
    <?php foreach ($appointments as $a): ?>
      <tr>
        <td class="nowrap"><?= e(fmt_dt($a['starts_at'])) ?></td>
        <td><?= e($a['treatment_name'] ?? $a['reason'] ?? '—') ?></td>
        <td><?= e($a['dentist_name'] ?? '—') ?></td>
        <td><span class="badge badge-<?= e($a['status']) ?>"><?= e(status_label($a['status'])) ?></span></td>
        <td class="right"><a class="btn btn-sm btn-ghost" href="/appointments/<?= (int) $a['id'] ?>/edit">Ouvrir</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($clinical): ?>
  <div class="card" id="soins">
    <h2>Historique des soins</h2>
    <form method="post" action="/patients/<?= (int) $p['id'] ?>/procedures" class="form-grid">
      <?= csrf_field() ?>
      <div>
        <label for="performed_on">Date</label>
        <input type="date" id="performed_on" name="performed_on" value="<?= e(old('performed_on', date('Y-m-d'))) ?>" required>
      </div>
      <div>
        <label for="tooth">Dent(s) (notation FDI)</label>
        <input type="text" id="tooth" name="tooth" value="<?= e(old('tooth')) ?>" maxlength="20" placeholder="ex : 36, 11-21">
      </div>
      <div class="full">
        <label for="proc_treatment">Acte</label>
        <select id="proc_treatment" name="treatment_id">
          <option value="">— Autre —</option>
          <?php foreach ($treatments as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="full">
        <label for="description">Compte rendu</label>
        <p class="muted small">Le patient peut lire ce compte rendu dans son espace en ligne.</p>
        <textarea id="description" name="description" required maxlength="3000"><?= e(old('description')) ?></textarea>
        <?= field_error('description') ?>
      </div>
      <div class="full"><button class="btn btn-primary" type="submit">Ajouter le soin</button></div>
    </form>
    <ul class="timeline">
      <?php foreach ($procedures as $pr): ?>
        <li>
          <strong><?= e(fmt_date($pr['performed_on'])) ?></strong>
          <?= $pr['tooth'] ? ' · dent ' . e($pr['tooth']) : '' ?>
          <?= $pr['treatment_name'] ? ' · ' . e($pr['treatment_name']) : '' ?>
          <span class="muted small"> — <?= e($pr['dentist_name'] ?? '') ?></span>
          <div><?= nl2br(e($pr['description'])) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($canBill): ?>
  <div class="card">
    <h2>Factures</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>N°</th><th>Date</th><th class="num">Total</th><th class="num">Payé</th><th>Statut</th></tr></thead>
      <tbody>
      <?php if (!$invoices): ?><tr><td colspan="5" class="empty">Aucune facture.</td></tr><?php endif; ?>
      <?php foreach ($invoices as $i): ?>
        <tr>
          <td><a href="/invoices/<?= (int) $i['id'] ?>"><?= e($i['number']) ?></a></td>
          <td><?= e(fmt_date($i['issued_on'])) ?></td>
          <td class="num"><?= e(money($i['total'], $user['currency'])) ?></td>
          <td class="num"><?= e(money($i['paid'], $user['currency'])) ?></td>
          <td><span class="badge badge-<?= e($i['status']) ?>"><?= e(status_label($i['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>
