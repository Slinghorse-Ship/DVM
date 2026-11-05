<?php declare(strict_types=1);
use Kulturhus\Verein\{AssetValuation,BalancePeriod};
$valuationPeriod=BalancePeriod::calendar($year);$value=null;$previous=null;$valuationIssue='';$confirmedValue=false;
foreach($plan['years'] as $row){
    $snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);
    if((int)$row['year']===$year){$value=$snapshot['valuation'];$confirmedValue=true;}
    if((int)$row['year']===$year-1)$previous=$snapshot['valuation'];
}
if($value===null)try{$value=AssetValuation::calculate(AssetValuation::fromBookPlan($data),$valuationPeriod,[],$previous);}catch(DomainException $error){$valuationIssue=$error->getMessage();}
?>
<form method="get"><?= hidden('view','finance').hidden('tab','book-values').hidden('item',$itemId) ?><label>Für welches Jahr?<input class="form-control" name="year" type="number" min="1900" max="2199" value="<?= $year ?>" required></label><button class="btn btn-outline theme-secondary secondary">Jahr anzeigen</button></form>
<p>Bewertung zu ursprünglichen Kosten ohne planmäßige Abschreibung. Buch- und Steuerwerte werden getrennt fortgeführt.</p>
<?php if($valuationIssue!==''): ?><p><?= e($valuationIssue) ?></p><?php else: ?>
<p><?= $confirmedValue?'Bestätigter Jahresstand':'Vorschau ohne neue Bewertungsereignisse · noch nicht bestätigt' ?></p>
<div class="table-scroll"><table class="table"><thead><tr><th>Wertentwicklung <?= $year ?></th><th>Buchwert</th><th>Steuerwert</th></tr></thead><tbody>
<?php foreach(['book_opening_cents'=>'Wert zu Jahresbeginn','additions_cents'=>'Anschaffungskosten der Zugänge','impairment_cents'=>'Wertminderung','writeup_cents'=>'Wertaufholung','book_disposals_cents'=>'Restwert der Abgänge','book_closing_cents'=>'Verbleibender Wert am Jahresende'] as $field=>$label): ?><tr><th scope="row"><?= e($label) ?></th><td><?= euro($value['book'][$field]) ?></td><td><?= euro($value['tax'][$field]) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php foreach($value['source']['events'] as $event): ?><p><?= e($event['on'].' · '.$event['reason']) ?> · <a href="<?= e(url(['view'=>'documents','doc'=>$event['document']['document_id']])) ?>">Originalnachweis ansehen</a></p><?php endforeach ?>
<?php endif ?>
<?php if($canConfirmYear): ?>
<details <?= input($_POST,'action')==='asset-book-year'?'open':'' ?>><summary>Bewertung für <?= $year ?> prüfen und bestätigen</summary>
<form method="post"><?= csrf().hidden('action','asset-book-year').hidden('item',$itemId).hidden('version',input($_POST,'action')==='asset-book-year'?input($_POST,'version'):(string)$plan['version']).hidden('year',(string)$year) ?>
<?php require __DIR__.'/asset-cost-events.php'; ?>
<p>Die Bestätigung speichert den belegten Jahresstand unveränderlich. Die Übernahme ins Bilanzjournal wird anschließend gesondert geprüft.</p>
<button class="btn btn-solid theme-primary">Bewertung <?= $year ?> bestätigen</button>
</form></details>
<?php endif ?>
