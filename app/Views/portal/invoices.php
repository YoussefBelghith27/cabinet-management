<?php $base = '/p/' . $clinic['slug']; ?>
<div class="page-head">
  <h1>Mes factures</h1>
  <?php if ($invoices): ?>
    <div class="stat"><div class="label">Reste à payer</div><div class="value"><?= e(money($due, $clinic['currency'])) ?></div></div>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (!$invoices): ?>
    <p class="empty">Aucune facture pour le moment.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>N°</th><th>Date</th><th class="num">Total</th><th class="num">Payé</th><th class="num">Reste dû</th><th>Statut</th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $i): ?>
        <tr>
          <td><a href="<?= e($base) ?>/invoices/<?= (int) $i['id'] ?>"><?= e($i['number']) ?></a></td>
          <td><?= e(fmt_date($i['issued_on'])) ?></td>
          <td class="num"><?= e(money($i['total'], $clinic['currency'])) ?></td>
          <td class="num"><?= e(money($i['paid'], $clinic['currency'])) ?></td>
          <td class="num"><?= e(money((float) $i['total'] - (float) $i['paid'], $clinic['currency'])) ?></td>
          <td><span class="badge badge-<?= e($i['status']) ?>"><?= e(status_label($i['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted small">Le règlement se fait au cabinet (espèces, carte, chèque, virement).</p>
  <?php endif; ?>
</div>
