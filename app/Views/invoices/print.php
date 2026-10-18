<div class="invoice-sheet">
  <div class="invoice-top">
    <div>
      <h2><?= e($clinic['name']) ?></h2>
      <div class="muted"><?= e($clinic['address'] ?? '') ?></div>
      <div class="muted"><?= e($clinic['phone'] ?? '') ?> <?= e($clinic['email'] ?? '') ?></div>
    </div>
    <div class="right">
      <h1>FACTURE</h1>
      <div>N° <strong><?= e($invoice['number']) ?></strong></div>
      <div>Date : <?= e(fmt_date($invoice['issued_on'])) ?></div>
      <?php if ($invoice['status'] === 'cancelled'): ?><div class="badge badge-cancelled">ANNULÉE</div><?php endif; ?>
    </div>
  </div>

  <p><strong>Patient :</strong> <?= e($patient['last_name'] . ' ' . $patient['first_name']) ?><br>
    <?= $patient['address'] ? e($patient['address']) . '<br>' : '' ?>
    <?= $patient['insurance_number'] ? 'N° assurance : ' . e($patient['insurance_number']) : '' ?></p>

  <table>
    <thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">Prix unitaire</th><th class="num">Montant</th></tr></thead>
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
  </table>

  <div class="totals">
    <div><span>Total</span><span><?= e(money($invoice['total'], $currency)) ?></span></div>
    <div><span>Déjà réglé</span><span><?= e(money($invoice['paid'], $currency)) ?></span></div>
    <div class="grand"><span>Net à payer</span><span><?= e(money(max(0, (float) $invoice['total'] - (float) $invoice['paid']), $currency)) ?></span></div>
  </div>
  <?php if ($invoice['notes']): ?><p><?= e($invoice['notes']) ?></p><?php endif; ?>
</div>
