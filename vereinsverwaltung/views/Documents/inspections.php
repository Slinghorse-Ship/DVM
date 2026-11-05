<?php declare(strict_types=1);
use Kulturhus\Verein\DocumentWorkflow\InspectionStore;
use Kulturhus\Verein\Documents\HseInterfaceText;

$is=$workflowManager->inspections;$ia=$is->actor();$iw=$ia['access']==='write';
$inspectionMessages=$documentActionMessages??[];
$inspectionLocale=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->locale():'de';
$it=static fn(string $source):string=>HseInterfaceText::text($source,$inspectionMessages);
$ifmt=static fn(string $source,array $values):string=>HseInterfaceText::format($source,$values,$inspectionMessages);
$idate=static fn(string $source):string=>HseInterfaceText::date($source,$inspectionLocale,$inspectionMessages);
$itime=static fn(string $source):string=>HseInterfaceText::timestamp($source,$inspectionLocale);
$ipost=$error!==''&&($_SERVER['REQUEST_METHOD']??'')==='POST'
    &&str_starts_with(input($_POST,'action'),'document-workflow-inspection-')
    &&is_string($_POST['csrf']??null)&&isset($_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf']);
$in=$ipost?$_POST:$_GET;
$filter=['query'=>input($in,'inspection_query'),'status'=>input($in,'inspection_status','active'),'scope'=>input($in,'inspection_scope','all'),'page'=>input($in,'inspection_page','1')];
$invalidFilter=false;
try{$state=InspectionStore::state($filter);}
catch(DomainException $failure){$invalidFilter=true;$error=$it($failure->getMessage());if(http_response_code()<400)http_response_code(422);$state=InspectionStore::state([]);}
$catalogue=$invalidFilter?['rows'=>[],'total'=>0,'pages'=>1,'state'=>$state]:$is->listing($state);$state=$catalogue['state'];
$shownFilter=$invalidFilter?$filter:$state;
$base=['view'=>'documents','tab'=>'inspections','inspection_query'=>$state['query'],'inspection_status'=>$state['status'],'inspection_scope'=>$state['scope'],'inspection_page'=>$state['page']];
$iu=static fn(array $extra=[]):string=>'?'.http_build_query(array_replace($base,$extra),'','&',PHP_QUERY_RFC3986);
$id=$ipost?input($_POST,'wf_id'):input($_GET,'inspection');
$new=$iw&&($id===''&&(isset($_GET['new_inspection'])||($ipost&&input($_POST,'action')==='document-workflow-inspection-create')));
$task=$id!==''?$is->get($id):null;$source=$task['source']??[];
if($new){
    $sourceDoc=input($in,'inspection_source_doc',input($_GET,'inspection_source_doc'));
    if($sourceDoc!==''){
        $revision=input($in,'inspection_source_revision',input($_GET,'inspection_source_revision'));
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$revision))throw new DomainException('Beurteilungsfassung prüfen.');
        $source=$is->source($sourceDoc,(int)$revision);
    }
}
$people=$iw?$is->participants():[];
$names=$is->names([...array_column($catalogue['rows'],'id'),...($id!==''?[$id]:[])],true);
$personName=static fn(string $actor):string=>($names[$actor]['available']??false)?$names[$actor]['label']:$it('Person nicht mehr verfügbar');
$posted=static fn(string $action,array $transitions=[]):bool=>$ipost&&input($_POST,'action')===$action&&(!$transitions||in_array(input($_POST,'inspection_transition'),$transitions,true));
$fieldPost=$posted('document-workflow-inspection-create')||$posted('document-workflow-inspection-update');
$value=static fn(string $key,string $column,string $fallback=''):string=>$fieldPost&&is_string($_POST[$key]??null)?$_POST[$key]:($task[$column]??$fallback);
$fields=static function()use($value,$people,$it,$ifmt):void{?>
<label><?= e($it('Titel')) ?><input class="form-control" name="inspection_title" required value="<?= e($value('inspection_title','title')) ?>"><small><?= e($ifmt('Bis zu {count} Zeichen.',['count'=>240])) ?></small></label>
<label><?= e($it('Vorgabe zur Umsetzung')) ?><textarea class="form-control" name="inspection_instruction" rows="4" required><?= e($value('inspection_instruction','instruction')) ?></textarea><small><?= e($ifmt('Bis zu {count} Zeichen.',['count'=>8000])) ?></small></label>
<div class="inspection-columns">
<label><?= e($it('Zuständige Person')) ?><select class="form-control" name="inspection_assignee" required><option value=""><?= e($it('Person auswählen')) ?></option>
<?php $selected=$value('inspection_assignee','assignee_id');if($selected!==''&&!in_array($selected,array_column($people,'id'),true)):?><option value="<?= e($selected) ?>" selected><?= e($it('Zuordnung nicht mehr verfügbar')) ?></option><?php endif ?>
<?php foreach($people as$person):?><option value="<?= e($person['id']) ?>" <?= $selected===$person['id']?'selected':'' ?>><?= e($person['name']) ?></option><?php endforeach ?>
</select></label>
<label><?= e($it('Frist')) ?><input class="form-control" name="inspection_deadline" type="date" value="<?= e($value('inspection_deadline','deadline')) ?>"></label>
</div>
<?php };
$common=static function(string $action,array $transitions=[])use($base,$id,$task,$posted):void{
    echo csrf().hidden('action',$action).hidden('wf_id',$id).hidden('wf_version',$posted($action,$transitions)?input($_POST,'wf_version'):(string)($task['version']??0));
    foreach($base as$key=>$value)if(str_starts_with($key,'inspection_'))echo hidden($key,$value);
};
$sourceText=static fn(array $reference):string=>$ifmt('Bezug: {title} · Beurteilungsfassung {revision}',['title'=>$reference['title'],'revision'=>$reference['revision']]);
?>
<link rel="stylesheet" href="inspections.css?v=2">
<section class="content inspection-workspace" aria-label="<?= e($it('Gefährdungsprüfung')) ?>">
<header class="inspection-heading"><h1><?= e($it('Gefährdungsprüfung')) ?></h1><?php if($iw&&!$new):?><a class="btn btn-solid theme-primary" href="<?= e($iu(['new_inspection'=>'1'])) ?>"><?= e($it('Prüfauftrag erteilen')) ?></a><?php endif ?></header>
<nav class="module-tabs" aria-label="<?= e($it('HSE-Bereiche')) ?>"><?php if($ia['access']!=='none'):?><a href="?view=documents&amp;tab=workflows"><?= e($it('Gefährdungsbeurteilung')) ?></a><?php endif ?><a href="<?= e($iu()) ?>" aria-current="page"><?= e($it('Gefährdungsprüfung')) ?></a></nav>
<?php if($error!==''):?><p class="error" role="alert"><?= e($it($error)) ?></p><?php endif ?>
<?php if($new||$task):?>
<section class="card inspection-focus"><header class="inspection-heading"><h2><?= e($new?$it('Prüfauftrag erteilen'):$task['title']) ?></h2><a class="btn btn-outline theme-secondary" href="<?= e($iu()) ?>"><?= e($it('Schließen')) ?></a></header>
<?php if($source):?><p class="inspection-source"><?= e($sourceText($source)) ?><?php if($ia['access']!=='none'):?> · <a href="<?= e('?'.http_build_query(['view'=>'documents','tab'=>'workflows','wf_doc'=>$source['id'],'wf_revision'=>$source['revision']])) ?>"><?= e($it('Beurteilung öffnen')) ?></a><?php endif ?></p><?php endif ?>
<?php if($new):?>
<form method="post" action="<?= e($iu(['new_inspection'=>'1','inspection_source_doc'=>$source['id']??'','inspection_source_revision'=>$source['revision']??0])) ?>" class="inspection-create">
<?php $common('document-workflow-inspection-create');echo hidden('inspection_request_key',$posted('document-workflow-inspection-create')?input($_POST,'inspection_request_key'):bin2hex(random_bytes(16))).hidden('inspection_source_doc',$source['id']??'').hidden('inspection_source_revision',$source['revision']??0);$fields(); ?>
<button class="btn btn-solid theme-primary"><?= e($it('Auftrag erteilen')) ?></button>
</form>
<?php else:?>
<p><span class="inspection-status"><?= e($it(InspectionStore::STATUSES[$task['status']])) ?></span> · <?= e($personName($task['assignee_id'])) ?> · <?= e($idate($task['deadline'])) ?> · <?= e($ifmt('Stand {version}',['version'=>$task['version']])) ?></p>
<div class="inspection-instruction"><?= nl2br(e($task['instruction'])) ?></div>
<?php if($task['rework']!==''):?><div class="inspection-rework"><h3><?= e($it('Vorgabe zur Nacharbeit')) ?></h3><p><?= nl2br(e($task['rework'])) ?></p></div><?php endif ?>
<?php if($task['implementation']!==''):?><details class="inspection-evidence" <?= in_array($task['status'],['submitted','verified','returned'],true)?'open':'' ?>><summary><?= e($ifmt('Umsetzungsrückmeldung · {date}',['date'=>$idate($task['completed_on'])])) ?></summary><p><?= nl2br(e($task['implementation'])) ?></p></details><?php endif ?>
<?php if($task['assignee_id']===$ia['id']&&in_array($task['status'],['open','in_progress','returned'],true)):?>
<?php if($task['status']!=='in_progress'):?><form method="post" action="<?= e($iu(['inspection'=>$id])) ?>" class="inspection-start"><?php $common('document-workflow-inspection-transition',['start']);echo hidden('inspection_transition','start').hidden('inspection_comment',''); ?><button class="btn btn-outline theme-secondary"><?= e($it('Umsetzung beginnen')) ?></button></form><?php endif ?>
<form method="post" action="<?= e($iu(['inspection'=>$id])) ?>" class="inspection-submit">
<?php $common('document-workflow-inspection-transition',['submit']);echo hidden('inspection_transition','submit').hidden('inspection_comment',''); ?>
<label><?= e($it('Was wurde umgesetzt?')) ?><textarea class="form-control" name="inspection_implementation" rows="4" required><?= e($posted('document-workflow-inspection-transition',['submit'])?input($_POST,'inspection_implementation'):$task['implementation']) ?></textarea><small><?= e($ifmt('Bis zu {count} Zeichen.',['count'=>8000])) ?></small></label>
<label><?= e($it('Umgesetzt am')) ?><input class="form-control" type="date" name="inspection_completed_on" required value="<?= e($posted('document-workflow-inspection-transition',['submit'])?input($_POST,'inspection_completed_on'):$task['completed_on']) ?>"></label>
<button class="btn btn-solid theme-primary"><?= e($it('Umsetzung zur Abnahme melden')) ?></button>
</form>
<?php endif ?>
<?php if($iw&&$task['status']==='submitted'):?>
<form method="post" action="<?= e($iu(['inspection'=>$id])) ?>" class="inspection-review"><?php $common('document-workflow-inspection-transition',['verify','return']); ?>
<label><?= e($it('Prüfrückmeldung')) ?><textarea class="form-control" name="inspection_comment" rows="3"><?= e($posted('document-workflow-inspection-transition',['verify','return'])?input($_POST,'inspection_comment'):'') ?></textarea><small><?= e($ifmt('Bis zu {count} Zeichen.',['count'=>8000])) ?></small></label>
<div class="inspection-actions"><button class="btn btn-solid theme-primary" name="inspection_transition" value="verify"><?= e($it('Umsetzung abnehmen')) ?></button><button class="btn btn-outline theme-secondary" name="inspection_transition" value="return"><?= e($it('Nacharbeit vorgeben')) ?></button></div>
</form>
<?php endif ?>
<?php if($iw):?>
<?php if(in_array($task['status'],['open','in_progress','returned'],true)):?><details class="inspection-edit" <?= $posted('document-workflow-inspection-update')?'open':'' ?>><summary><?= e($it('Auftrag bearbeiten')) ?></summary><form method="post" action="<?= e($iu(['inspection'=>$id])) ?>"><?php $common('document-workflow-inspection-update');$fields(); ?><button class="btn btn-outline theme-secondary"><?= e($it('Auftrag speichern')) ?></button></form></details><?php endif ?>
<?php if($task['status']!=='cancelled'):?>
<?php $stateTransition=$task['status']==='verified'?'reopen':'cancel'; ?>
<details class="inspection-state" <?= $posted('document-workflow-inspection-transition',[$stateTransition])?'open':'' ?>><summary><?= e($it($stateTransition==='reopen'?'Erneute Prüfung':'Auftrag abbrechen')) ?></summary>
<form method="post" action="<?= e($iu(['inspection'=>$id])) ?>"><?php $common('document-workflow-inspection-transition',[$stateTransition]);echo hidden('inspection_transition',$stateTransition); ?>
<label><?= e($it($stateTransition==='reopen'?'Neue Vorgabe zur Nacharbeit':'Grund')) ?><textarea class="form-control" name="inspection_comment" required rows="3"><?= e($posted('document-workflow-inspection-transition',[$stateTransition])?input($_POST,'inspection_comment'):'') ?></textarea><small><?= e($ifmt('Bis zu {count} Zeichen.',['count'=>8000])) ?></small></label>
<button class="btn btn-outline theme-secondary"><?= e($it($stateTransition==='reopen'?'Zur Nacharbeit öffnen':'Auftrag abbrechen')) ?></button></form></details>
<?php endif ?>
<?php endif ?>
<?php if($ipost):?><a class="btn btn-outline theme-secondary inspection-current" href="<?= e($iu(['inspection'=>$id])) ?>"><?= e($it('Aktuellen Stand öffnen')) ?></a><?php endif ?>
<details class="inspection-history" <?= isset($_GET['inspection_history_page'])?'open':'' ?>><summary><?= e($ifmt('Verlauf · {count} Stände',['count'=>$task['version']])) ?></summary>
<?php
$hp=input($_GET,'inspection_history_page','1');
if(!preg_match('/\A[1-9][0-9]{0,4}\z/D',$hp)){$error=$it('Verlaufsseite prüfen.');if(http_response_code()<400)http_response_code(422);$hp='1';echo '<p role="alert">'.e($error).'</p>';}
$history=$is->history($id,(int)$hp);
$actions=['create'=>'Auftrag erteilt','update'=>'Auftrag geändert','start'=>'Umsetzung begonnen','submit'=>'Umsetzung gemeldet','verify'=>'Umsetzung abgenommen','return'=>'Nacharbeit vorgegeben','reopen'=>'Erneute Prüfung','cancel'=>'Auftrag abgebrochen'];
foreach($history['rows']as$event):$snapshot=$event['snapshot']; ?>
<details class="inspection-history-entry" data-inspection-revision="<?= (int)$event['revision'] ?>"><summary><?= e($it($actions[$event['action']]??$event['action'])) ?> · <?= e($ifmt('Stand {version}',['version'=>$event['revision']])) ?></summary>
<p><?= e($event['actor_available']?$event['actor_name']:$it('Person nicht mehr verfügbar')) ?> · <time><?= e($itime($event['created_at'])) ?></time> · <?= e($it('Zeitpunkt (Europe/Berlin)')) ?></p>
<?php if($event['comment']!==''):?><p class="inspection-history-comment"><?= nl2br(e($event['comment'])) ?></p><?php endif ?>
<dl class="inspection-history-values">
<dt><?= e($it('Gespeicherter Titel')) ?></dt><dd data-inspection-original="title"><?= e($snapshot['title']) ?></dd>
<dt><?= e($it('Vorgabe')) ?></dt><dd data-inspection-original="instruction"><?= nl2br(e($snapshot['instruction'])) ?></dd>
<dt><?= e($it('Gespeicherte Zuständigenkennung')) ?></dt><dd data-inspection-original="assignee_id"><?= e($snapshot['assignee_id']) ?></dd>
<dt><?= e($it('Gespeicherte Frist')) ?></dt><dd data-inspection-original="deadline"><?= e($idate($snapshot['deadline'])) ?></dd>
<dt><?= e($it('Gespeicherter Umsetzungsstand')) ?></dt><dd><?= e($it(InspectionStore::STATUSES[$snapshot['status']])) ?></dd>
<dt><?= e($it('Umsetzung')) ?></dt><dd data-inspection-original="implementation"><?= nl2br(e($snapshot['implementation'])) ?></dd>
<dt><?= e($it('Gespeicherter Umsetzungstag')) ?></dt><dd data-inspection-original="completed_on"><?= e($snapshot['completed_on']!==''?$idate($snapshot['completed_on']):$it('Keine Angabe')) ?></dd>
</dl>
<?php $originalSource=json_decode($snapshot['source_json'],true,64,JSON_THROW_ON_ERROR); ?><p class="inspection-history-source"><?= e($originalSource?$sourceText($originalSource):$it('Kein Bezug zu einer Beurteilung.')) ?></p>
</details>
<?php endforeach ?>
<nav class="inspection-actions" aria-label="<?= e($it('Verlaufsseiten')) ?>"><?php if($history['page']>1):?><a class="btn btn-outline theme-secondary" href="<?= e($iu(['inspection'=>$id,'inspection_history_page'=>$history['page']-1])) ?>"><?= e($it('Neuere Stände')) ?></a><?php endif ?><span><?= e($ifmt('Seite {page} von {pages}',['page'=>$history['page'],'pages'=>$history['pages']])) ?></span><?php if($history['page']<$history['pages']):?><a class="btn btn-outline theme-secondary" href="<?= e($iu(['inspection'=>$id,'inspection_history_page'=>$history['page']+1])) ?>"><?= e($it('Ältere Stände')) ?></a><?php endif ?></nav>
</details>
<?php endif ?>
</section>
<?php endif ?>
<details class="card inspection-list" <?= $invalidFilter||(!$task&&!$new)?'open':'' ?>><summary><?= e($ifmt('Prüfaufträge · {count}',['count'=>$catalogue['total']])) ?></summary>
<form method="get" class="inspection-filter"><?= hidden('view','documents').hidden('tab','inspections') ?>
<label><?= e($it('Suche')) ?><input class="form-control" name="inspection_query" type="search" value="<?= e($shownFilter['query']) ?>"><small><?= e($ifmt('Bis zu {count} Zeichen.',['count'=>240])) ?></small></label>
<label><?= e($it('Status')) ?><select class="form-control" name="inspection_status">
<?php $statusOptions=['active'=>'Aktive','overdue'=>'Überfällige',...InspectionStore::STATUSES,'all'=>'Alle'];if(!isset($statusOptions[$shownFilter['status']])): ?><option value="<?= e($shownFilter['status']) ?>" selected><?= e($ifmt('Auswahl prüfen: {value}',['value'=>$shownFilter['status']])) ?></option><?php endif ?>
<?php foreach($statusOptions as$key=>$label):?><option value="<?= e($key) ?>" <?= $shownFilter['status']===$key?'selected':'' ?>><?= e($it($label)) ?></option><?php endforeach ?></select></label>
<label><?= e($it('Zuordnung')) ?><select class="form-control" name="inspection_scope">
<?php if(!in_array($shownFilter['scope'],['all','mine'],true)):?><option value="<?= e($shownFilter['scope']) ?>" selected><?= e($ifmt('Auswahl prüfen: {value}',['value'=>$shownFilter['scope']])) ?></option><?php endif ?>
<?php foreach(['all'=>'Alle verfügbaren','mine'=>'Meine Aufträge']as$key=>$label):?><option value="<?= e($key) ?>" <?= $shownFilter['scope']===$key?'selected':'' ?>><?= e($it($label)) ?></option><?php endforeach ?>
</select></label><button class="btn btn-outline theme-secondary"><?= e($it('Filtern')) ?></button></form>
<div class="inspection-cards"><?php foreach($catalogue['rows']as$row):?><article class="inspection-card" data-inspection-id="<?= e($row['id']) ?>"><p class="inspection-status"><?= e($it(InspectionStore::STATUSES[$row['status']])) ?></p><h3><?= e($row['title']) ?></h3><p><?= e($row['instruction']) ?></p><p><?= e($personName($row['assignee_id'])) ?> · <?= e($idate($row['deadline'])) ?></p><a class="btn btn-outline theme-secondary" href="<?= e($iu(['inspection'=>$row['id']])) ?>"><?= e($it('Auftrag öffnen')) ?></a></article><?php endforeach ?><?php if(!$catalogue['rows']):?><p><?= e($it($invalidFilter?'Suchangaben korrigieren.':'Keine passenden Prüfaufträge.')) ?></p><?php endif ?></div>
<nav class="inspection-actions" aria-label="<?= e($it('Prüfauftragsseiten')) ?>"><?php if($state['page']>1):?><a class="btn btn-outline theme-secondary" href="<?= e($iu(['inspection_page'=>$state['page']-1])) ?>"><?= e($it('Zurück')) ?></a><?php endif ?><span><?= e($ifmt('Seite {page} von {pages}',['page'=>$state['page'],'pages'=>$catalogue['pages']])) ?></span><?php if($state['page']<$catalogue['pages']):?><a class="btn btn-outline theme-secondary" href="<?= e($iu(['inspection_page'=>$state['page']+1])) ?>"><?= e($it('Weiter')) ?></a><?php endif ?></nav>
</details>
</section>
