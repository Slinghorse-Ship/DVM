<?php declare(strict_types=1);
use Kulturhus\Verein\Documents;
$valuationDocs=$valuationDocs??array_filter((new Documents($db,(string)$_SESSION['actor']))->listing(),static fn($d)=>$d['state']==='active'&&in_array('finance',$d['scopes'],true));
$valuationDraft=$valuationDraft??(is_array($_POST['valuation']??null)?$_POST['valuation']:[]);
$valuationDraft=array_values(array_filter($valuationDraft,'is_array'));
$rows=max(3,min(20,count($valuationDraft)));
?>
<div data-cost-events>
<p>Wertminderung, Wertaufholung oder Abgang jeweils mit Datum und Nachweis erfassen. Die Ereignisse müssen in zeitlicher Reihenfolge stehen. Ohne Ereignis bleiben die bestätigten Vorjahreswerte bestehen.</p>
<?php for($i=0;$i<$rows;$i++):$row=$valuationDraft[$i]??[];$prefix='valuation['.$i.']'; ?>
<fieldset data-cost-event><legend>Bewertungsereignis <?= $i+1 ?></legend>
<?= hidden($prefix.'[id]',input($row,'id',bin2hex(random_bytes(16)))) ?>
<label>Was hat sich geändert?<select class="form-control" name="<?= e($prefix) ?>[kind]" data-cost-kind><option value="">Kein Ereignis</option><?php foreach(['impairment'=>'Wertminderung','writeup'=>'Wertaufholung','disposal'=>'Abgang eines belegten Teils ohne Erlös'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= input($row,'kind')===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<div data-cost-event-fields>
<label>Datum<input class="form-control" type="date" name="<?= e($prefix) ?>[on]" min="<?= e($valuationPeriod['start']) ?>" max="<?= e($valuationPeriod['end']) ?>" value="<?= e(input($row,'on')) ?>"></label>
<p data-cost-value-label>Bei einer Wertänderung: gesamte verbleibende Buch- und Steuerwerte nach diesem Ereignis. Bei einem Abgang: Buch- und Steuerwerte ausschließlich des abgegangenen Teils.</p>
<div class="form-grid"><label>Buchwert · EUR<input class="form-control" name="<?= e($prefix) ?>[book]" inputmode="decimal" value="<?= e(input($row,'book')) ?>"></label><label>Steuerwert · EUR<input class="form-control" name="<?= e($prefix) ?>[tax]" inputmode="decimal" value="<?= e(input($row,'tax')) ?>"></label></div>
<label>Originales Gegenkonto der Wertminderung<select class="form-control" name="<?= e($prefix) ?>[counter_code]"><option value="">Aus bestätigter Anlagenart und Bewertungsereignis</option><option value="72070" <?= input($row,'counter_code')==='72070'?'selected':'' ?>>72070 · Finanzanlage an verbundenem Unternehmen</option></select></label>
<div data-cost-impairment><label class="check"><input class="check" type="checkbox" name="<?= e($prefix) ?>[permanent]" value="1" <?= input($row,'permanent')==='1'?'checked':'' ?>>Die Wertminderung ist voraussichtlich dauerhaft.</label><label class="check"><input class="check" type="checkbox" name="<?= e($prefix) ?>[tax_election]" value="1" <?= input($row,'tax_election')==='1'?'checked':'' ?>>Der niedrigere steuerliche Teilwert wird anhand des Nachweises gewählt.</label></div>
<div data-cost-disposal>
<label>Ursprüngliche Kosten des abgegangenen Teils · EUR<input class="form-control" name="<?= e($prefix) ?>[cost]" inputmode="decimal" value="<?= e(input($row,'cost')) ?>"></label>
<label>Nachweis der Kosten- und Wertaufteilung<select class="form-control" name="<?= e($prefix) ?>[allocation_document_id]"><option value="">Nachweis wählen …</option><?php foreach($valuationDocs as $d): ?><option value="<?= e($d['id']) ?>" <?= input($row,'allocation_document_id')===$d['id']?'selected':'' ?>><?= e($d['title']) ?></option><?php endforeach ?></select></label>
<label class="check"><input class="check" type="checkbox" name="<?= e($prefix) ?>[no_proceeds]" value="1" <?= input($row,'no_proceeds')==='1'?'checked':'' ?>>Kein Verkauf, keine Schenkung oder Entnahme und kein Erlös, Ersatz oder Entschädigung. Abgegangener Teil und seine Restwerte sind belegt.</label>
</div>
<label>Originalnachweis<select class="form-control" name="<?= e($prefix) ?>[document_id]"><option value="">Nachweis wählen …</option><?php foreach($valuationDocs as $d): ?><option value="<?= e($d['id']) ?>" <?= input($row,'document_id')===$d['id']?'selected':'' ?>><?= e($d['title']) ?></option><?php endforeach ?></select></label>
<label>Grund der Bewertung<textarea class="form-control" name="<?= e($prefix) ?>[reason]" maxlength="2000"><?= e(input($row,'reason')) ?></textarea></label>
<label>Fachlich geprüfte Rechtsgrundlage<textarea class="form-control" name="<?= e($prefix) ?>[legal_basis]" maxlength="2000"><?= e(input($row,'legal_basis')) ?></textarea></label>
<label class="check"><input class="check" type="checkbox" name="<?= e($prefix) ?>[confirmed]" value="1" <?= input($row,'confirmed')==='1'?'checked':'' ?>>Datum, Werte, Bewertungsgrund und Originalnachweis sind geprüft.</label>
</div></fieldset>
<?php endfor ?>
<button type="button" class="btn btn-outline theme-secondary secondary" data-cost-add>Weiteres Ereignis erfassen</button>
</div>
