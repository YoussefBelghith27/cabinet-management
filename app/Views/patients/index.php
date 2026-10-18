<div class="page-head">
  <h1>Patients <span class="muted small">(<?= (int) $total ?>)</span></h1>
  <a class="btn btn-primary" href="/patients/new">+ Nouveau patient</a>
</div>

<div class="card">
  <form method="get" action="/patients" class="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, téléphone ou e-mail…" maxlength="80" aria-label="Rechercher">
    <button class="btn" type="submit">Rechercher</button>
  </form>
  <br>
  <div class="table-wrap"><table>
    <thead><tr><th>Nom</th><th>Naissance</th><th>Téléphone</th><th>E-mail</th><th>Dernière visite</th></tr></thead>
    <tbody>
    <?php if (!$patients): ?>
      <tr><td colspan="5" class="empty">Aucun patient trouvé.</td></tr>
    <?php endif; ?>
    <?php foreach ($patients as $p): ?>
      <tr>
        <td><a href="/patients/<?= (int) $p['id'] ?>"><strong><?= e($p['last_name']) ?></strong> <?= e($p['first_name']) ?></a>
          <?php if ($p['allergies']): ?> <span class="badge badge-critical" title="Allergies">⚠ allergie</span><?php endif; ?>
        </td>
        <td><?= e(fmt_date($p['birth_date'])) ?></td>
        <td class="nowrap"><?= e($p['phone'] ?? '') ?></td>
        <td><?= e($p['email'] ?? '') ?></td>
        <td><?= e(fmt_date($p['last_visit'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
    <p class="actions">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>" href="/patients?page=<?= $i ?>&amp;q=<?= e(rawurlencode($q)) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </p>
  <?php endif; ?>
</div>
