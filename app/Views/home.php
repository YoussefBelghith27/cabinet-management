<section class="hero">
  <h1>Votre cabinet dentaire, organisé simplement.</h1>
  <p>Agenda, dossiers patients, facturation et prise de rendez-vous en ligne — dans une plateforme sécurisée, pensée pour les dentistes et leurs assistants.</p>
  <div class="actions actions-center">
    <a class="btn btn-primary" href="/register">Créer mon cabinet</a>
    <a class="btn btn-ghost" href="/login">J’ai déjà un compte</a>
  </div>

  <?php
  // Illustrative week (8h–13h, 48px per hour): appointments settle into place, then an online request gets confirmed.
  $demo = [
      'Lun' => [[48, 36, 'Y. Haddad', 'Détartrage'], [120, 24, 'O. Jebali', 'Contrôle']],
      'Mar' => [[72, 48, 'I. Chaabane', 'Soin de carie'], [168, 24, 'L. Bouazizi', 'Radiographie']],
      'Mer' => [[24, 48, 'H. Sassi', 'Couronne'], [144, 36, 'M. Gharbi', null]],
      'Jeu' => [[96, 36, 'K. Trabelsi', 'Extraction']],
      'Ven' => [[48, 24, 'N. Ayari', 'Contrôle'], [120, 48, 'S. Mejri', 'Blanchiment']],
  ];
  $seq = 0;
  ?>
  <div class="hero-demo" aria-hidden="true">
    <div class="demo-hours">
      <?php foreach (['8h', '9h', '10h', '11h', '12h'] as $i => $h): ?>
        <span data-top="<?= $i * 48 ?>"><?= $h ?></span>
      <?php endforeach; ?>
    </div>
    <?php foreach ($demo as $day => $items): ?>
      <div class="demo-day">
        <div class="demo-day-name"><?= $day ?></div>
        <div class="demo-col<?= $day === 'Mer' ? ' is-today' : '' ?>">
          <?php foreach ($items as [$top, $height, $name, $act]): ?>
            <div class="demo-appt<?= $act === null ? ' is-request' : '' ?>" data-top="<?= $top ?>" data-height="<?= $height - 2 ?>" data-seq="<?= $seq++ ?>">
              <strong><?= e($name) ?></strong>
              <?php if ($act === null): ?>
                <span class="demo-state"><span class="state-pending">Demande en ligne</span><span class="state-confirmed">Confirmé</span></span>
              <?php else: ?>
                <span><?= e($act) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($day === 'Mer'): ?><div class="now-line" data-top="108"></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="grid grid-3">
  <div class="card feature">
    <h3>Agenda intelligent</h3>
    <p class="muted">Vue semaine par praticien, détection des conflits, statuts (confirmé, absent, terminé) et demandes en ligne à valider.</p>
  </div>
  <div class="card feature">
    <h3>Dossiers patients</h3>
    <p class="muted">Coordonnées, antécédents, allergies mises en évidence, historique des soins dent par dent.</p>
  </div>
  <div class="card feature">
    <h3>Facturation</h3>
    <p class="muted">Factures numérotées, paiements partiels, reste à payer, impression PDF et suivi du chiffre d’affaires.</p>
  </div>
  <div class="card feature">
    <h3>Portail patient</h3>
    <p class="muted">Vos patients réservent un créneau libre 24 h/24 ; vous confirmez en un clic.</p>
  </div>
  <div class="card feature">
    <h3>Équipe & rôles</h3>
    <p class="muted">Dentiste, secrétaire, administrateur : chacun ne voit que ce dont il a besoin.</p>
  </div>
  <div class="card feature">
    <h3>Sécurité</h3>
    <p class="muted">Double authentification, données isolées par cabinet, journal de sécurité et sauvegardes vérifiées.</p>
  </div>
</section>
