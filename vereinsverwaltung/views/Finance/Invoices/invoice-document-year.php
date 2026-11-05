<?php declare(strict_types=1);
if(!isset($invoiceEntryText))require __DIR__.'/../../../src/Web/invoice-draft-labels.php';
$invoiceYear=\Kulturhus\Verein\TaxCatalog::documentYear($invoiceDocumentDate??'');
$invoiceYearOptions=[];$formPreferences=new \Kulturhus\Verein\FinancePreferences($db,(string)$_SESSION['actor']);
$invoiceCatalogOptions=static function(int $requestedYear)use($formPreferences,$finance,$invoiceEntryText):array{
 $catalogYear=\Kulturhus\Verein\TaxCatalog::assignmentYear($requestedYear);
 $visible=$formPreferences->visibleAccounts($finance->taxAccounts($catalogYear),$catalogYear);
 $common=array_fill_keys(array_keys(\Kulturhus\Verein\TaxCatalog::commonInvoiceAccounts($catalogYear)),true);
 $preference=$formPreferences->data()['accounts'][$catalogYear]??['mode'=>'all','codes'=>[]];
 $chosen=array_fill_keys(array_map('strval',$preference['codes']??[]),true);
 $visible=array_filter($visible,static fn($a)=>!empty($a['active'])&&(isset($common[(string)$a['code']])||isset($chosen[(string)$a['code']])||!empty($a['custom'])));
 return ['sphere'=>array_map($invoiceEntryText,\Kulturhus\Verein\TaxCatalog::costCenters()),
  'account'=>array_map(static fn($a)=>$a['code'].' · '.$a['name'],$visible),
  'booking_key'=>array_map(static fn($k)=>$k['name'],\Kulturhus\Verein\TaxCatalog::supportedKeys($catalogYear)),
  'catalog_year'=>$catalogYear,
  'fallback'=>\Kulturhus\Verein\TaxCatalog::assignmentUsesFallback($requestedYear),
  'account_help'=>\Kulturhus\Verein\TaxCatalog::assignmentUsesFallback($requestedYear)
    ?$invoiceEntryText('Für die Zuordnung eines historischen Belegs wird der aktuelle geprüfte SKR42-Katalog {year} verwendet. Das Rechnungsjahr bleibt unverändert.',['year'=>$catalogYear])
    :$invoiceEntryText('Die Auswahl enthält die häufigsten Vereinskonten. Weitere Konten können in den Finanz-Einstellungen freigeschaltet werden.')];
};
foreach(\Kulturhus\Verein\TaxCatalog::YEARS as $taxYear)$invoiceYearOptions[(string)$taxYear]=$invoiceCatalogOptions($taxYear);
if($invoiceYear>0&&!\Kulturhus\Verein\TaxCatalog::hasYear($invoiceYear))$invoiceYearOptions[(string)$invoiceYear]=$invoiceCatalogOptions($invoiceYear);
$invoiceYearOptions['fallback']=$invoiceCatalogOptions(\Kulturhus\Verein\TaxCatalog::YEAR);
$invoiceYearMessage=$invoiceYear?$invoiceEntryText('Rechnungsjahr {year} · ',['year'=>$invoiceYear]):$invoiceEntryText('Bitte das Rechnungsdatum angeben. ');
$invoiceYearMessage.=$invoiceYear?(\Kulturhus\Verein\TaxCatalog::hasYear($invoiceYear)?$invoiceEntryText('Steuerkonten werden aus dem geprüften SKR42-Katalog {year} angeboten.',['year'=>$invoiceYear]):$invoiceEntryText('Für die Zuordnung dieses historischen Belegs wird der aktuelle geprüfte SKR42-Katalog {year} angeboten. Das Rechnungsjahr bleibt erhalten; ein Export für dieses historische Jahr wird separat geprüft.',['year'=>\Kulturhus\Verein\TaxCatalog::YEAR])):$invoiceEntryText('Das Jahr wird automatisch übernommen.');
if($invoiceYear&&\Kulturhus\Verein\TaxCatalog::hasYear($invoiceYear)&&!\Kulturhus\Verein\TaxCatalog::supportedKeys($invoiceYear))$invoiceYearMessage.=$invoiceEntryText(' Für dieses Jahr sind keine geprüften BU-Schlüssel hinterlegt. Eine erforderliche Steuerbehandlung vor dem Export klären.');
$yearTextSources=['Rechnungsjahr ','Bitte das Rechnungsdatum angeben. ','Für die Zuordnung wird der aktuelle geprüfte SKR42-Katalog ',' angeboten. Das Rechnungsjahr bleibt erhalten; ein Export für dieses historische Jahr wird separat geprüft.','Steuerkonten werden aus dem geprüften SKR42-Katalog ',' angeboten.','Das Jahr wird automatisch übernommen.',' Für dieses Jahr sind keine geprüften BU-Schlüssel hinterlegt. Eine erforderliche Steuerbehandlung vor dem Export klären.'];
$yearClientMessages=array_intersect_key(\Kulturhus\Native\Language\InvoiceEntryMessages::client($invoiceEntryMessages),array_flip($yearTextSources));
?>
<p data-invoice-entry-messages="<?=e(json_encode($yearClientMessages,JSON_THROW_ON_ERROR))?>" class="muted" data-invoice-document-year data-tax-spheres="<?= e(json_encode(array_map($invoiceEntryText,\Kulturhus\Verein\TaxCatalog::costCenters()),JSON_THROW_ON_ERROR)) ?>" data-tax-year-options="<?= e(json_encode($invoiceYearOptions,JSON_THROW_ON_ERROR)) ?>" role="status"><?= e($invoiceYearMessage) ?></p>
<script defer src="invoice-entry-text.js?v=1"></script><script defer src="invoice-document-year.js?v=7"></script>

<?php require __DIR__.'/../Accounting/finance-form-preferences.php'; ?>
