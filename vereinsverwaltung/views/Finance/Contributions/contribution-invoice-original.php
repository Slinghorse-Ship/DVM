<?php declare(strict_types=1);
if(($_POST['action']??null)!=='contribution-invoice'||($error??'')==='')return;
$invoiceSourceOriginal=\Kulturhus\Verein\Web\ContributionInvoiceForm::original($_POST);
$invoiceSourceRoute=$financeEntry?scalarFields($_GET,['view','tab','kind','status','from','to','q','account','direction','sort','order','page']):['view'=>'finance','tab'=>'contributions'];
if($financeEntry)$invoiceSourceRoute['entry']=$financeEntry['id'];
?>
<link rel="stylesheet" href="finance-receipt.css?v=4">
<section class="card" data-contribution-invoice-original role="status">
<h2><?=e($financeFrameText('Beitragsrechnungsquelle'))?></h2>
<p><?=e($financeFrameText('Die ursprüngliche Belegkennung bleibt sichtbar. Eine andere Rechnung wird nicht stillschweigend gewählt. Bitte den geöffneten Beleg bewusst erneut prüfen.'))?></p>
<pre><?=e(json_encode($invoiceSourceOriginal,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR))?></pre>
<a class="btn btn-outline theme-secondary" href="<?=e(url($invoiceSourceRoute))?>"><?=e($financeFrameText($financeEntry?'Geöffneten Beleg neu prüfen':'Beitragsliste öffnen'))?></a>
</section>
