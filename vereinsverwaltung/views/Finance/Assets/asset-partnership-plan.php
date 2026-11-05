<?php declare(strict_types=1);
use Kulturhus\Verein\{PartnershipBookBasis,Documents,TaxCatalog};
$holdingPosted=$posted&&input($_POST,'method')==='partnership_hgb';
$holdingDraft=$holdingPosted?$_POST:array_replace($draft,$partnership?[
 'partnership_document_id'=>$data['partnership_plan']['document']['document_id'],
 'partnership_relationship'=>$data['partnership_relationship']['kind'],
 'partnership_relationship_document_id'=>$data['partnership_relationship']['document']['document_id'],
 'partnership_relationship_reason'=>$data['partnership_relationship']['reason']]:[]);
$holdingDocs=array_filter((new Documents($db,(string)$_SESSION['actor']))->listing(),static fn($d)=>$d['state']==='active'&&in_array('finance',$d['scopes'],true));
?>
<details class="card fw-workflow" <?= $partnership||$holdingPosted?'open':'' ?>><summary>Beteiligung an einer Personengesellschaft erfassen</summary>
<p>Die Anschaffung und die handelsrechtliche Bewertung anhand der Originalbelege bestätigen. Steuerliche Kapital-, Ergänzungs- und Sonderbilanzwerte werden später gesondert geprüft.</p>
<form method="post"><?= csrf().hidden('action','asset-book-save').hidden('item',$itemId).hidden('version',$holdingPosted?input($_POST,'version'):(string)$plan['version']).hidden('method','partnership_hgb') ?>
<div class="form-grid"><label>Ursprüngliche Anschaffungskosten · EUR<input class="form-control" name="basis" inputmode="decimal" required value="<?= e(input($holdingDraft,'basis')) ?>"></label>
<label>Datum der Anschaffung<input class="form-control" type="date" name="acquired_on" max="<?= date('Y-m-d') ?>" required value="<?= e(input($holdingDraft,'acquired_on')) ?>"></label></div>
<label>Originales Beteiligungskonto<select class="form-control" name="account" required><option value="">Konto anhand des Belegs wählen …</option><?php $holdingCatalog=TaxCatalog::accounts((int)date('Y'));foreach(PartnershipBookBasis::ACCOUNTS as $code): ?><option value="<?= e($code) ?>" <?= input($holdingDraft,'account')===$code?'selected':'' ?>><?= e($code.' · '.($holdingCatalog[$code]['name']??$code)) ?></option><?php endforeach ?></select></label>
<label>Vereinsbereich<select class="form-control" name="sphere" required><option value="">Geklärten Bereich wählen …</option><?php foreach(TaxCatalog::SPHERES as $code=>$label):if((string)$code==='9')continue; ?><option value="<?= e((string)$code) ?>" <?= input($holdingDraft,'sphere')===(string)$code?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<?php foreach(['partnership_document_id'=>'Originaler Anschaffungsbeleg','partnership_relationship_document_id'=>'Beleg zur Beziehung zur Gesellschaft'] as $field=>$label): ?><label><?= e($label) ?><select class="form-control" name="<?= e($field) ?>" required data-searchable-select><option value="">Finanzbeleg wählen …</option><?php foreach($holdingDocs as $d): ?><option value="<?= e($d['id']) ?>" <?= input($holdingDraft,$field)===$d['id']?'selected':'' ?>><?= e($d['title']) ?></option><?php endforeach ?></select></label><?php endforeach ?>
<label>Belegte Beziehung<select class="form-control" name="partnership_relationship" required><option value="">Beziehung anhand der Unterlagen wählen …</option><?php foreach(['participation'=>'Beteiligung','affiliated'=>'Verbundenes Unternehmen','parent_company'=>'Herrschendes oder mehrheitlich beteiligtes Unternehmen'] as $code=>$label): ?><option value="<?= e($code) ?>" <?= input($holdingDraft,'partnership_relationship')===$code?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<label>Nachweis zur Beziehung<textarea class="form-control" name="partnership_relationship_reason" required maxlength="2000"><?= e(input($holdingDraft,'partnership_relationship_reason')) ?></textarea></label>
<label>Grund und Nachweis der ursprünglichen HGB-Bewertung<textarea class="form-control" name="reason" required maxlength="2000"><?= e(input($holdingDraft,'reason')) ?></textarea></label>
<label class="check"><input class="check" type="checkbox" name="partnership_relationship_reviewed" value="1" required <?= input($holdingDraft,'partnership_relationship_reviewed')==='1'?'checked':'' ?>>Ich habe die Beziehung und das dazugehörige Beteiligungskonto anhand der Unterlagen geprüft.</label>
<label class="check"><input class="check" type="checkbox" name="asset_reviewed" value="1" required <?= input($holdingDraft,'asset_reviewed')==='1'?'checked':'' ?>>Ich habe Anschaffungskosten, Eigentum und Vereinsbereich geprüft.</label><button class="btn btn-solid theme-primary">HGB-Beteiligungsplan speichern</button>
</form></details>
