<div class="page-head">
  <h1>Factures</h1>
  <div class="actions">
    <?php foreach (['' => 'Toutes', 'unpaid' => 'Impayées', 'partial' => 'Partielles', 'paid' => 'Payées', 'cancelled' => 'Annulées'] as $k => $label): ?>
      <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-ghost' ?>" href="/invoices<?= $k ? '?status=' . $k : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <a class="btn btn-primary" href="/invoices/new">+ Nouvelle facture</a>
  </div>
</div>

<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>N°</th><th>Date</th><th>Patient</th><th class="num">Total</th><th class="num">Reste dû</th><th>Statut</th></tr></thead>
    <tbody>
    <?php if (!$invoices): ?><tr><td colspan="6" class="empty">Aucune facture.</td></tr><?php endif; ?>
    <?php foreach ($invoices as $i): ?>
      <tr>
        <td><a href="/invoices/<?= (int) $i['id'] ?>"><?= e($i['number']) ?></a></td>
        <td><?= e(fmt_date($i['issued_on'])) ?></td>
        <td><a href="/patients/<?= (int) $i['patient_id'] ?>"><?= e($i['last_name'] . ' ' . $i['first_name']) ?></a></td>
        <td class="num"><?= e(money($i['total'], $currency)) ?></td>
        <td class="num"><?= $i['status'] === 'cancelled' ? '—' : e(money((float) $i['total'] - (float) $i['paid'], $currency)) ?></td>
        <td><span class="badge badge-<?= e($i['status']) ?>"><?= e(status_label($i['status'])) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
