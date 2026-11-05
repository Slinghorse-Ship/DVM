<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Banking\Notice\{DebitDraft,DebitText};
$nt=static fn(string $text):string=>\Kulturhus\Native\Language\DebitNoticeMessages::text($text,$debitMessages);
$nu=static fn(array $query=[]):string=>url($debitUrl($query));
$nf=static function(string $name,string $value,bool $readonly=false,string $type='text'):void{
    if($type==='date'&&$value!==''&&(!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$value,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])))$type='text';
    if($type==='textarea'||strpbrk($value,"\r\n")!==false):?><textarea class="form-control" name="<?=e($name)?>"<?=$readonly?' readonly':''?>><?="\n".e($value)?></textarea><?php else:?><input class="form-control" type="<?=e($type)?>" name="<?=e($name)?>" value="<?=e($value)?>"<?=$readonly?' readonly':''?>><?php endif;
};
?>
<link rel="stylesheet" href="general-settings-native.css?v=3"><link rel="stylesheet" href="debit-notice-native.css?v=1">
<section id="debit-notice" class="general-settings-native general-settings-values" data-debit-notice-native>
<h2><?=e($nt('Lastschriftvorankündigung prüfen und erzeugen'))?></h2>
<p><?=e($nt('Diese Vorankündigung verwendet die ausgegebene Originalrechnung, das aktuelle Mandat und die freigegebene Briefvorlage. Die Erzeugung bestätigt keinen Versand und keinen Bankauftrag. Rechtzeitige tatsächliche Vorabinformation vor dem Einzug gesondert sicherstellen.'))?></p>
<?php if(!$debitState):?><p role="alert"><?=e($nt('Vorankündigungsbereich konnte nicht geladen werden.'))?></p><?php return;endif ?>
<?php foreach($debitState['errors']as$failure):if($nt($failure)!==$error):?><p role="alert"><?=e($nt($failure))?></p><?php endif;endforeach ?>
<?php if(!$debitState['can_write']):?><p role="status"><?=e($nt('Eigene frühere Bestätigungen bleiben mit Finanzleserecht erreichbar. Neue Lastschriftvorankündigungen benötigen Finanzschreibrecht und Zugriff auf das ursprüngliche Dokument.'))?></p><?php endif ?>
<?php if($source=$debitState['source']):?><section class="card" data-debit-source>
<h3><?=e($nt($debitState['receipt']?'Geprüfte Rechnung und Mandat':'Rechnung und aktuelles Mandat'))?></h3>
<dl><dt><?=e($nt('Rechnungsnummer'))?></dt><dd><?=e($source['entry']['display_number'])?></dd>
<dt><?=e($nt('Briefvorlage'))?></dt><dd><?=e($nt($source['template']==='texts_contributions'?'Beitrags-Lastschrift':'Rechnungs-Lastschrift'))?></dd>
<dt><?=e($nt('Ursprünglicher Empfänger und Anschrift'))?></dt><dd><pre><?="\n".e($source['recipient'])?></pre></dd>
<dt><?=e($nt('Einzugsbetrag'))?></dt><dd><?=e(euro($source['amount_cents']))?></dd>
<?php if(isset($source['funding'])):?><dt><?=e($nt('Ursprünglicher Rechnungsbetrag'))?></dt><dd data-debit-funding><?=e(euro($source['funding']['face_cents']))?></dd><dt><?=e($nt('Bereits bezahlt'))?></dt><dd><?=e(euro($source['funding']['paid_cents']))?></dd><?php endif ?>
<dt><?=e($nt('Mandatsreferenz'))?></dt><dd><?=e($source['mandate']['reference'])?></dd>
<dt><?=e($nt('Gläubiger-ID'))?></dt><dd><?=e($source['mandate']['creditor_id'])?></dd>
<dt><?=e($nt('Mitgliedskonto'))?></dt><dd><?=e($source['mandate']['iban_masked'])?></dd></dl>
<a class="btn btn-outline theme-secondary" data-debit-claim href="<?=e(url(['document_file'=>$debitDocument,'revision'=>$source['file']['revision'],'preview'=>1]))?>" target="_blank" rel="noopener"><?=e($nt('Genau das ursprüngliche Rechnungs-PDF öffnen'))?></a>
</section><?php endif ?>
<?php if($first=$debitState['receipt']):?><section class="card" data-debit-first="<?=e($first['key'])?>">
<h3><?=e($nt('Vorankündigungsbestätigung'))?></h3><p><?=e(localDateTime($first['created_at']))?></p>
<h4><?=e($nt('Eigener Grund'))?></h4><pre><?="\n".e($first['input']['reason'])?></pre>
<a class="btn btn-solid theme-primary" download data-debit-pdf href="<?=e($nu(['debit_pdf'=>$first['key']]))?>"><?=e($nt('Genau dieses ursprüngliche Lastschriftvorankündigung-PDF herunterladen'))?></a>
<a class="btn btn-outline theme-secondary" download data-debit-original href="<?=e($nu(['debit_original'=>$first['key']]))?>"><?=e($nt('Genau diese persönliche Erstbestätigung herunterladen'))?></a>
<a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'documents','doc'=>$first['result']['document_id']]))?>"><?=e($nt('Gespeicherte Lastschriftvorankündigung in der Dokumentablage öffnen'))?></a>
<?php if($debitState['can_write']):?><a class="btn btn-outline theme-secondary" href="<?=e($nu(['debit_new'=>'1']))?>#debit-notice"><?=e($nt('Eine weitere Lastschriftvorankündigung bewusst neu öffnen'))?></a><?php endif ?>
</section><?php endif ?>
<?php if(!$debitState['receipt']&&$debitDraft&&$debitDraft['accepted']===null):$raw=$debitDraft['input'];$review=$debitDraft['review'];$readonly=$debitDraft['pending']||!$debitState['can_write'];?><section class="card" data-debit-editor>
<?php if($debitDraft['pending']):?><p role="status"><?=e($nt('Vorankündigungsausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.'))?></p><a class="btn btn-outline theme-secondary" href="<?=e($nu(['debit_draft'=>$debitDraft['id'],'debit_first'=>$debitDraft['key']]))?>#debit-notice"><?=e($nt('Ursprünglichen Vorankündigungsausgang lesend prüfen'))?></a><?php endif ?>
<form method="post" action="<?=e($nu())?>#debit-notice" novalidate data-debit-form data-confirm-other-drafts>
<?=csrf().hidden('action','debit-notice-native').hidden('debit_draft',$debitDraft['id']).hidden('debit_revision',DebitDraft::revision($debitDraft))?>
<label><?=e($nt('Briefdatum'))?><?php $nf('day',$raw['day'],$readonly,'date');?></label>
<label><?=e($nt('Einzugsdatum'))?><?php $nf('collection',$raw['collection'],$readonly,'date');?></label>
<?php foreach(['subject'=>'Betreff','intro'=>'Einleitung','message'=>'Brieftext','closing'=>'Schlusstext']as$field=>$label):?><label><?=e($nt($label))?><?php $nf('text['.$field.']',$raw['text'][$field],$readonly,$field==='subject'?'text':'textarea');?></label><?php endforeach ?>
<label><?=e($nt('Eigener Grund für diese Lastschriftvorankündigung'))?><?php $nf('reason',$raw['reason'],$readonly,'textarea');?></label>
<p><?=e($nt('Betreff bis 200 Zeichen, Einleitung, Brieftext und Schlusstext jeweils bis 6.000 Zeichen, eigener Grund bis 2.000 Zeichen. Ganze Angaben bleiben bei Fehlern erhalten.'))?></p>
<div class="general-settings-actions"><?php if($debitState['can_write']&&!$debitDraft['pending']):?><button class="btn btn-solid theme-primary" name="debit_intent" value="preview"><?=e($nt('Ganze Lastschriftvorankündigung unverbindlich prüfen'))?></button><?php endif;if(!$debitDraft['pending']):?><button class="btn btn-outline theme-secondary" name="debit_intent" value="discard"><?=e($nt('Eigenen ungespeicherten Briefentwurf verwerfen'))?></button><?php endif;if($debitState['can_write']&&($debitDraft['pending']||$debitDraft['conflicted'])):?><button class="btn btn-outline theme-secondary" name="debit_intent" value="current"><?=e($nt('Ganze Briefangaben mit aktuellem Stand erneut prüfen'))?></button><?php endif ?></div>
<?php if($review&&!$debitDraft['pending']):?><section class="card" data-debit-review>
<h3><?=e($nt('Geprüfte, noch nicht gespeicherte Lastschriftvorankündigung'))?></h3>
<?php try{$rendered=DebitText::document($review['source']+['assets'=>[]],$review['input']);?><h4><?=e($rendered['title'])?></h4><pre data-debit-resolved><?="\n".e($rendered['text']['message'])?></pre><?php }catch(DomainException $failure){?><p role="alert"><?=e($nt($failure->getMessage()))?></p><?php } ?>
<?php if($debitState['can_write']):$previewUrl=$nu(['debit_draft'=>$debitDraft['id'],'debit_preview_pdf'=>$debitDraft['key']]);require __DIR__.'/../../Documents/pdf-viewer.php';?>
<?=hidden('debit_key',$debitDraft['key'])?><label class="general-settings-check"><input class="check" type="checkbox" name="debit_approved" value="1"><span><?=e($nt('Genau diese Originalrechnung, Anschrift, Mandatsreferenz, Gläubiger-ID, Betrag, Einzugsdatum und alle ganzen Briefangaben einschließlich der PDF-Vorschau sind geprüft.'))?></span></label>
<button class="btn btn-solid theme-primary" name="debit_intent" value="accept"><?=e($nt('Geprüfte Lastschriftvorankündigung bewusst erzeugen'))?></button><?php endif ?>
</section><?php endif ?></form></section><?php endif ?>
<?php if(!$debitDraft&&!$debitState['receipt']&&$debitState['can_write']&&$debitState['errors']===[]):?><a class="btn btn-outline theme-secondary" href="<?=e($nu(['debit_new'=>'1']))?>#debit-notice"><?=e($nt('Eigene Lastschriftvorankündigung bewusst öffnen'))?></a><?php endif ?>
<?php $history=$debitState['history'];$historyQuery=is_string($_GET['debit_history_q']??'')?($_GET['debit_history_q']??''):'';$historyPage=is_string($_GET['debit_history_page']??'1')?($_GET['debit_history_page']??'1'):'';?><section class="card" data-debit-history>
<h3><?=e($nt('Eigene frühere Lastschriftvorankündigungen'))?></h3><form method="get" class="general-settings-page-form"><?=hidden('view','documents').hidden('doc',$debitDocument).hidden('debit_entry',$debitEntry)?>
<label><?=e($nt('Ganze Vorankündigungssuche'))?><?php $nf('debit_history_q',$historyQuery,false,strpbrk($historyQuery,"\r\n")!==false?'textarea':'search');?></label><label><?=e($nt('Vorankündigungsseite'))?><?php $nf('debit_history_page',$historyPage);?></label><button class="btn btn-outline theme-secondary"><?=e($nt('Genau diese eigenen Lastschriftvorankündigungen anzeigen'))?></button></form>
<?php if($history&&$history['error']===''):?><p><?=e(str_replace(['{page}','{pages}','{count}'],[(string)$history['page'],(string)$history['pages'],(string)$history['total']],$nt('Seite {page} von {pages} · {count} eigene Bestätigungen')))?></p><ul><?php foreach($history['rows']as$row):?><li data-debit-history-row><a href="<?=e($nu(['debit_first'=>$row['request_key'],'debit_history_q'=>$history['query'],'debit_history_page'=>$history['page_input']]))?>#debit-notice"><?=e(str_replace('{date}',localDateTime($row['created_at']),$nt('Lastschriftvorankündigung vom {date}')))?></a></li><?php endforeach ?></ul><?php if(!$history['rows']):?><p><?=e($nt('Keine passenden eigenen Lastschriftvorankündigungen vorhanden.'))?></p><?php endif;endif ?>
</section></section>
