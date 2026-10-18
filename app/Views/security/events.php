<div class="page-head">
  <h1>Journal de sécurité</h1>
  <div class="actions">
    <?php foreach (['' => 'Tous', 'info' => 'Info', 'warning' => 'Avertissements', 'critical' => 'Critiques'] as $k => $label): ?>
      <a class="btn btn-sm <?= $severity === $k ? 'btn-primary' : 'btn-ghost' ?>" href="/security/events<?= $k ? '?severity=' . $k : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid grid-3">
  <div class="stat"><div class="label">Échecs de connexion (7 j)</div><div class="value"><?= (int) ($summary['failed'] ?? 0) ?></div></div>
  <div class="stat"><div class="label">Comptes verrouillés (7 j)</div><div class="value"><?= (int) ($summary['locked'] ?? 0) ?></div></div>
  <div class="stat"><div class="label">Événements critiques (7 j)</div><div class="value"><?= (int) ($summary['critical'] ?? 0) ?></div></div>
</div>
<br>

<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Événement</th><th>Niveau</th><th>Utilisateur</th><th>IP</th><th>Détails</th></tr></thead>
    <tbody>
    <?php if (!$events): ?><tr><td colspan="6" class="empty">Aucun événement.</td></tr><?php endif; ?>
    <?php foreach ($events as $ev): ?>
      <tr>
        <td class="nowrap"><?= e(fmt_dt($ev['created_at'])) ?></td>
        <td class="mono small"><?= e($ev['event']) ?></td>
        <td><span class="badge badge-<?= e($ev['severity']) ?>"><?= e($ev['severity']) ?></span></td>
        <td><?= e($ev['user_name'] ?? '—') ?></td>
        <td class="mono small"><?= e($ev['ip']) ?></td>
        <td class="mono small"><?= e($ev['details'] && $ev['details'] !== '[]' ? $ev['details'] : '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
