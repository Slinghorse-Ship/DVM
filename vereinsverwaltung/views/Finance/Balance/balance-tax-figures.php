<?php declare(strict_types=1);
$figuresText=$bt??static fn(string $source):string=>$source;
require_once __DIR__.'/../../../src/Finance/Balance/BalanceDirectionalClosing.php';
?>
<details class="card" data-balance-specialist-readonly <?= $filing['state']==='validated'&&!$approved?'open':'' ?>><summary><?= e($figuresText('Vollständiger Zahlenentwurf')) ?></summary>
<?php foreach($values['accounts'] as $key=>$account):
 if(!$account['book_cents']&&!$account['tax_cents']&&!$account['book_opening_cents']&&!$account['tax_opening_cents'])continue;
 $bookDisplay=\Kulturhus\Verein\BalanceDirectionalClosing::presentation($values['year'],$values['book']['accounts'][$key]);
 $taxDisplay=\Kulturhus\Verein\BalanceDirectionalClosing::presentation($values['year'],$values['tax']['accounts'][$key]);
 $kind=static fn(?array $row):string=>$row===null?'':' · '.$figuresText($row['amount_cents']===0?'Ausgeglichen':($row['group']==='liabilities'?'Verbindlichkeit':'Forderung'));
 ?>
<div class="tax-balance-figures"><strong><?= e($account['name']) ?></strong><dl>
<div><dt><?= e($figuresText('Buchwert')) ?><?= e($kind($bookDisplay)) ?></dt><dd><?= $money($bookDisplay['amount_cents']??$account['book_cents']) ?></dd></div>
<div><dt><?= e($figuresText($bookDisplay!==null?'Änderung des Originalsaldos':'Änderung')) ?></dt><dd><?= $money($bookDisplay!==null?$values['tax']['accounts'][$key]['closing_cents']-$values['book']['accounts'][$key]['closing_cents']:$account['difference_cents']) ?></dd></div>
<div><dt><?= e($figuresText('Steuerwert')) ?><?= e($kind($taxDisplay)) ?></dt><dd><?= $money($taxDisplay['amount_cents']??$account['tax_cents']) ?></dd></div>
</dl></div>
<?php endforeach ?>
<p><?= e($figuresText('Jahresergebnis nach steuerlicher Bewertung:')) ?> <?= $money($values['tax']['annual_result_cents']) ?></p></details>
