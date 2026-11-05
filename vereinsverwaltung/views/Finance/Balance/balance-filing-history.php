<?php declare(strict_types=1);

$balanceFilingMessages=$annualTaxMessages??[];
$bt=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$balanceFilingMessages);
$balanceHistoryYear=(int)($_GET['tax_year']??$_GET['balance_year']??date('Y')-1);
$balanceHistoryPage=$_GET['balance_local_history']??'1';
try{
 if(!is_string($balanceHistoryPage)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$balanceHistoryPage))throw new DomainException($bt('E-Bilanz-Bestätigungsseite vollständig prüfen.'),422);
 $balanceHistory=(new \Kulturhus\Verein\BalanceFilingCommands($db,(string)$_SESSION['actor']))->history($balanceHistoryYear,(int)$balanceHistoryPage);
}catch(DomainException $e){?><p role="alert"><?= e($e->getMessage()) ?></p><?php return;}?>
<details class="card" data-balance-local data-balance-local-history><summary><?=e($bt('Frühere lokale E-Bilanz-Bestätigungen ('))?><?= $balanceHistory['count'] ?>)</summary>
<p><?=e($bt('Diese lokalen Schritte bestätigen keine Übermittlung. Je Seite werden sechs ursprüngliche Ergebnisse angezeigt.'))?></p>
<?php if(!$balanceHistory['rows']):?><p><?=e($bt('Noch keine lokale E-Bilanz-Bestätigung gespeichert.'))?></p><?php endif?>
<ul><?php foreach($balanceHistory['rows']as $row):?><li><a href="<?= e(url(['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>(string)$balanceHistoryYear,'balance_local_outcome'=>$row['request_key']])) ?>"><?= e((new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s').' · '.\Kulturhus\Native\Language\BalanceFilingMessages::operation($row['operation'],$balanceFilingMessages).' · '.$row['filing_version']) ?></a></li><?php endforeach?></ul>
<p><?=e($bt('Seite'))?> <?= $balanceHistory['page'] ?> <?=e($bt('von'))?> <?= $balanceHistory['pages'] ?></p>
<?php $historyRoute=$_GET;unset($historyRoute['balance_local_outcome'],$historyRoute['balance_local_xml']);if($balanceHistory['page']>1):?><a class="btn btn-outline" href="<?= e(url(array_replace($historyRoute,['balance_local_history'=>(string)($balanceHistory['page']-1)]))) ?>"><?=e($bt('Vorherige Bestätigungen'))?></a><?php endif?>
<?php if($balanceHistory['page']<$balanceHistory['pages']):?><a class="btn btn-outline" href="<?= e(url(array_replace($historyRoute,['balance_local_history'=>(string)($balanceHistory['page']+1)]))) ?>"><?=e($bt('Weitere Bestätigungen'))?></a><?php endif?></details>
