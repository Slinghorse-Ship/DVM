<?php declare(strict_types=1);
$ownMovementEvidence=$preview['snapshot']['internal_movements']??[];
if(!$ownMovementEvidence)return;
$ownMovementMessages=$managerLanguageMessages??[];$ownMovementText=static fn(string$text):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($text,$ownMovementMessages);$ownMovementDate=static fn(string$date):string=>implode('.',array_reverse(explode('-',$date)));$ownMovementMoney=static fn(int$cents):string=>number_format($cents/100,2,',','.').' €';
?>
<section class="card" data-own-movement-evidence>
<h3><?= e($ownMovementText('Eigene Kontoumbuchung')) ?></h3>
<p><?= e($ownMovementText('Der zugeordnete eigene Transferanteil verändert weder Einnahmen noch Ausgaben.')) ?></p>
<?php foreach($ownMovementEvidence as$ownMovement):$ownOut=$ownMovement['outgoing'];$ownIn=$ownMovement['incoming']; ?>
<article data-own-movement-original="<?= e($ownMovement['movement']['id']) ?>">
<p><strong><?= e($ownOut['account']['name'].' → '.$ownIn['account']['name']) ?></strong> · <?= e($ownMovementMoney((int)$ownMovement['movement']['cents'])) ?></p>
<dl><dt><?= e($ownMovementText('Abgang')) ?></dt><dd><?= e($ownMovementDate($ownOut['transaction']['booked_on'])) ?></dd><dt><?= e($ownMovementText('Zugang')) ?></dt><dd><?= e($ownMovementDate($ownIn['transaction']['booked_on'])) ?></dd><dt><?= e($ownMovementText('Grund der Zuordnung')) ?></dt><dd><?= nl2br(e($ownMovement['movement']['reason'])) ?></dd></dl>
<?php if($ownOut['transaction']['booked_on']!==$ownIn['transaction']['booked_on']): ?><p><?= e($ownMovementText('Die Gegenbewegung behält ihren tatsächlichen Buchungstag. Ein bis dahin offener Geldtransit wird in den nächsten Zeitraum vorgetragen.')) ?></p><?php endif ?>
<p><a class="btn btn-outline text-wrap" data-own-movement-outgoing href="<?= e(url(['view'=>'finance','tab'=>'bank','account'=>$ownOut['transaction']['account_id'],'transaction'=>$ownOut['transaction']['id']])) ?>"><?= e($ownMovementText('Abgang im Bankbereich öffnen')) ?></a> <a class="btn btn-outline text-wrap" data-own-movement-incoming href="<?= e(url(['view'=>'finance','tab'=>'bank','account'=>$ownIn['transaction']['account_id'],'transaction'=>$ownIn['transaction']['id']])) ?>"><?= e($ownMovementText('Zugang im Bankbereich öffnen')) ?></a></p>
</article><?php endforeach ?>
</section>
