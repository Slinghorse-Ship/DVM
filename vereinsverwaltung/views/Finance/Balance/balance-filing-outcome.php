<?php declare(strict_types=1);

$balanceFilingMessages=$annualTaxMessages??[];
$bt=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$balanceFilingMessages);
use Kulturhus\Verein\{BalanceFilingCommands};
use Kulturhus\Verein\Web\BalanceFilingForm;
echo '<link rel="stylesheet" href="balance-filing-local.css?v=1">';
$balanceLocalError??='';$balanceLocalState??=null;$balanceLocalRead??=false;$balanceLocalOutcome??=null;$balanceLocalHandled??=false;
$balanceRaw=BalanceFilingForm::original($_POST);$balanceForm=$balanceLocalState['fields']??$balanceRaw;
$balanceKey=$balanceLocalOutcome['key']??$balanceForm['balance_local_key']??$_GET['balance_local_outcome']??null;
$balanceKey=is_string($balanceKey)&&preg_match('/\A[a-f0-9]{32}\z/D',$balanceKey)?$balanceKey:null;
$balanceYear=$balanceLocalOutcome['original']['year']??$_GET['tax_year']??$_GET['balance_year']??date('Y')-1;
$balanceRead=static fn(string $key):string=>url(['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>(string)$balanceYear,'balance_local_outcome'=>$key]);
if($balanceLocalError!==''):?>
<section class="notice" role="alert" data-balance-local data-balance-local-error><strong><?= e($bt($balanceLocalError)) ?></strong>
<details><summary><?=e($bt('Bekannte ursprüngliche Formularangaben'))?></summary><pre data-balance-local-raw><?= e(json_encode($balanceRaw,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)) ?></pre></details>
<?php if($balanceKey):?><a class="btn btn-outline" data-balance-local-read-first href="<?= e($balanceRead($balanceKey)) ?>" target="_blank" rel="noopener"><?=e($bt('Gespeicherten Ausgang in neuem Tab lesen'))?></a><?php endif?>
<?php if($balanceLocalHandled&&http_response_code()===409&&is_string($balanceForm['balance_local_token']??null)&&isset($_SESSION['balance_local_contexts'][$balanceForm['balance_local_token']])):$source=$_SESSION['balance_local_contexts'][$balanceForm['balance_local_token']];try{$comparison=(new BalanceFilingCommands($db,(string)$_SESSION['actor']))->workspace($source['context']['year'],$source['context']['filing']['id']??null,$source['context']['period_id']);?>
<details data-balance-local-comparison><summary><?=e($bt('Aktuellen Stand mit meinen Angaben vergleichen'))?></summary><p><?=e($bt('Die Übernahme aktualisiert nur die Ausgangsfassung. Erst ein weiteres Speichern ändert den Entwurf.'))?></p>
<details><summary><?=e($bt('Alle aktuellen Angaben'))?></summary><pre><?= e(json_encode($comparison['filing']??$comparison['context'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)) ?></pre></details>
<button class="btn btn-outline" form="<?= isset($source['actions'])&&in_array('tax-filing-balance-save',$source['actions'],true)?'balance-local-main':'balance-local-command' ?>" name="action" value="balance-local-rebase" formnovalidate><?=e($bt('Aktuellen Ausgang ausdrücklich übernehmen und meine Angaben behalten'))?></button></details>
<?php }catch(Throwable){}endif?></section>
<?php endif;if($balanceLocalOutcome===null)return;$saved=$balanceLocalOutcome['original'];
if($saved===null):?><section class="card" data-balance-local data-balance-local-empty><h2><?=e($bt('E-Bilanz-Ausgang noch nicht lesbar'))?></h2><p><?=e($bt('Dieselbe Kennung erneut lesen; keinen zweiten Auftrag beginnen.'))?></p><code><?= e($balanceKey) ?></code><a class="btn btn-outline" href="<?= e($balanceRead($balanceKey)) ?>"><?=e($bt('Gespeicherten Ausgang erneut lesen'))?></a></section>
<?php else:$record=$saved['result'];$stamp=(new DateTimeImmutable($saved['created_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s T');?>
<section class="card" data-balance-local data-balance-local-saved data-balance-local-key="<?= e($saved['key']) ?>"><h2><?= e($bt($saved['notice'])) ?></h2>
<p><?= e($stamp) ?> <?=e($bt('· E-Bilanz'))?> <?= $saved['year'] ?> <?=e($bt('· gespeicherte Fassung'))?> <?= $record['version'] ?></p><p><?=e($bt('Diese Bestätigung enthält die damaligen Angaben und den tatsächlichen Ausgang. Spätere Bearbeitungen verändern sie nicht. Dieser lokale Schritt hat keine E-Bilanz übermittelt.'))?></p>
<?php $originalProfile=$record['form_data']['balance_preparation']['profile']; ?>
<dl data-balance-local-facts>
<dt><?=e($bt('Name des Vereins'))?></dt><dd><?=e($originalProfile['company']['name']!==''?$originalProfile['company']['name']:$bt('Noch zu klären'))?></dd>
<dt><?=e($bt('Betrieb oder Vereinstätigkeit'))?></dt><dd><?=e($originalProfile['business_name']!==''?$originalProfile['business_name']:((($originalProfile['whole_business_name']??'')!=='')?$originalProfile['whole_business_name']:$bt('Noch zu klären')))?></dd>
<dt><?=e($bt('Datenlieferant · Name und Anschrift'))?></dt><dd><?=e($originalProfile['supplier']!==''?$originalProfile['supplier']:$bt('Noch zu klären'))?></dd>
<dt><?=e($bt('Abgabeumgebung'))?></dt><dd><?=e($bt($record['environment']==='production'?'PRODUKTION · echte Vereinsdaten':'TEST · ausschließlich erfundene Daten'))?></dd>
</dl>
<?php foreach(['before'=>$bt('Frühere ursprüngliche XML'),'original'=>$bt('Damals gespeicherte XML')]as $which=>$label):$file=$which==='before'?$saved['before']:$record;if(is_string($file['xml_data']??null)):?><a class="btn btn-outline" href="<?= e($balanceRead($saved['key']).'&balance_local_xml='.$which) ?>"><?= e($label) ?></a><?php endif;endforeach?>
<details><summary><?=e($bt('Gespeicherte Angaben vollständig lesen'))?></summary><pre data-balance-local-original><?= e(json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)) ?></pre></details>
<details><summary><?=e($bt('Eingabe und Ausgangsfassung'))?></summary><pre><?= e($saved['original_json']) ?></pre></details>
<details><summary><?=e($bt('Bestätigung und Prüfsumme'))?></summary><p><?= e($saved['key']) ?></p><code data-balance-local-sha><?= e($saved['sha256']) ?></code></details>
<a class="btn btn-solid theme-primary" href="<?= e(url(['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>(string)$saved['year'],'filing'=>$record['id']])) ?>"><?=e($bt('Aktuellen Stand öffnen'))?></a></section>
<?php endif?>
