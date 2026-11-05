<?php declare(strict_types=1);require __DIR__.'/native-dashboard-common.php'; ?>
<section class="dashboard-native content" data-native-dashboard data-dashboard-personal>
<header class="dwf-heading"><div><h1><?= e($dashI('Mein Vereinsbereich')) ?></h1><p><?= e($dashI('Deine Informationen und die für dich freigegebenen Vereinsunterlagen.')) ?></p></div></header>
<?php $dashError('dashboard');$dashError('links');if($dashState['access']!==null):if($dashState['profile']===null):?><p class="dwf-alert" role="status"><?= e($dashI('Für persönliche Angaben ist kein verfügbares Mitgliederprofil verknüpft.')) ?></p><?php endif ?>
<?php require __DIR__.'/native-dashboard-setup.php'; ?><div class="dashboard-grid"><?php foreach($dashState['personal_links']as$dashLink):?><section class="dwf-card" data-dashboard-personal-link><h2><?= e($dashI($dashLink['label'])) ?></h2><p><?= e($dashI($dashLink['description'])) ?></p><a class="btn btn-outline theme-secondary dwf-button" href="<?= e(url($dashLink['route'])) ?>"><?= e($dashI('Öffnen →')) ?></a></section><?php endforeach ?></div>
<?php require __DIR__.'/native-dashboard-reviews.php';endif ?></section>
