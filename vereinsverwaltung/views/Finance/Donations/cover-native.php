<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Donations\Cover\{CoverDraft,CoverText};
$ct=static fn(string $text):string=>\Kulturhus\Native\Language\DonationCoverMessages::text($text,$coverMessages);
$cu=static fn(array $query=[]):string=>url($coverUrl($query));
$cf=static function(string $name,string $value,bool $readonly=false,string $type='text'):void{
    if($type==='date'&&$value!==''&&(!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$value,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])))$type='text';
    if($type==='textarea'||strpbrk($value,"\r\n")!==false):?><textarea class="form-control" name="<?=e($name)?>"<?=$readonly?' readonly':''?>><?="\n".e($value)?></textarea><?php else:?><input class="form-control" type="<?=e($type)?>" name="<?=e($name)?>" value="<?=e($value)?>"<?=$readonly?' readonly':''?>><?php endif;
};
?>
<link rel="stylesheet" href="general-settings-native.css?v=3"><link rel="stylesheet" href="donation-cover-native.css?v=1">
<section id="donation-cover" class="general-settings-native general-settings-values" data-donation-cover-native>
<h2><?=e($ct('Spendenbegleitschreiben prüfen und erzeugen'))?></h2>
<p><?=e($ct('Dieses Begleitschreiben verwendet die ausgestellte ursprüngliche Bescheinigung und die gespeicherte Briefvorlage. Es ändert oder unterschreibt keine Zuwendungsbescheinigung und versendet keine Nachricht.'))?></p>
<?php if(!$coverState):?><p role="alert"><?=e($ct('Begleitschreibenbereich konnte nicht geladen werden.'))?></p><?php return;endif ?>
<?php foreach($coverState['errors']as$failure):if($ct($failure)!==$error):?><p role="alert"><?=e($ct($failure))?></p><?php endif;endforeach ?>
<?php if(!$coverState['can_write']):?><p role="status"><?=e($ct('Eigene frühere Bestätigungen bleiben mit Finanzleserecht erreichbar. Neue Begleitschreiben benötigen Finanzschreibrecht und Zugriff auf das ursprüngliche Dokument.'))?></p><?php endif ?>
<?php if($source=$coverState['source']):?><section class="card" data-cover-source>
<h3><?=e($ct($coverState['receipt']?'Geprüfte Bescheinigung':'Tatsächlich ausgestellte Bescheinigung'))?></h3>
<dl><dt><?=e($ct('Ursprüngliche Bescheinigungsnummer'))?></dt><dd><?=e($source['receipt']['id'])?></dd><dt><?=e($ct('Briefvorlage'))?></dt><dd><?=e($ct($source['template']==='collective'?'Sammelspendenbegleitschreiben':'Spendenbegleitschreiben'))?></dd><dt><?=e($ct('Ursprünglicher Zuwender und Anschrift'))?></dt><dd><pre><?="\n".e($source['donor'])?></pre></dd><dt><?=e($ct('Ursprünglicher Zuwendungsbetrag'))?></dt><dd><?=e(euro($source['amount_cents']))?></dd></dl>
<a class="btn btn-outline theme-secondary" data-cover-certificate href="<?=e(url(['document_file'=>$coverDocument,'revision'=>1,'preview'=>1]))?>" target="_blank" rel="noopener"><?=e($ct('Genau das ursprüngliche Bescheinigungs-PDF öffnen'))?></a>
</section><?php endif ?>
<?php if($first=$coverState['receipt']):?><section class="card" data-cover-first="<?=e($first['key'])?>">
<h3><?=e($ct('Begleitschreibenbestätigung'))?></h3><p><?=e(localDateTime($first['created_at']))?></p>
<h4><?=e($ct('Eigener Grund'))?></h4><pre><?="\n".e($first['input']['reason'])?></pre>
<a class="btn btn-solid theme-primary" download data-cover-pdf href="<?=e($cu(['cover_pdf'=>$first['key']]))?>"><?=e($ct('Genau dieses ursprüngliche Begleitschreiben-PDF herunterladen'))?></a>
<a class="btn btn-outline theme-secondary" download data-cover-original href="<?=e($cu(['cover_original'=>$first['key']]))?>"><?=e($ct('Genau diese persönliche Erstbestätigung herunterladen'))?></a>
<a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'documents','doc'=>$first['result']['document_id']]))?>"><?=e($ct('Gespeichertes Begleitschreiben in der Dokumentablage öffnen'))?></a>
<?php if($coverState['can_write']):?><a class="btn btn-outline theme-secondary" href="<?=e($cu(['cover_new'=>'1']))?>#donation-cover"><?=e($ct('Ein weiteres Begleitschreiben bewusst neu öffnen'))?></a><?php endif ?>
</section><?php endif ?>
<?php if(!$coverState['receipt']&&$coverDraft&&$coverDraft['accepted']===null):$raw=$coverDraft['input'];$review=$coverDraft['review'];$readonly=$coverDraft['pending']||!$coverState['can_write'];?><section class="card" data-cover-editor>
<?php if($coverDraft['pending']):?><p role="status"><?=e($ct('Begleitschreibenausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.'))?></p><a class="btn btn-outline theme-secondary" href="<?=e($cu(['cover_draft'=>$coverDraft['id'],'cover_first'=>$coverDraft['key']]))?>#donation-cover"><?=e($ct('Ursprünglichen Begleitschreibenausgang lesend prüfen'))?></a><?php endif ?>
<form method="post" action="<?=e($cu())?>#donation-cover" novalidate data-cover-form data-confirm-other-drafts>
<?=csrf().hidden('action','donation-cover-native').hidden('cover_draft',$coverDraft['id']).hidden('cover_revision',CoverDraft::revision($coverDraft))?>
<label><?=e($ct('Briefdatum'))?><?php $cf('day',$raw['day'],$readonly,'date');?></label>
<?php foreach(['subject'=>'Betreff','intro'=>'Einleitung','message'=>'Brieftext','closing'=>'Schlusstext']as$field=>$label):?><label><?=e($ct($label))?><?php $cf('text['.$field.']',$raw['text'][$field],$readonly,$field==='subject'?'text':'textarea');?></label><?php endforeach ?>
<label><?=e($ct('Eigener Grund für dieses Begleitschreiben'))?><?php $cf('reason',$raw['reason'],$readonly,'textarea');?></label>
<p><?=e($ct('Betreff bis 200 Zeichen, Einleitung, Brieftext und Schlusstext jeweils bis 6.000 Zeichen, eigener Grund bis 2.000 Zeichen. Ganze Angaben bleiben bei Fehlern erhalten.'))?></p>
<div class="general-settings-actions"><?php if($coverState['can_write']&&!$coverDraft['pending']):?><button class="btn btn-solid theme-primary" name="cover_intent" value="preview"><?=e($ct('Ganzes Begleitschreiben unverbindlich prüfen'))?></button><?php endif;if(!$coverDraft['pending']):?><button class="btn btn-outline theme-secondary" name="cover_intent" value="discard"><?=e($ct('Eigenen ungespeicherten Briefentwurf verwerfen'))?></button><?php endif;if($coverState['can_write']&&($coverDraft['pending']||$coverDraft['conflicted'])):?><button class="btn btn-outline theme-secondary" name="cover_intent" value="current"><?=e($ct('Ganze Briefangaben mit aktuellem Stand erneut prüfen'))?></button><?php endif ?></div>
<?php if($review&&!$coverDraft['pending']):?><section class="card" data-cover-review>
<h3><?=e($ct('Geprüftes, noch nicht gespeichertes Begleitschreiben'))?></h3>
<?php try{$rendered=CoverText::document($review['source']+['assets'=>[]],$review['input']);?><h4><?=e($rendered['title'])?></h4><pre data-cover-resolved><?="\n".e($rendered['text']['message'])?></pre><?php }catch(DomainException $failure){?><p role="alert"><?=e($ct($failure->getMessage()))?></p><?php } ?>
<?php if($coverState['can_write']):$previewUrl=$cu(['cover_draft'=>$coverDraft['id'],'cover_preview_pdf'=>$coverDraft['key']]);require __DIR__.'/../../Documents/pdf-viewer.php';?>
<?=hidden('cover_key',$coverDraft['key'])?><label class="general-settings-check"><input class="check" type="checkbox" name="cover_approved" value="1"><span><?=e($ct('Genau diese ursprüngliche Bescheinigung, Anschrift, Betrag und alle ganzen Briefangaben einschließlich der PDF-Vorschau sind geprüft.'))?></span></label>
<button class="btn btn-solid theme-primary" name="cover_intent" value="accept"><?=e($ct('Geprüftes Begleitschreiben bewusst erzeugen'))?></button><?php endif ?>
</section><?php endif ?></form></section><?php endif ?>
<?php if(!$coverDraft&&!$coverState['receipt']&&$coverState['can_write']&&$coverState['errors']===[]):?><a class="btn btn-outline theme-secondary" href="<?=e($cu(['cover_new'=>'1']))?>#donation-cover"><?=e($ct('Eigenes Begleitschreiben bewusst öffnen'))?></a><?php endif ?>
<?php $history=$coverState['history'];$historyQuery=is_string($_GET['cover_history_q']??'')?($_GET['cover_history_q']??''):'';$historyPage=is_string($_GET['cover_history_page']??'1')?($_GET['cover_history_page']??'1'):'';?><section class="card" data-cover-history>
<h3><?=e($ct('Eigene frühere Begleitschreiben'))?></h3><form method="get" class="general-settings-page-form"><?=hidden('view','documents').hidden('doc',$coverDocument).hidden('cover_receipt',$coverReceipt)?>
<label><?=e($ct('Ganze Begleitschreibensuche'))?><?php $cf('cover_history_q',$historyQuery,false,strpbrk($historyQuery,"\r\n")!==false?'textarea':'search');?></label><label><?=e($ct('Begleitschreibenseite'))?><?php $cf('cover_history_page',$historyPage);?></label><button class="btn btn-outline theme-secondary"><?=e($ct('Genau diese eigenen Begleitschreiben anzeigen'))?></button></form>
<?php if($history&&$history['error']===''):?><p><?=e(str_replace(['{page}','{pages}','{count}'],[(string)$history['page'],(string)$history['pages'],(string)$history['total']],$ct('Seite {page} von {pages} · {count} eigene Bestätigungen')))?></p><ul><?php foreach($history['rows']as$row):?><li data-cover-history-row><a href="<?=e($cu(['cover_first'=>$row['request_key'],'cover_history_q'=>$history['query'],'cover_history_page'=>$history['page_input']]))?>#donation-cover"><?=e(str_replace('{date}',localDateTime($row['created_at']),$ct('Begleitschreiben vom {date}')))?></a></li><?php endforeach ?></ul><?php if(!$history['rows']):?><p><?=e($ct('Keine passenden eigenen Begleitschreiben vorhanden.'))?></p><?php endif;endif ?>
</section></section>
