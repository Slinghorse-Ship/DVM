<?php declare(strict_types=1);
$dcMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\DonationContactMessages::messages('de');
$dc=static fn(string $source):string=>\Kulturhus\Native\Language\DonationContactMessages::text($source,$dcMessages);
$dce=static fn(string $source):string=>e($dc($source));
$donorLink=$finance->donationContact($donorType,$r['id']);
$matchesDonor=static fn(array $data):bool=>($data['source_type']??null)===$donorType&&($data['source_id']??null)===$r['id'];
$review=isset($donorContactState)&&$matchesDonor($donorContactState['data'])?$donorContactState:null;
$donorChoice=(($contactChoiceState['action']??null)==='donation-contact'&&$matchesDonor($contactChoiceState['data']))?$contactChoiceState:null;
$ownDonorPost=in_array($_POST['action']??null,['donation-contact','donation-contact-review','donation-contact-adopt'],true)&&$matchesDonor($_POST);
$donorData=$review['data']??$donorChoice['data']??($ownDonorPost?$_POST:[]);
$chosenContact=array_key_exists('contact_id',$donorData)?input($donorData,'contact_id'):($donorLink['id']??'');
$picker=$donorChoice??['contact_id'=>$chosenContact,'contact_query'=>input($donorData,'contact_query'),'contact_page'=>input($donorData,'contact_page','1')];
$malformed=[];foreach(['source_version','contact_id','contact_query','contact_page']as$key)if(array_key_exists($key,$donorData)&&!is_string($donorData[$key])&&!($key==='contact_page'&&is_int($donorData[$key])))$malformed[$key]=$donorData[$key];
$heldVersion=array_key_exists('source_version',$donorData)?input($donorData,'source_version'):(string)$r['version'];$stale=$heldVersion!==(string)$r['version'];
?>
<section class="card" data-donor-contact><h3><?= $dce('Zugeordneter Spenderkontakt') ?></h3><p data-donor-current><?= $donorLink?e($donorLink['name']):$dce('Noch kein Kontakt zugeordnet. Der Name im Beleg allein ist keine eindeutige Kontaktzuordnung.') ?></p>
<?php if($finance->canWrite()&&($canRead??false)): ?>
<form method="post" data-donor-assignment><?= csrf().hidden('action','donation-contact').hidden('source_type',$donorType).hidden('source_id',$r['id']).hidden('source_version',$heldVersion) ?>
<?php
$pickerProblem=false;
try{if($malformed)throw new DomainException('Ursprüngliche Auswahl prüfen.');echo \Kulturhus\Verein\ContactChoicePicker::render($registry,$picker,$dcMessages);}
catch(DomainException $pickerError){$pickerProblem=true; ?>
<fieldset class="contact-choice-picker" data-donor-search-error><legend><?= $dce('Kontakt auswählen') ?></legend><p role="alert"><?= e($dc($pickerError->getMessage())) ?></p><p><?= $dce('Die vollständige Eingabe bleibt erhalten. Suche oder Seite korrigieren und erneut suchen.') ?></p>
<?= hidden('contact_id',input($picker,'contact_id')) ?><label><?= $dce('Name oder E-Mail') ?><input class="form-control" type="search" name="contact_query" maxlength="150" value="<?= e(input($picker,'contact_query')) ?>"></label><label><?= $dce('Ursprüngliche Suchseite') ?><input class="form-control" name="contact_page" inputmode="numeric" value="<?= e(input($picker,'contact_page','1')) ?>"></label>
<?php if($malformed): ?><p><?= $dce('Nicht unterstützte Eingabe') ?></p><textarea class="form-control" rows="3" readonly data-donor-malformed aria-label="<?= $dce('Nicht unterstützte Eingabe') ?>"><?= e(json_encode($malformed,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)) ?></textarea><?php endif ?>
<button class="btn btn-outline theme-secondary" name="contact_browse" value="search" formnovalidate><?= $dce('Suchen') ?></button></fieldset>
<?php } ?>
<?php if($stale): ?><p role="status" data-donor-stale><?= $dce('Die Spende hat inzwischen einen anderen Stand. Auswahl und ursprüngliche Fassung bleiben erhalten. Zuerst bewusst vergleichen.') ?></p><?php endif ?>
<?php if($review): ?><section data-donor-comparison><h4><?= $dce('Vergleich des aktuellen Spendenbelegs') ?></h4><dl><dt><?= $dce('Ursprüngliche Fassung') ?></dt><dd><?= e($review['original_version']) ?></dd><dt><?= $dce('Aktuelle Fassung') ?></dt><dd><?= e($review['source']['version']) ?></dd><dt><?= $dce('Aktuell zugeordnet') ?></dt><dd><?= $review['linked']?e($review['linked']['name']):$dce('Keine Zuordnung') ?></dd><dt><?= $dce('Beabsichtigte Auswahl') ?></dt><dd><?= $review['selected']?e($review['selected']['name']):$dce('Noch kein Kontakt gewählt') ?></dd></dl>
<p><?= $dce('Dieser Vergleich ist lesend. Die Auswahl wird erst mit „Kontakt zuordnen“ gespeichert.') ?></p>
<?php if($review['adopted']): ?><p role="status" data-donor-adopted><?= $dce('Der geprüfte Stand ist im Formular übernommen. Es wurde noch kein Kontakt geändert.') ?></p><?php else: ?><?= hidden('contact_review_hash',$review['hash']) ?><button class="btn btn-outline theme-secondary" name="action" value="donation-contact-adopt" formnovalidate><?= $dce('Geprüften Stand für diese Auswahl übernehmen') ?></button><?php endif ?></section><?php endif ?>
<div class="actions"><button class="btn btn-solid theme-primary" <?= $stale||$pickerProblem?'disabled':'' ?>><?= $dce('Kontakt zuordnen') ?></button><button class="btn btn-outline theme-secondary" name="action" value="donation-contact-review" formnovalidate><?= $dce('Aktuellen Stand vergleichen') ?></button></div></form>
<p><a class="btn btn-outline theme-secondary" data-donor-reopen href="<?= e(url($_GET)) ?>"><?= $dce('Gespeicherten Spendenbeleg neu öffnen') ?></a> · <?= $dce('Beim Neuöffnen werden ungespeicherte Formulareingaben verworfen.') ?></p>
<?php endif ?>
<p><?= $dce('Mit zugeordnetem Spender und tatsächlichem Zahlungseingang wird automatisch ein nicht ausgestellter Bescheinigungsentwurf vorbereitet. Für Sachspenden werden die gespeicherten Sachangaben verwendet.') ?></p>
<?php $preparation=\Kulturhus\Verein\DonationPreparation::status($db,(string)$_SESSION['actor'],$donorType,$r['id']);if($preparation): ?><p role="status" data-donor-preparation><?= e($dc($preparation['reason'])) ?></p>
<?php if($preparation['documents']): ?><h4><?= $dce('Vorbereitete Dokumente') ?></h4><?php foreach($preparation['documents']as$preparedDocument): ?><p><a class="btn btn-outline theme-secondary" data-donor-draft href="<?= e(url(['view'=>'documents','doc'=>$preparedDocument])) ?>"><?= $dce('Entwurf öffnen') ?></a></p><?php endforeach;endif;endif ?>
<?php if($finance->canWrite()): ?><form method="post" data-donor-prepare><?= csrf().hidden('action','donation-prepare').hidden('source_type',$donorType).hidden('source_id',$r['id']) ?><button class="btn btn-outline theme-secondary secondary"><?= $dce('Entwurf erneut vorbereiten') ?></button></form><?php endif ?></section>
