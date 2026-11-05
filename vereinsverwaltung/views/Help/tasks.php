<?php declare(strict_types=1);
$taskHelpState=\Kulturhus\Verein\Web\TaskHelp::prepare($workspaceAccess,$registry->canWrite(),$finance->canWrite(),$_GET);
http_response_code($taskHelpState['status']);
$taskHelpText=static fn(string $source):string=>\Kulturhus\Native\Language\TaskHelpMessages::text($source,$managerLanguageMessages);
?>
<div class="page-heading"><h1><?=e($taskHelpText('Aufgabenhilfe'))?></h1></div>
<div class="content task-help" data-task-help="<?=e($taskHelpState['state'])?>">
<?php if(in_array($taskHelpState['state'],['invalid','blocked'],true)):?>
<section class="card" data-task-help-error><p role="alert"><?=e($taskHelpText($taskHelpState['message']))?></p>
<?php if($taskHelpState['raw_present']):?><h2><?=e($taskHelpText('Übermittelte Themenauswahl'))?></h2><?php if(!is_string($taskHelpState['raw'])):?><p><?=e($taskHelpText('Mehrteilige Angabe'))?></p><?php endif;?><pre><?=e(is_string($taskHelpState['raw'])?$taskHelpState['raw']:json_encode($taskHelpState['raw'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR))?></pre><?php endif;?>
<a class="btn btn-outline theme-secondary" data-task-help-index href="<?=e(url(['view'=>'help']))?>"><?=e($taskHelpText('Zur Hilfeübersicht'))?></a></section>
<?php elseif($taskHelpState['state']==='selected'):$taskHelpTopic=$taskHelpState['selected'];?>
<article class="card" data-task-help-article="<?=e($taskHelpState['raw'])?>"><h2><?=e($taskHelpText($taskHelpTopic['title']))?></h2><p><?=e($taskHelpText($taskHelpTopic['intro']))?></p>
<?php if(!$taskHelpTopic['can_edit']):?><p role="status" data-task-help-readonly><?=e($taskHelpText('Sie haben hier Lesezugriff. Änderungen benötigen das passende aktuelle Bearbeitungsrecht.'))?></p><?php endif;?>
<h3><?=e($taskHelpText('Schritt für Schritt'))?></h3><ol><?php foreach($taskHelpTopic['steps']as$step):?><li><?=e($taskHelpText($step))?></li><?php endforeach;?></ol>
<?php if($taskHelpTopic['links']):?><nav aria-label="<?=e($taskHelpText('Passenden Arbeitsbereich öffnen'))?>" class="task-help-actions"><?php foreach($taskHelpTopic['links']as[$label,$route,$anchor]):?><a class="btn btn-outline theme-secondary" data-task-help-destination href="<?=e(url($route).($anchor!==''?'#'.$anchor:''))?>"><?=e($taskHelpText($label))?></a><?php endforeach;?></nav><?php endif;?>
<p><?=e($taskHelpText('Diese Hilfe liest nur. Speichern, Freigeben und Zahlungen werden im jeweiligen Arbeitsbereich gesondert bestätigt.'))?></p><a class="btn btn-outline theme-secondary" data-task-help-index href="<?=e(url(['view'=>'help']))?>"><?=e($taskHelpText('Zur Hilfeübersicht'))?></a></article>
<?php else:?>
<section class="card"><h2><?=e($taskHelpText('Wobei brauchen Sie Hilfe?'))?></h2><p><?=e($taskHelpText('Wählen Sie eine Aufgabe. Die Anleitung nennt den vorhandenen Menüweg und den nächsten Schritt.'))?></p>
<nav aria-label="<?=e($taskHelpText('Verfügbare Aufgaben'))?>" class="task-help-topics"><?php foreach($taskHelpState['topics']as$id=>$topic):?><a class="btn btn-outline theme-secondary" data-task-help-topic="<?=e($id)?>" href="<?=e(url(['view'=>'help','help_topic'=>$id]))?>"><?=e($taskHelpText($topic['title']))?></a><?php endforeach;?></nav></section>
<?php endif;?></div>
