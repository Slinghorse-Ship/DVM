<?php declare(strict_types=1);
require_once __DIR__.'/../../../src/Finance/Invoices/HybridEInvoice.php';
$hybridRows=\Kulturhus\Verein\DocumentCatalog::query($db,"SELECT DISTINCT d.id,d.title,d.created_at,f.sha256,f.snapshot FROM documents d JOIN document_links l ON l.club_id=d.club_id AND l.document_id=d.id JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=(SELECT MAX(revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id) WHERE l.club_id=? AND l.source_type='entry' AND l.source_id=? AND d.kind='invoice' AND d.state='active' ORDER BY d.created_at DESC",[$r['club_id'],$r['id']])->fetchAll();
$hybridFiles=[];foreach($hybridRows as $row){$snapshot=json_decode($row['snapshot']??'',true);$canonical=$snapshot['einvoice']??null;if(is_array($canonical)&&($canonical['kosit']['state']??'')==='valid'&&($canonical['validation']['state']??'')==='valid'&&hash_equals((string)($canonical['validation']['sha256']??''),$row['sha256'])&&hash_equals((string)($canonical['xml_sha256']??''),hash('sha256',(string)($canonical['xml']??'')))){try{$hybridDoc=$documents->document($row['id']);}catch(DomainException){continue;}$hybridFiles[]=$row+['document'=>$hybridDoc,'validation'=>$canonical['validation']];continue;}if(($snapshot['kind']??'')!=='outgoing_hybrid_einvoice'||($snapshot['validation']['state']??'')!=='valid'||($snapshot['domestic_confirmation']['country']??'')!=='DE'||!preg_match('/^[a-f0-9]{64}$/D',(string)($snapshot['domestic_confirmation']['source_hash']??''))||!hash_equals((string)($snapshot['validation']['sha256']??''),$row['sha256']))continue;try{$hybridDoc=$documents->document($row['id']);}catch(DomainException){continue;}$hybridFiles[]=$row+['document'=>$hybridDoc,'validation'=>$snapshot['validation']];}
$outgoingElectronicMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$outgoingElectronicText=static fn(string$s,array$v=[]):string=>\Kulturhus\Native\Language\OutgoingElectronicMessages::text($s,$outgoingElectronicMessages,$v);
$hybridAvailable=false;$hybridSource=null;
if($outgoingReady)try{$hybridSource=\Kulturhus\Verein\Web\OutgoingHybridForm::current($db,(string)$_SESSION['actor'],$r['id']);$hybridAvailable=true;}catch(DomainException){}
$hybridForm=\Kulturhus\Verein\Web\OutgoingHybridForm::state($r['id'],$hybridSource,$_POST);
if(!$hybridAvailable&&!$hybridFiles&&!$hybridForm['retained'])return;
?>
<section class="card" data-outgoing-electronic-status="hybrid"><link rel="stylesheet" href="outgoing-electronic.css?v=1">
<h2><?=e($outgoingElectronicText('Rechnung als ZUGFeRD-PDF'))?></h2>
<p><?=e($outgoingElectronicText('Ein PDF zum Lesen und dieselben Rechnungsdaten zum automatischen Einlesen in einer Datei. Rechnungsnummer, Positionen und Beträge stammen aus der ausgegebenen Originalrechnung.'))?></p>
<?php if($hybridFiles):foreach($hybridFiles as$hybridFile):?>
<p><strong><?=e($hybridFile['title'])?></strong> · <?=e($outgoingElectronicText('XML und PDF/A-3b geprüft'))?><br>
<a class="btn btn-outline theme-secondary button secondary" href="<?=e(url(['document_file'=>$hybridFile['id'],'revision'=>$hybridFile['document']['revision']]))?>"><?=e($outgoingElectronicText('ZUGFeRD-PDF herunterladen'))?></a>
<a href="<?=e(url(['view'=>'documents','doc'=>$hybridFile['id']]))?>"><?=e($outgoingElectronicText('Dokument ansehen'))?></a></p>
<?php endforeach;elseif($hybridAvailable&&\Kulturhus\Verein\HybridEInvoice::configured()):$hybridBuyer=$hybridSource['invoice']['data'];$hybridSeller=$hybridBuyer['issuer']['club'];?>
<form method="post" data-outgoing-hybrid-form>
<?=csrf().hidden('action','outgoing-hybrid-save').hidden('entry_id',$r['id']).hidden('entry_version',$hybridForm['version']).hidden('source_hash',$hybridForm['source_hash'])?>
<?php if($hybridForm['changed']):?><p role="status"><?=e($outgoingElectronicText('Der gespeicherte Rechnungsstand hat sich geändert. Deine Auswahl und die ursprünglich geprüfte Fassung bleiben erhalten. Zuerst den aktuellen Stand vergleichen und bewusst übernehmen.'))?></p><?php endif?>
<p><?=e($outgoingElectronicText('Ursprünglich geprüfte Belegversion:'))?> <span data-hybrid-original-version><?=e($hybridForm['version'])?></span></p>
<fieldset><legend><?=e($outgoingElectronicText('Anschriften der Originalrechnung prüfen'))?></legend>
<p><strong><?=e($outgoingElectronicText('Rechnungssteller:'))?></strong> <?=e($hybridSeller['name'].', '.$hybridSeller['street'].', '.$hybridSeller['postcode'].' '.$hybridSeller['city'])?><br>
<strong><?=e($outgoingElectronicText('Rechnungsempfänger:'))?></strong> <?=e($hybridBuyer['buyer_name'].', '.$hybridBuyer['buyer_street'].', '.$hybridBuyer['buyer_postcode'].' '.$hybridBuyer['buyer_city'])?></p>
<label><?=e($outgoingElectronicText('Haben beide Rechnungsparteien ihre Anschrift in Deutschland?'))?>
<select class="form-control" name="domestic_country" required>
<option value=""><?=e($outgoingElectronicText('Bitte auswählen'))?></option>
<?php if(!in_array($hybridForm['country'],['','DE','other'],true)):?><option value="<?=e($hybridForm['country'])?>" selected><?=e($outgoingElectronicText('Unbekannte Auswahl:'))?> <?=e($hybridForm['country'])?></option><?php endif?>
<option value="DE"<?=$hybridForm['country']==='DE'?' selected':''?>><?=e($outgoingElectronicText('Ja, beide Anschriften liegen in Deutschland'))?></option>
<option value="other"<?=$hybridForm['country']==='other'?' selected':''?>><?=e($outgoingElectronicText('Nein, mindestens eine Anschrift liegt im Ausland'))?></option>
</select></label>
<p><?=e($outgoingElectronicText('Dieser Export ist für inländische Rechnungen eingerichtet. Für eine ausländische Anschrift bitte das vorhandene Rechnungs-PDF verwenden und die passende E-Rechnungsanforderung klären.'))?></p>
</fieldset>
<div class="outgoing-electronic-actions"><button class="btn btn-outline theme-secondary" type="submit" name="hybrid_current" value="1" formnovalidate><?=e($outgoingElectronicText('Aktuelle Originalrechnung vergleichen'))?></button></div>
<?php if(isset($hybridFormResult['comparison'])):$hybridComparison=$hybridFormResult['comparison'];?>
<section class="card" data-hybrid-comparison><h3><?=e($outgoingElectronicText('Aktuell verglichene Originalrechnung'))?></h3>
<p><?=e($outgoingElectronicText('Aktuelle Belegversion:'))?> <?=e((string)$hybridComparison['source']['entry']['version'])?></p>
<p><?=e($outgoingElectronicText('SHA-256 des ausgegebenen PDF-Originals:'))?> <code><?=e($hybridComparison['original']['sha256'])?></code></p>
<?=hidden('hybrid_compare_hash',$hybridComparison['comparison_hash'])?>
<button class="btn btn-outline theme-secondary" type="submit" name="hybrid_adopt" value="1" formnovalidate><?=e($outgoingElectronicText('Verglichene Originalrechnung übernehmen'))?></button>
<p><?=e($outgoingElectronicText('Vergleichen und Übernehmen erstellen keine Datei. Danach die Originalanschriften prüfen und die Datei gesondert erstellen.'))?></p>
</section>
<?php endif?>
<p><?=e($outgoingElectronicText('Die Datei wird vollständig geprüft und beim Beleg gespeichert. Die Prüfung kann bis zu zwei Minuten dauern. Der Download versendet keine E-Mail.'))?></p>
<button class="btn btn-solid theme-primary" type="submit" data-outgoing-hybrid-save<?=$hybridForm['changed']?' disabled':''?>><?=e($outgoingElectronicText('ZUGFeRD-PDF prüfen und erstellen'))?></button>
</form>
<?php else:?>
<?php if($hybridForm['retained']):?><p><?=e($outgoingElectronicText('Deine noch nicht gespeicherte Auswahl:'))?> <span data-hybrid-retained-country><?=e($hybridForm['country'])?></span></p><p><?=e($outgoingElectronicText('Ursprünglich geprüfte Belegversion:'))?> <?=e($hybridForm['version'])?></p><?php endif?>
<p><?=e($outgoingElectronicText($hybridAvailable?'ZUGFeRD ist hier noch nicht eingerichtet. Das Rechnungs-PDF und die XRechnung bleiben verfügbar.':'Die Originalrechnung kann mit diesem Zugang oder in ihrem aktuellen Zustand nicht neu erstellt werden. Vorhandene Originaldateien bleiben gesondert lesbar.'))?></p>
<?php endif?>
</section>
