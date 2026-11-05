<?php declare(strict_types=1);
$mnService=new \Kulturhus\Verein\EventVoucherMail($db,(string)$_SESSION['actor'],$websiteCalendar??null);
$mnState=$voucherMailState??$mnService->workspace($vmState,$_GET,$_SESSION,$_POST);
$mnDraft=$mnState['draft'];$mnBase=array_replace($vmBase,$mnState['query']);
if($mnDraft!==null&&in_array($mnDraft['action'],['event-voucher-mail','event-voucher-mail-all','event-voucher-mail-cancel'],true))$mnBase['voucher_resume']=$mnDraft['key'];
$mnUrl=static function(array $changes=[])use($mnBase,$mnState):string{
 $target=array_replace($mnBase,$changes);
 if((isset($changes['voucher_mail_section'])&&$changes['voucher_mail_section']!==$mnState['section'])||isset($changes['voucher_mail_person'])||isset($changes['voucher_mail_delivery']))unset($target['voucher_resume']);
 return url($target);
};
$mnForm=static function(array $draft):string{
 $html=csrf().hidden('voucher_mail_native','1').hidden('action',$draft['action']).hidden('voucher_event',$draft['event']).hidden('request_key',$draft['key']);
 if($draft['action']==='event-voucher-mail-template')$html.=hidden('template_version',$draft['version']);
 foreach(['contact_id','preview_hash','mail_id','expected_state','expected_at','expected_fingerprint']as$field)if(array_key_exists($field,$draft['values']))$html.=hidden($field,$draft['values'][$field]);
 return$html;
};
$mnStatus=['pending'=>'Vorgemerkt','sending'=>'Wird versendet','sent'=>'Versendet','uncertain'=>'Zustellung prüfen','blocked'=>'Nicht versendet – erneut prüfen','cancelled'=>'Beendet'];
$mnSearch=static function(string $section,string $label)use($mnBase,$mnState,$vmI):void{
 $search='voucher_mail_'.$section.'_query';$page='voucher_mail_'.$section.'_page';?>
 <form method="get" class="voucher-manager-search" data-live-search-page="<?= e($page) ?>"><?php foreach($mnBase as$field=>$value)if(!in_array($field,[$search,$page],true))echo hidden($field,$value);echo hidden($page,'1');?><label><?= e($vmI($label)) ?><input class="form-control" type="search" name="<?= e($search) ?>" value="<?= e($mnState['query'][$search]??'') ?>"></label><button class="btn btn-outline theme-secondary dwf-button"><?= e($vmI('Suchen')) ?></button></form>
<?php };
$mnPager=static fn(string $section,?array $page,string $label):string=>\Kulturhus\Verein\Documents\WorkflowPager::render($page,'voucher_mail_'.$section.'_page',$mnBase,$vmI,$label);
$mnReview=static function(array $draft)use($vmI):void{?>
 <p><a class="btn btn-outline theme-secondary dwf-button" href="<?= e(url(['view'=>'portal','tab'=>'vouchers','voucher_scope'=>'manage','voucher_task'=>'receipts','voucher_receipt'=>$draft['key']])) ?>"><?= e($vmI('Ausgang dieses Vorgangs prüfen')) ?></a></p>
 <details><summary><?= e($vmI('Nach geändertem Stand neu vorbereiten')) ?></summary><p><?= e($vmI('Prüfe zuerst den Ausgang des ursprünglichen Vorgangs und danach die jetzt angezeigten aktuellen Daten. Diese Vorbereitung speichert noch keine Änderung.')) ?></p><label class="voucher-manager-person"><input type="checkbox" name="voucher_mail_review_current" value="1"><span><?= e($vmI('Ich habe den aktuellen Stand geprüft.')) ?></span></label><button formnovalidate class="btn btn-outline theme-secondary dwf-button" name="voucher_mail_compose" value="review-current"><?= e($vmI('Aktuellen Versandstand für einen neuen Vorgang übernehmen')) ?></button></details>
<?php };
?>
<section class="dwf-card" data-voucher-mail data-voucher-mail-native>
<h2><?= e($vmI('Gutschein-Mails')) ?></h2><p><?= e($vmI('Eine Vormerkung ist keine bestätigte Zustellung. Unklare Vorgänge werden nicht automatisch erneut versendet.')) ?></p>
<nav class="dwf-actions" aria-label="<?= e($vmI('Gutschein-Mailbereiche')) ?>"><?php foreach(['people'=>'Persönliche Vorschau','template'=>'Mailvorlage bearbeiten','batch'=>'Persönlichen Sammelversand prüfen','history'=>'Versandverlauf']as$mnSection=>$mnLabel):?><a class="btn btn-outline theme-secondary dwf-button" href="<?= e($mnUrl(['voucher_mail_section'=>$mnSection])) ?>"><?= e($vmI($mnLabel)) ?></a><?php endforeach ?></nav>
<?php if($mnState['error']!==''):?><p class="dwf-alert dwf-error" role="alert"><?= e($vmI($mnState['error'])) ?></p><?php endif ?>
<?php if($mnState['template']!==null):require __DIR__.'/native-voucher-mail-template.php';endif ?>
<?php if(!$mnState['queue_available']):?><p class="dwf-alert" role="status"><?= e($vmI('Mailversand ist in dieser Umgebung ausgeschaltet.')) ?></p><?php endif ?>
<?php if($mnState['error']===''):if($mnState['section']==='people'):require __DIR__.'/native-voucher-mail-people.php';elseif($mnState['section']==='personal'):require __DIR__.'/native-voucher-mail-personal.php';elseif($mnState['section']==='batch'):require __DIR__.'/native-voucher-mail-batch.php';elseif($mnState['section']==='history'):require __DIR__.'/native-voucher-mail-history.php';endif;endif ?>
</section>
