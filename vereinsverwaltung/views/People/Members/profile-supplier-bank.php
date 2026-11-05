<?php declare(strict_types=1);
$memberProfileViewMessages=$managerLanguageMessages??[];
$memberProfileText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$memberProfileViewMessages,$values);

if(!$profile||$profile['kind']!=='organisation')return;
require_once __DIR__.'/../../../src/Finance/Invoices/InvoiceSupplierContacts.php';
$supplierProfileFinance=new \Kulturhus\Verein\Finance($db,(string)$_SESSION['actor']);
$supplierBankReadable=$supplierProfileFinance->canRead();
$supplierBankWritable=$supplierBankReadable&&$supplierProfileFinance->canWrite()&&$registry->canWrite();
?>
<div class="content profile-extra"><section class="card" id="supplier-bank"><h2><?= e($memberProfileText('Bankverbindung des Lieferanten')) ?></h2>
<?php if(!$supplierBankReadable): ?><p><?= e($memberProfileText('Zum Ansehen der Bankdaten ist zusätzlich die Finanzberechtigung erforderlich.')) ?></p>
<?php else:
 $supplierProfileBank=(new \Kulturhus\Verein\InvoiceSupplierContacts($db,(string)$_SESSION['actor']))->bank($profile['id']);
 $supplierHasBank=$supplierProfileBank['iban']!==''||$supplierProfileBank['bic']!=='';
 $supplierBankDraft=$supplierProfileBank;
 $supplierBankFailed=$error!==''&&input($_POST,'action')==='supplier-bank-save'&&input($_POST,'id')===$profile['id'];
 if($supplierBankFailed)$supplierBankDraft=['iban'=>input($_POST,'supplier_iban'),'bic'=>input($_POST,'supplier_bic')];
 $supplierBankVersion=$supplierBankFailed?input($_POST,'version'):$profile['version'];
 $supplierBankInvalid=$supplierBankFailed?(str_contains($error,'IBAN')?'iban':(str_contains($error,'BIC')?'bic':'')):'';
 $supplierBankAttrs=static fn(string $field):string=>$supplierBankInvalid===$field?' aria-invalid="true" aria-describedby="supplier-bank-error" autofocus':'';
?>
<p><?= e($memberProfileText('Diese Bankverbindung gehört zu')) ?> <strong><?= e($profile['name']) ?></strong><?= e($memberProfileText('. Sie ist auch bei Rechnungen sichtbar, die mit diesem Firmenkontakt verknüpft sind.')) ?></p>
<?php if($supplierBankWritable): ?>
<form method="post" autocomplete="off"><?= csrf().hidden('action','supplier-bank-save').hidden('id',$profile['id']).hidden('version',$supplierBankVersion) ?>
<div class="form-grid"><label><?= e($memberProfileText('IBAN')) ?> <span class="required">*</span><input class="form-control" name="supplier_iban" required maxlength="42" data-iban spellcheck="false" autocomplete="off" value="<?= e($supplierBankDraft['iban']) ?>"<?= $supplierBankAttrs('iban') ?>></label><label><?= e($memberProfileText('BIC (optional)')) ?><input class="form-control" name="supplier_bic" maxlength="11" spellcheck="false" autocomplete="off" value="<?= e($supplierBankDraft['bic']) ?>"<?= $supplierBankAttrs('bic') ?>></label></div>
<?php if($supplierBankInvalid!==''): ?><p id="supplier-bank-error" class="field-validation-error" role="alert"><?= e($error) ?></p><?php endif ?>
<p class="muted"><?= e($memberProfileText('Die IBAN wird auf Schreibfehler geprüft. Vor einer Zahlung bitte mit der Originalrechnung vergleichen. Änderungen hier ändern keine bereits gespeicherten Rechnungen oder Zahlungen.')) ?></p>
<button class="btn btn-solid theme-primary"><?= e($memberProfileText('Bankdaten speichern')) ?></button></form>
<?php if($supplierHasBank): ?><form method="post" data-confirm="<?= e($memberProfileText('Nur die Bankverbindung dieses Lieferanten entfernen? Rechnungen und Zahlungen bleiben erhalten.')) ?>"><?= csrf().hidden('action','supplier-bank-remove').hidden('id',$profile['id']).hidden('version',$profile['version']) ?><p><button class="btn btn-outline theme-secondary secondary"><?= e($memberProfileText('Bankverbindung entfernen')) ?></button></p></form><?php endif ?>
<?php else: ?>
<?php if($supplierHasBank): ?><dl class="data-list"><dt><?= e($memberProfileText('IBAN')) ?></dt><dd><?= e($supplierProfileBank['iban']) ?></dd><dt><?= e($memberProfileText('BIC')) ?></dt><dd><?= e($supplierProfileBank['bic']?:$memberProfileText('Nicht hinterlegt')) ?></dd></dl><?php else: ?><p><?= e($memberProfileText('Noch keine Bankverbindung hinterlegt.')) ?></p><?php endif ?>
<p class="muted"><?= e($memberProfileText('Zum Ändern sind Bearbeitungsrechte für Kontakte und Finanzen erforderlich.')) ?></p>
<?php endif;endif ?>
</section></div>
