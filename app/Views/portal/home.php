<?php $hours = json_decode((string) $clinic['opening_hours'], true) ?: []; ?>
<section class="hero">
  <h1><?= e($clinic['name']) ?></h1>
  <p>Prenez rendez-vous en ligne en quelques clics, 24 h/24.</p>
  <div class="actions actions-center">
    <a class="btn btn-primary" href="/p/<?= e($clinic['slug']) ?>/register">Prendre rendez-vous</a>
    <a class="btn btn-ghost" href="/p/<?= e($clinic['slug']) ?>/login">J’ai déjà un compte</a>
  </div>
</section>

<div class="grid grid-2">
  <div class="card">
    <h2>Horaires</h2>
    <dl class="details">
      <?php foreach ($days as $n => $label): ?>
        <dt><?= e($label) ?></dt>
        <dd><?= !empty($hours[$n]) ? e(implode(' · ', array_map(fn ($r) => $r[0] . ' – ' . $r[1], $hours[$n]))) : '<span class="muted">Fermé</span>' ?></dd>
      <?php endforeach; ?>
    </dl>
  </div>
  <div class="card">
    <h2>Contact</h2>
    <dl class="details">
      <dt>Adresse</dt><dd><?= e($clinic['address'] ?: '—') ?></dd>
      <dt>Téléphone</dt><dd><?= e($clinic['phone'] ?: '—') ?></dd>
      <dt>E-mail</dt><dd><?= e($clinic['email'] ?: '—') ?></dd>
    </dl>
  </div>
</div>
