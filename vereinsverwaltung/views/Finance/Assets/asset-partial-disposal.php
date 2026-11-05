<?php declare(strict_types=1);
use Kulturhus\Verein\{AssetPartialDisposalRecords,Documents};
require_once __DIR__.'/../../../src/Finance/Assets/AssetPartialDisposalRecords.php';
$partialId=input($_GET,'partial_review',input($_POST,'partial_review'));
$partialRecord=$partialId!==''?(new AssetPartialDisposalRecords($db,(string)$_SESSION['actor']))->get($partialId):null;
if($partialRecord&&($partialRecord['item_id']!==$itemId||$partialRecord['year']!==$year))throw new DomainException('Prüfstand gehört zu einem anderen Gegenstand oder Jahr.');
$partialDraft=input($_POST,'action')==='asset-partial-prepare'?$_POST:[];
$partialDocs=(new AssetPartialDisposalRecords($db,(string)$_SESSION['actor']))->evidenceChoices($itemId);
if($partialRecord&&!$partialDraft){
    $p=$partialRecord['review']['proof'];$partialDraft=['disposal_date'=>$p['date'],'disposal_document'=>$p['document_id'],
        'component_document'=>$p['allocation']['document_id'],'component_cost'=>Kulturhus\Verein\OutgoingInvoices::amount($p['allocation']['cost_cents']),
        'component_residual'=>Kulturhus\Verein\OutgoingInvoices::amount($p['allocation']['residual_cents']),'disposal_reason'=>$p['reason']];
}
?>
<details id="partial-disposal" class="card" <?= $partialRecord||$partialDraft?'open':'' ?>>
<summary>Nur einen Teil des Gegenstands ausmustern</summary>
<?php if($partialRecord):$partialValues=$partialRecord['review']['calculation']['book']; ?>
<h3>Teilabgang prüfen</h3>
<dl><dt>Datum</dt><dd><?= e(implode('.',array_reverse(explode('-',$partialRecord['review']['proof']['date'])))) ?></dd>
<dt>Anschaffungskosten des entfernten Teils</dt><dd><?= euro($partialValues['disposals_cents']) ?></dd>
<dt>Restwert des entfernten Teils</dt><dd><?= euro($partialValues['book_disposals_cents']) ?></dd>
<dt>Abschreibung für <?= $year ?></dt><dd><?= euro($partialValues['depreciation_cents']) ?></dd>
<dt>Verbleibender Buchwert am Jahresende</dt><dd><strong><?= euro($partialValues['book_closing_cents']) ?></strong></dd></dl>
<p><?= e($partialRecord['review']['proof']['reason']) ?></p>
<?php foreach(['disposal'=>'Ausmusterungsnachweis','allocation'=>'Kostennachweis'] as $key=>$label): ?>
<p><a href="<?= e(url(['view'=>'documents','doc'=>$partialRecord['review']['evidence'][$key]['document_id']])) ?>"><?= e($label) ?> ansehen</a></p>
<?php endforeach ?>
<?php if($partialRecord['is_latest']): ?>
<form method="post"><?= csrf().hidden('action','asset-partial-book').hidden('partial_review',$partialId).hidden('partial_sha256',$partialRecord['sha256']).hidden('item',$itemId) ?>
<label class="check"><input class="check" type="checkbox" name="partial_book_confirmed" value="1" required>Nachweise und Beträge stimmen. Teilabgang und Jahresabschreibung verbindlich buchen.</label>
<button class="btn btn-solid theme-primary">Teilabgang und Jahreswert buchen</button></form>
<?php else: ?><p>Ein neuerer Prüfstand liegt vor. Angaben unten erneut berechnen.</p><?php endif ?>
<?php endif ?>
<?php if($partialRecord): ?><details><summary>Angaben ändern</summary><?php endif ?>
<form method="post"><?= csrf().hidden('action','asset-partial-prepare').hidden('item',$itemId).hidden('version',(string)$plan['version']).hidden('year',(string)$year) ?>
<h3>Welcher Teil wurde entfernt?</h3>
<label>Datum der Ausmusterung<input class="form-control" type="date" name="disposal_date" min="<?= $year ?>-01-01" max="<?= $year ?>-12-31" value="<?= e(input($partialDraft,'disposal_date')) ?>" required></label>
<label>Anschaffungskosten dieses Teils · EUR<input class="form-control" name="component_cost" inputmode="decimal" value="<?= e(input($partialDraft,'component_cost')) ?>" required></label>
<?php if($data['residual_cents']>0): ?>
<label>Geplanter Restwert dieses Teils nach Ende der Nutzungsdauer · EUR<input class="form-control" name="component_residual" inputmode="decimal" value="<?= e(input($partialDraft,'component_residual','0,00')) ?>" required></label>
<?php else: ?><?= hidden('component_residual','0,00') ?><?php endif ?>
<?php foreach(['component_document'=>'Rechnung / Nachweis der Teilkosten','disposal_document'=>'Nachweis der Ausmusterung'] as $field=>$label): ?>
<label><?= e($label) ?><select class="form-control" name="<?= e($field) ?>" data-searchable-select required><option value="">Nachweis auswählen</option>
<?php foreach($partialDocs[$field] as $doc): ?><option value="<?= e($doc['id']) ?>" <?= input($partialDraft,$field)===$doc['id']?'selected':'' ?>><?= e($doc['title']) ?></option><?php endforeach ?>
</select></label><?php endforeach ?>
<?php if(!$partialDocs['disposal_document']): ?><p><a href="<?= e(url(['view'=>'documents','source_type'=>'item','source_id'=>$itemId])) ?>">Ausmusterungsnachweis beim Gegenstand ablegen</a></p><?php endif ?>
<label>Welcher Teil und warum?<textarea class="form-control" name="disposal_reason" maxlength="2000" required><?= e(input($partialDraft,'disposal_reason')) ?></textarea></label>
<label class="check"><input class="check" type="checkbox" name="partial_confirmed" value="1" required>Teilkosten sind belegt. Es gab keinen Erlös und keine Entschädigung. Der verbleibende Teil wird unverändert weitergenutzt; die Nutzungsdauer bleibt gleich.</label>
<button class="btn btn-outline theme-secondary secondary">Teilabgang berechnen</button></form><?php if($partialRecord): ?></details><?php endif ?>
<?php if($partialActive): ?><p><a href="<?= e(url(['view'=>'finance','tab'=>'book-values','item'=>$itemId,'year'=>(string)$year])) ?>">Zurück zum Gegenstand</a></p><?php endif ?></details>
