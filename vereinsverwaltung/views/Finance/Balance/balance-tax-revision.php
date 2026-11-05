<?php declare(strict_types=1);

$balanceFilingMessages=$annualTaxMessages??[];
$bt=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$balanceFilingMessages);
use Kulturhus\Verein\BalanceFilingRevision;
$revision=$b['filing_revision']??['status'=>'E','reason'=>'','legal_basis'=>'','source'=>null];
$revisionSource=$revision['source']['filing_id']??'';
if(input($_POST,'action')==='tax-filing-balance-revision'&&input($_POST,'filing')===$fid){
    $revision=['status'=>input($_POST,'revision_status'),'reason'=>input($_POST,'revision_reason'),'legal_basis'=>input($_POST,'revision_legal_basis')];$revisionSource=input($_POST,'revision_source');
}
?>
<details class="card"><summary><?=e($bt('Abgabeart:'))?> <?= e(BalanceFilingRevision::LABELS[$revision['status']]??$bt('Bitte prüfen')) ?></summary>
<?php if($editable): ?><form method="post"><?= csrf().hidden('filing',$fid).hidden('version',input($_POST,'action')==='tax-filing-balance-revision'?input($_POST,'version'):(string)$filing['version']).hidden('tax_year',(string)$year).hidden('action','tax-filing-balance-revision') ?><label><?=e($bt('Was wird abgegeben?'))?><select class="form-control" name="revision_status" required><?php foreach(BalanceFilingRevision::LABELS as $key=>$label): ?><option value="<?= e($key) ?>" <?= $revision['status']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<label><?=e($bt('Vorherige Abgabe · nur bei Korrektur'))?><select class="form-control" name="revision_source"><option value=""><?=e($bt('Keine – erstmalige Abgabe'))?></option><?php foreach((new BalanceFilingRevision($db,(string)$_SESSION['actor']))->choices($filing) as $previous): ?><option value="<?= e($previous['filing_id']) ?>" <?= $revisionSource===$previous['filing_id']?'selected':'' ?>><?= e($previous['at'].' · '.$previous['transfer_ticket']) ?></option><?php endforeach ?></select></label>
<label><?=e($bt('Grund und Nachweis'))?><textarea class="form-control" name="revision_reason" maxlength="1000" required><?= e($revision['reason']) ?></textarea></label>
<label><?=e($bt('Steuerliche Zulässigkeit · nur bei Korrektur'))?><textarea class="form-control" name="revision_legal_basis" maxlength="1000"><?= e($revision['legal_basis']) ?></textarea></label>
<label class="check"><input class="check" type="checkbox" name="revision_confirmed" value="1" required><?=e($bt('Abgabeart und bisherige Übermittlungen geprüft, auch außerhalb dieses Programms. Die Korrektur ist zulässig; bei erstmaliger Abgabe wurde dieser Abschluss noch nicht übermittelt.'))?></label>
<button class="btn btn-solid theme-primary"><?=e($bt('Abgabeart speichern'))?></button></form>
<?php else: ?><p><?= e($revision['reason']) ?></p><?php if($revisionSource!==''): ?><a href="<?= e(url($base+['filing'=>$revisionSource])) ?>"><?=e($bt('Vorherige Abgabe öffnen'))?></a><?php endif ?><?php endif ?></details>
