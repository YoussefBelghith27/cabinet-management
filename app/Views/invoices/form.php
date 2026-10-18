<?php
$catalog = [];
foreach ($treatments as $t) {
    $catalog[$t['id']] = ['name' => $t['name'], 'price' => $t['price']];
}
$selected = (int) old('patient_id', (string) $patientId);
?>
<div class="page-head">
  <h1>Nouvelle facture</h1>
  <a class="btn btn-ghost" href="/invoices">← Factures</a>
</div>

<form method="post" action="/invoices" data-invoice-form data-decimals="<?= currency_decimals($currency) ?>" data-catalog="<?= e(json_encode($catalog)) ?>">
  <?= csrf_field() ?>
  <div class="card">
    <div class="form-grid">
      <div>
        <label for="patient_id">Patient</label>
        <select id="patient_id" name="patient_id" required>
          <option value="">— Choisir —</option>
          <?php foreach ($patients as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= $selected === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['last_name'] . ' ' . $p['first_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error('patient_id') ?>
      </div>
      <div>
        <label for="issued_on">Date</label>
        <input type="date" id="issued_on" name="issued_on" value="<?= e(old('issued_on', date('Y-m-d'))) ?>" required>
        <?= field_error('issued_on') ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Lignes</h2>
    <?= field_error('items') ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Acte (catalogue)</th><th>Description</th><th>Qté</th><th>Prix unitaire</th><th class="num">Total</th><th></th></tr></thead>
      <tbody></tbody>
      <tfoot><tr><td colspan="4" class="right"><strong>Total (<?= e($currency) ?>)</strong></td><td class="num"><strong data-total>0</strong></td><td></td></tr></tfoot>
    </table></div>
    <template>
      <tr>
        <td>
          <select data-catalog aria-label="Acte du catalogue">
            <option value="">—</option>
            <?php foreach ($treatments as $t): ?>
              <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input type="text" name="item_description[]" maxlength="255" aria-label="Description"></td>
        <td><input type="number" name="item_quantity[]" value="1" min="1" max="999" aria-label="Quantité"></td>
        <td><input type="text" name="item_price[]" inputmode="decimal" aria-label="Prix unitaire"></td>
        <td class="num" data-line-total>0</td>
        <td><button type="button" class="btn btn-sm btn-ghost" data-remove-line aria-label="Supprimer la ligne">✕</button></td>
      </tr>
    </template>
    <p><button type="button" class="btn btn-sm" data-add-line>+ Ajouter une ligne</button></p>
    <label for="notes">Notes (imprimées sur la facture)</label>
    <input type="text" id="notes" name="notes" value="<?= e(old('notes')) ?>" maxlength="500">
  </div>

  <div class="form-actions"><button class="btn btn-primary" type="submit">Créer la facture</button></div>
</form>
