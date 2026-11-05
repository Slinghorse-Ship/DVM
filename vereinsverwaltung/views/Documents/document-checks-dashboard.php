<?php if(\Kulturhus\Verein\BoardAccess::allowed($db,(string)$_SESSION['actor'])):
require_once __DIR__.'/../../src/Documents/HseInterfaceText.php';
$checkSummary=(new \Kulturhus\Verein\DocumentChecks($db,(string)$_SESSION['actor'],$registry))->summary();
$checkDashboardMessages=$documentActionMessages??(isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[]);
$checkDashboardLocale=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->locale():'de';
$checkDashboardText=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::text($source,$checkDashboardMessages);
?>
<section class="card" data-check-dashboard><div class="section-heading"><h2><?= e(\Kulturhus\Verein\Documents\HseInterfaceText::format('Nachweise & Fristen · {count} offen',['count'=>$checkSummary['open']],$checkDashboardMessages)) ?></h2><a href="?view=documents&amp;tab=checks"><?= e($checkDashboardText('Alle verwalten →')) ?></a></div>
<?php if(!$checkSummary['rows']):?><p><?= e($checkDashboardText('Keine fälligen Hinweise aus den erfassten Nachweisen. Noch nicht erfasste Pflichten werden nicht automatisch erkannt.')) ?></p><?php else:?><ul><?php foreach($checkSummary['rows']as$checkAlert):?><li><a href="?view=documents&amp;tab=checks&amp;check=<?= e(rawurlencode($checkAlert['id'])) ?>"><?= e($checkAlert['title']) ?></a> · <?= e($checkDashboardText($checkAlert['status']['label'])) ?><?php if($checkAlert['status']['due']!==''):?> · <?= e(\Kulturhus\Verein\Documents\HseInterfaceText::date($checkAlert['status']['due'],$checkDashboardLocale,$checkDashboardMessages)) ?><?php endif ?> · <?= e($checkAlert['responsible']!==''?$checkAlert['responsible']:$checkDashboardText('Zuständigkeit offen')) ?></li><?php endforeach ?></ul><?php endif ?></section>
<?php endif ?>
