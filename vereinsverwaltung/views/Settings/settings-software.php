<?php declare(strict_types=1);
$softwareText=isset($managerText)&&is_callable($managerText)?$managerText:static fn(string $source):string=>$source;
$softwareLabel=static fn(string $source):string=>htmlspecialchars($softwareText($source),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<footer class="content" aria-label="<?= $softwareLabel('Software und Urheberrecht') ?>">
<section class="card">
<h2><?= $softwareLabel('Über die Software') ?></h2>
<p><strong><?= $softwareLabel('Vereinsmanager · © Jan Wehlage') ?></strong></p>
<p><?= $softwareLabel('Entwickelt von Jan Wehlage. Der Verein ist Nutzer der Software.') ?></p>
<p class="muted"><?= $softwareLabel('Für eingebundene Drittanbieter-Komponenten gelten deren eigene Lizenz- und Urheberhinweise.') ?></p>
</section>
</footer>
