<?php declare(strict_types=1); use Kulturhus\Verein\AssetTypes;
require_once __DIR__.'/../../../src/Finance/Assets/OriginalExceptionalDepreciation.php';
$exceptionalCode=Kulturhus\Verein\OriginalExceptionalDepreciation::counter($data);
if($exceptionalCode!==null&&!isset($data['original_leasing'])):
$exceptionalDocs=array_filter($exceptionalDocs??$valuationDocs??(new Kulturhus\Verein\Documents($db,(string)$_SESSION['actor']))->listing(),static fn($d)=>$d['state']==='active'&&in_array('finance',$d['scopes'],true)); ?>
<details><summary>Außerplanmäßige Abschreibung / AfaA gesondert bestätigen</summary><fieldset><legend>Originalnachweis zum Jahresende</legend><p>Regelmäßige AfA bleibt eine eigene Buchung. Buch- und Steuerentscheidung unabhängig anhand des Originals prüfen. Hier sind nur gleiche Beträge im Anschaffungsjahr freigegeben; Abgänge und Folgejahre benötigen einen eigenen Nachweis.</p>
<label>Zusätzliche Jahresentscheidung<select class="form-control" name="exceptional_code"><option value="">Keine AfaA</option><option value="<?= e($exceptionalCode) ?>" <?= input($_POST,'exceptional_code')===$exceptionalCode?'selected':'' ?>><?= e($exceptionalCode) ?> · belegte zusätzliche AfaA</option></select></label>
<label>Zusätzlicher Buchbetrag in Euro<input class="form-control" name="exceptional_book" value="<?= e(input($_POST,'exceptional_book')) ?>" inputmode="decimal"></label>
<label>Zusätzlicher Steuerbetrag in Euro<input class="form-control" name="exceptional_tax" value="<?= e(input($_POST,'exceptional_tax')) ?>" inputmode="decimal"></label>
<label>Begründung der Buchbewertung<textarea class="form-control" name="exceptional_book_reason" maxlength="2000"><?= e(input($_POST,'exceptional_book_reason')) ?></textarea></label>
<label>Begründung und Rechtsgrundlage der Steuerbewertung<textarea class="form-control" name="exceptional_tax_reason" maxlength="2000"><?= e(input($_POST,'exceptional_tax_reason')) ?></textarea></label>
<label>Originaler Finanznachweis<select class="form-control" name="exceptional_document_id"><option value="">Nachweis auswählen …</option><?php foreach($exceptionalDocs as $row): ?><option value="<?= e($row['id']) ?>" <?= input($_POST,'exceptional_document_id')===$row['id']?'selected':'' ?>><?= e($row['title']) ?></option><?php endforeach ?></select></label>
<label class="check"><input class="check" type="checkbox" name="exceptional_book_reviewed" value="1" <?= input($_POST,'exceptional_book_reviewed')==='1'?'checked':'' ?>> Buchbewertung anhand des ursprünglichen Nachweises bestätigt.</label>
<label class="check"><input class="check" type="checkbox" name="exceptional_tax_reviewed" value="1" <?= input($_POST,'exceptional_tax_reviewed')==='1'?'checked':'' ?>> Steuerliche AfaA unabhängig bestätigt; kein Schluss allein aus dem Konto oder Buchbetrag.</label>
</fieldset></details><?php endif ?>
