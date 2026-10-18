<div class="page-head"><h1>Équipe</h1></div>

<?php if ($newPassword): ?>
  <div class="flash flash-warning">
    Mot de passe temporaire pour <strong><?= e($newPassword['email']) ?></strong> :
    <span class="mono"><?= e($newPassword['password']) ?></span><br>
    Transmettez-le de vive voix ; il ne sera plus affiché. La personne devra le changer à sa première connexion.
  </div>
<?php endif; ?>

<div class="card">
  <h2>Ajouter un membre</h2>
  <form method="post" action="/staff" class="form-grid">
    <?= csrf_field() ?>
    <div>
      <label for="name">Nom complet</label>
      <input type="text" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="120">
      <?= field_error('name') ?>
    </div>
    <div>
      <label for="email">E-mail (identifiant)</label>
      <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required maxlength="190">
      <?= field_error('email') ?>
    </div>
    <div>
      <label for="role">Rôle</label>
      <select id="role" name="role">
        <option value="secretary">Secrétaire — agenda, patients, factures</option>
        <option value="dentist">Dentiste — agenda, dossiers médicaux, soins</option>
        <option value="admin">Administrateur — accès complet</option>
      </select>
    </div>
    <div class="full"><button class="btn btn-primary" type="submit">Créer le compte</button></div>
  </form>
</div>

<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>2FA</th><th>Dernière connexion</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($staff as $s): ?>
      <tr>
        <td><?= e($s['name']) ?></td>
        <td><?= e($s['email']) ?></td>
        <td><?= e(role_label($s['role'])) ?></td>
        <td><?= $s['mfa_enabled'] ? '<span class="badge badge-paid">activée</span>' : '<span class="badge">non</span>' ?></td>
        <td><?= e(fmt_dt($s['last_login_at'])) ?></td>
        <td><?= $s['is_active'] ? '<span class="badge badge-paid">actif</span>' : '<span class="badge badge-cancelled">désactivé</span>' ?></td>
        <td class="right nowrap">
          <?php if ((int) $s['id'] !== (int) $user['id']): ?>
            <form method="post" action="/staff/<?= (int) $s['id'] ?>/reset-password" class="inline" data-confirm="Générer un nouveau mot de passe temporaire ?">
              <?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Réinitialiser</button>
            </form>
            <form method="post" action="/staff/<?= (int) $s['id'] ?>/toggle" class="inline" data-confirm="<?= $s['is_active'] ? 'Désactiver ce compte ?' : 'Réactiver ce compte ?' ?>">
              <?= csrf_field() ?><button class="btn btn-sm <?= $s['is_active'] ? 'btn-danger' : '' ?>" type="submit"><?= $s['is_active'] ? 'Désactiver' : 'Réactiver' ?></button>
            </form>
          <?php else: ?>
            <span class="muted small">vous</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
