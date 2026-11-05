<?php declare(strict_types=1);
use Kulturhus\Verein\TaxFilingScope;
$canSend=($runtime['profiles'][$filing['data_version']]['transport_enabled']??false)===true;
$formHidden=csrf().hidden('filing',$fid).hidden('version',(string)$filing['version']).hidden('tax_year',(string)$year);
?>
<section class="card" data-tax-local-send-review><h2><?=e($ft('Ausdrückliche Abgabefreigabe'))?></h2>
<?php if($evidenceParent&&!str_starts_with($evidenceParent['state'],'sent_')):?><a class="btn btn-solid theme-primary" href="<?=e(url($base+['filing'=>$evidenceParent['id']]))?>"><?=e($ft('Weiter: Erklärung und Belege abgeben'))?></a>
<?php elseif(!$evidence&&\Kulturhus\Verein\TaxFilingEvidence::needsEvidence($filing)):?><p><?=e($ft('Die bereits ausgewählten Nachweise und der freigegebene Tätigkeitsbericht sollen mitgesendet werden.'))?></p><button class="btn btn-solid theme-primary" form="tax-local-form" name="action" value="tax-filing-evidence" formnovalidate><?=e($ft('Weiter: PDF-Belege prüfen'))?></button>
<?php elseif($evidence&&$evidence['state']!=='validated'):?><p><?=e($ft('Bitte den Belegauftrag zuerst prüfen. Die Steuererklärung wurde noch nicht gesendet.'))?></p><a class="btn btn-solid theme-primary" href="<?=e(url($base+['filing'=>$evidence['id']]))?>"><?=e($ft('Belegauftrag prüfen'))?></a>
<?php elseif(!$canSend||($filing['environment']==='production'&&!($runtime['profiles'][$filing['data_version']]['production_allowed']??false))):?><p><a href="<?=e(TaxFilingScope::families()[$filing['family']]['url'])?>" target="_blank" rel="noopener noreferrer"><?=e($ft('In Mein ELSTER bearbeiten ↗'))?></a></p>
<?php elseif($filing['environment']==='production'&&!\Kulturhus\Verein\BoardAccess::allowed($db,(string)$_SESSION['actor'])):?><p><?=e($ft('Die echte Abgabe muss vom berechtigten Vorstand freigegeben werden.'))?></p>
<?php else:?>
<form method="post" enctype="multipart/form-data" autocomplete="off"><?= $formHidden.hidden('action','tax-filing-send').hidden('confirmation','SEND-'.strtoupper($filing['environment'])) ?>
<?php if($evidence): ?><?= hidden('evidence_id',$evidence['id']).hidden('evidence_version',$evidence['version']) ?><?php endif ?>
<label class="check"><input class="check" type="checkbox" name="reviewed" value="1" required><?= e($evidence?$ft('Ich habe die Vorschau der Erklärung, des Belegauftrags und alle ausgewählten PDFs geprüft und bin zur Übermittlung berechtigt.'):$ft('Ich habe die vollständige Vorschau und gegebenenfalls die beigefügten PDFs geprüft und bin zur Übermittlung berechtigt.')) ?></label>
<?php if($filing['environment']==='test'): ?><label class="check"><input class="check" type="checkbox" name="synthetic" value="1" required><?=e($ft('Diese Erklärung enthält ausschließlich erfundene Testdaten.'))?></label><?php endif ?>
<?php require __DIR__.'/tax-certificate-input.php'; ?>
<button class="btn btn-solid theme-primary"><?= e($filing['environment']==='test'?$ft('Test jetzt senden'):($evidence?$ft('Erklärung und ausgewählte Belege verbindlich abgeben'):($filing['family']==='receipts'?$ft('Ausgewählte Belege verbindlich senden'):$ft('Steuererklärung jetzt verbindlich abgeben')))) ?></button></form><?php endif?></section>
