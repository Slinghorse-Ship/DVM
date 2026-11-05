<?php declare(strict_types=1);
use Kulturhus\Verein\Finance;
$financeMatchBody??=[];$financeMatchContext??=$transactionContext;$financeMatchText??=static fn(string $source):string=>$source;
$movements=$finance->internalMovements($t['id']);
$internalKey=bin2hex(random_bytes(16));
?>
<?php if($assignable): ?>
<section class="card internal-movements">
 <h2><?= e($financeMatchText('Abhebung, Einzahlung oder eigener Kontoübertrag')) ?></h2>
 <p><?= e($financeMatchText('Geld zwischen eigenen Konten ist kein Ertrag und kein Aufwand. Ordnen Sie den vorhandenen Gegenumsatz zu. Gebühren werden getrennt einem Ausgabebeleg zugeordnet.')) ?></p>
 <details class="matching-create" <?= input($_POST,'action')==='internal-link'||isset($_GET['own_q'])||isset($_GET['own_page'])||isset($_GET['own_account'])?'open':'' ?>><summary><?= e($financeMatchText('Vorhandenen Gegenumsatz auf eigenem Konto zuordnen')) ?></summary>
 <?php if($financeMatchBody&&$finance->canWrite()):?><div class="filter-form"><label><?=e($financeMatchText('Gegenumsatz suchen'))?><input class="form-control" name="own_q" form="finance-match-original-form" value="<?=e(input($_GET,'own_q'))?>" placeholder="<?=e($financeMatchText('Name, Konto oder Verwendungszweck'))?>"></label><label><?=e($financeMatchText('Eigenes Gegenkonto'))?><select class="form-control" name="own_account" form="finance-match-original-form"><option value=""><?=e($financeMatchText('Alle anderen eigenen Konten'))?></option><?php foreach($finance->accounts() as $ownAccount):if($ownAccount['id']===$t['account_id'])continue;?><option value="<?=e($ownAccount['id'])?>" <?=input($_GET,'own_account')===$ownAccount['id']?'selected':''?>><?=e($ownAccount['name'])?></option><?php endforeach?></select></label><button class="btn btn-outline theme-secondary" form="finance-match-original-form" name="match_move" value="own:search" formnovalidate><?=e($financeMatchText('Gegenumsätze suchen'))?></button></div><?php else:?><form method="get" class="filter-form" data-live-search-page="own_page"><?php foreach($financeMatchContext as $key=>$value)if(!in_array($key,['own_q','own_page','own_account'],true))echo hidden($key,$value); ?>
 <label><?= e($financeMatchText('Gegenumsatz suchen')) ?><input class="form-control" name="own_q" maxlength="150" value="<?= e(input($_GET,'own_q')) ?>" placeholder="<?= e($financeMatchText('Name, Konto oder Verwendungszweck')) ?>"></label>
 <label><?= e($financeMatchText('Eigenes Gegenkonto')) ?><select class="form-control" name="own_account"><option value=""><?= e($financeMatchText('Alle anderen eigenen Konten')) ?></option><?php foreach($finance->accounts() as $ownAccount): if($ownAccount['id']===$t['account_id'])continue; ?><option value="<?= e($ownAccount['id']) ?>" <?= input($_GET,'own_account')===$ownAccount['id']?'selected':'' ?>><?= e($ownAccount['name']) ?></option><?php endforeach ?></select></label><button class="btn btn-outline theme-secondary"><?= e($financeMatchText('Gegenumsätze suchen')) ?></button></form><?php endif?>
 <?php $ownCatalogue=null;$ownError='';try{$ownCatalogue=$finance->ownMovementCandidatesPage($t['id'],$_GET['own_page']??1,$_GET['own_q']??'',$_GET['own_account']??'');}catch(DomainException $failure){$ownError=$failure->getMessage();} $ownCandidates=$ownCatalogue['rows']??[];if($ownError!==''): ?><p class="error" role="alert"><?= e($financeMatchText($ownError)) ?></p><?php endif ?>
 <?php foreach($ownCandidates as $other):$ownRetained=input($financeMatchBody,'action')==='internal-link'&&input($financeMatchBody,'other_id')===$other['id']; ?>
 <article class="matching-candidate"><div><strong><?= e($other['account_name']) ?></strong><p><?= e(dateDe($other['booked_on']).' · '.$other['description']) ?><br><?= e($financeMatchText('Noch zuzuordnen')) ?> <?= e(euro((int)$other['remaining_cents'])) ?></p></div>
 <?php if($ownRetained): ?><a class="btn btn-outline theme-secondary" href="#finance-match-original"><?= e($financeMatchText('Ursprüngliche Auswahl oben fortsetzen')) ?></a><?php else: ?><form method="post" class="match-form">
 <?= csrf().hidden('action','internal-link').hidden('transaction_id',$t['id']).hidden('transaction_version',$t['version']).hidden('other_id',$other['id']).hidden('other_version',$other['version']).hidden('request_key',bin2hex(random_bytes(16))) ?>
 <label><?= e($financeMatchText('Transferbetrag (EUR)')) ?><input class="form-control" name="amount" inputmode="decimal" required value="<?= e(Finance::decimal(min($t['remaining_cents'],$other['remaining_cents']))) ?>"></label>
 <label><?= e($financeMatchText('Grund / Nachweis')) ?><input class="form-control" name="reason" required maxlength="500" value="" placeholder="<?= e($financeMatchText('z. B. Bargeld für die Vereinskasse')) ?>"></label>
 <button class="btn btn-solid theme-primary"><?= e($financeMatchText('Als eigenen Geldtransfer zuordnen')) ?></button>
 </form><?php endif ?></article>
 <?php endforeach ?>
 <?php if(!$ownCandidates&&$ownError===''): ?><p><?= e($financeMatchText('Kein offener Gegenumsatz vorhanden. Bankumsätze zuerst importieren; eine tatsächliche Bargeldbewegung können Sie unten erfassen.')) ?></p><?php endif ?>
 <?php $catalogue=$ownCatalogue;$catalogueContext=array_replace($financeMatchContext,['own_q'=>input($_GET,'own_q'),'own_account'=>input($_GET,'own_account')]);$cataloguePageKey='own_page';$catalogueLabel=$financeMatchText('Gegenumsatzseiten');$catalogueText=$financeMatchText;$financeMatchPagerKind='own';require __DIR__.'/../Accounting/finance-match-pager.php'; ?>
 </details>
 <?php if($t['account_kind']!=='cash'): $cashAccounts=array_values(array_filter($finance->accounts(),static fn($a)=>$a['kind']==='cash'&&($a['active']??1))); ?>
 <details class="matching-create" <?= input($_POST,'action')==='internal-cash'?'open':'' ?>><summary><?= e($financeMatchText($t['cents']<0?'Abhebung: tatsächlichen Zugang in der Handkasse erfassen':'Einzahlung: tatsächliche Abgabe aus der Handkasse erfassen')) ?></summary>
 <p><?= e($financeMatchText('Nur verwenden, wenn die Bargeldbewegung noch nicht im Kassenbuch steht. Ein vorhandener Kassenumsatz wird oben zugeordnet. Der Bankumsatz wird nicht erneut angelegt.')) ?></p>
 <?php if(input($financeMatchBody,'action')==='internal-cash'): ?><p><a class="btn btn-outline theme-secondary" href="#finance-match-original"><?= e($financeMatchText('Ursprüngliche Auswahl oben fortsetzen')) ?></a></p><?php elseif($cashAccounts): $ownCashBody=[]; ?>
 <form method="post" class="filter-form">
 <?= csrf().hidden('action','internal-cash').hidden('transaction_id',$t['id']).hidden('transaction_version',$t['version']).hidden('request_key',$internalKey) ?>
 <label><?= e($financeMatchText('Handkasse')) ?><select class="form-control" name="cash_account" required><option value=""><?= e($financeMatchText('Bitte wählen')) ?></option><?php foreach($cashAccounts as $cash): ?><option value="<?= e($cash['id']) ?>" <?= input($ownCashBody,'cash_account')===$cash['id']?'selected':'' ?>><?= e($cash['name']) ?></option><?php endforeach ?></select></label>
 <label><?= e($financeMatchText('Tag der tatsächlichen Bargeldbewegung')) ?><input class="form-control" type="date" name="cash_date" required max="<?= e(\Kulturhus\Verein\Finance::today()) ?>" value="<?= e(input($ownCashBody,'cash_date',$t['booked_on'])) ?>"></label>
 <label><?= e($financeMatchText('Bargeldbetrag (EUR)')) ?><input class="form-control" name="amount" inputmode="decimal" required value="<?= e(input($ownCashBody,'amount',Finance::decimal($t['remaining_cents']))) ?>"></label>
 <label><?= e($financeMatchText('Grund / Nachweis')) ?><input class="form-control" name="reason" required maxlength="500" value="<?= e(input($ownCashBody,'reason')) ?>"></label>
 <label><input type="checkbox" name="cash_confirm" value="1" required> <?= e($financeMatchText('Die Bargeldbewegung ist tatsächlich erfolgt und noch nicht im Kassenbuch erfasst.')) ?></label>
 <button class="btn btn-solid theme-primary"><?= e($financeMatchText('Bargeldbewegung erfassen und zuordnen')) ?></button>
 </form>
 <?php else: ?><p><?= e($financeMatchText('Zuerst unter Konten eine Handkasse anlegen.')) ?></p><?php endif ?>
 </details>
 <?php endif ?>
</section>
<?php endif ?>
<?php if($movements): ?>
<section class="card internal-movements"><h2><?= e($financeMatchText('Geldtransfers zwischen eigenen Konten')) ?></h2>
<?php foreach($movements as $movement): $otherId=$movement['outgoing_id']===$t['id']?$movement['incoming_id']:$movement['outgoing_id']; ?>
<article class="matching-candidate"><div><strong><?= e(euro((int)$movement['cents'])) ?> · <?= e($movement['outgoing_name'].' → '.$movement['incoming_name']) ?></strong><p><?= e($movement['reason']) ?></p><a href="<?= e(url(bankTransactionContext($otherId,$_GET))) ?>"><?= e($financeMatchText('Gegenumsatz öffnen')) ?></a>
<?php if($movement['voided_at']): ?><p><?= e($financeMatchText('Aufgehoben:')) ?> <?= e($movement['void_reason']) ?></p><?php else: ?><p><?= e($financeMatchText('Als eigener Geldtransfer zugeordnet · kein Ertrag oder Aufwand.')) ?></p><?php endif ?></div>
<?php if(!$movement['voided_at']&&$finance->canWrite()): ?><form method="post" class="match-form"><?= csrf().hidden('action','internal-release').hidden('transaction_id',$t['id']).hidden('movement_id',$movement['id']) ?><label><?= e($financeMatchText('Korrekturgrund')) ?><input class="form-control" name="reason" required maxlength="1000" value="<?= e(input($_POST,'action')==='internal-release'&&input($_POST,'movement_id')===$movement['id']?input($_POST,'reason'):'') ?>"></label><button class="btn btn-outline theme-secondary"><?= e($financeMatchText('Transferzuordnung aufheben')) ?></button><small><?= e($financeMatchText('Beide Kontoumsätze bleiben bestehen.')) ?></small></form><?php endif ?>
</article><?php endforeach ?></section>
<?php endif ?>
