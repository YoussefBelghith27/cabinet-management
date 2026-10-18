<?php
$base = '/p/' . $clinic['slug'];
$due = max(0, (float) $invoice['total'] - (float) $invoice['paid']);
?>
<div class="page-head">
  <div>
    <h1>Facture <?= e($invoice['number']) ?></h1>
    <span class="muted"><?= e(fmt_date($invoice['issued_on'])) ?></span>
    <span class="badge badge-<?= e($invoice['status']) ?>"><?= e(status_label($invoice['status'])) ?></span>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="<?= e($base) ?>/invoices">Mes factures</a>
    <a class="btn btn-primary" href="<?= e($base) ?>/invoices/<?= (int) $invoice['id'] ?>/print">Imprimer / PDF</a>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Détail</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Montant</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['description']) ?></td>
          <td class="num"><?= (int) $it['quantity'] ?></td>
          <td class="num"><?= e(money($it['unit_price'], $currency)) ?></td>
          <td class="num"><?= e(money($it['line_total'], $currency)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="totals">
      <div><span>Total</span><span><?= e(money($invoice['total'], $currency)) ?></span></div>
      <div><span>Déjà réglé</span><span><?= e(money($invoice['paid'], $currency)) ?></span></div>
      <div class="grand"><span>Reste à payer</span><span><?= e(money($due, $currency)) ?></span></div>
    </div>
  </div>

  <div class="card">
    <h2>Mes paiements</h2>
    <ul class="timeline">
      <?php if (!$payments): ?><li class="muted">Aucun paiement enregistré.</li><?php endif; ?>
      <?php foreach ($payments as $pay): ?>
        <li><strong><?= e(money($pay['amount'], $currency)) ?></strong> — <?= e(method_label($pay['method'])) ?>, le <?= e(fmt_date($pay['paid_on'])) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
