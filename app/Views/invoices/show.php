<?php $due = (float) $invoice['total'] - (float) $invoice['paid']; ?>
<div class="page-head">
  <div>
    <h1>Facture <?= e($invoice['number']) ?> <span class="badge badge-<?= e($invoice['status']) ?>"><?= e(status_label($invoice['status'])) ?></span></h1>
    <span class="muted"><?= e(fmt_date($invoice['issued_on'])) ?> · <a href="/patients/<?= (int) $patient['id'] ?>"><?= e($patient['last_name'] . ' ' . $patient['first_name']) ?></a></span>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="/invoices/<?= (int) $invoice['id'] ?>/print">Imprimer / PDF</a>
    <?php if ($user['role'] === 'admin' && $invoice['status'] !== 'cancelled' && (float) $invoice['paid'] == 0.0): ?>
      <form method="post" action="/invoices/<?= (int) $invoice['id'] ?>/cancel" class="inline" data-confirm="Annuler définitivement cette facture ?">
        <?= csrf_field() ?><button class="btn btn-danger" type="submit">Annuler la facture</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Détail</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Description</th><th class="num">Qté</th><th class="num">P.U.</th><th class="num">Total</th></tr></thead>
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
      <div><span>Payé</span><span><?= e(money($invoice['paid'], $currency)) ?></span></div>
      <div class="grand"><span>Reste dû</span><span><?= e(money($invoice['status'] === 'cancelled' ? 0 : $due, $currency)) ?></span></div>
    </div>
    <?php if ($invoice['notes']): ?><p class="muted"><?= e($invoice['notes']) ?></p><?php endif; ?>
  </div>

  <div class="card">
    <h2>Paiements</h2>
    <ul class="timeline">
      <?php if (!$payments): ?><li class="muted">Aucun paiement.</li><?php endif; ?>
      <?php foreach ($payments as $pay): ?>
        <li><strong><?= e(money($pay['amount'], $currency)) ?></strong> — <?= e(method_label($pay['method'])) ?> · <?= e(fmt_date($pay['paid_on'])) ?>
          <?= $pay['reference'] ? '<span class="muted small"> · réf. ' . e($pay['reference']) . '</span>' : '' ?></li>
      <?php endforeach; ?>
    </ul>

    <?php if (in_array($invoice['status'], ['unpaid', 'partial'], true)): ?>
      <h3>Enregistrer un paiement</h3>
      <form method="post" action="/invoices/<?= (int) $invoice['id'] ?>/payments" class="form-grid">
        <?= csrf_field() ?>
        <div>
          <label for="amount">Montant</label>
          <input type="text" id="amount" name="amount" inputmode="decimal" value="<?= e(old('amount', number_format($due, currency_decimals($currency), '.', ''))) ?>" required>
          <?= field_error('amount') ?>
        </div>
        <div>
          <label for="method">Mode</label>
          <select id="method" name="method">
            <?php foreach (['cash', 'card', 'check', 'transfer', 'insurance'] as $m): ?>
              <option value="<?= $m ?>"><?= e(method_label($m)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="paid_on">Date</label>
          <input type="date" id="paid_on" name="paid_on" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div>
          <label for="reference">Référence (n° chèque…)</label>
          <input type="text" id="reference" name="reference" maxlength="100">
        </div>
        <div class="full"><button class="btn btn-primary" type="submit">Encaisser</button></div>
      </form>
    <?php endif; ?>
  </div>
</div>
