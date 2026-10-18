<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? '') ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="print-page">
  <div class="print-actions no-print">
    <button class="btn btn-primary" type="button" data-print>Imprimer / PDF</button>
    <a class="btn btn-ghost" href="<?= e($backUrl ?? '/invoices/' . (int) $invoice['id']) ?>">Retour</a>
  </div>
  <?= $content ?>
  <script src="/assets/app.js"></script>
</body>
</html>
