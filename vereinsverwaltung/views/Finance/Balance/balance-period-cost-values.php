<?php declare(strict_types=1);
$periodCostTextMessages=$managerLanguageMessages??[];
$periodCostText=static fn(string$text):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($text,$periodCostTextMessages);
// Read-only preview; importing repeats document, period and finance-write checks.
$valuationPeriod=$r['period'];$valuationDraft=[];
if(input($_GET,'valuation_preview')==='1'&&is_array($_GET['valuation']??null))$valuationDraft=$_GET['valuation'];
else foreach($options['valuations']??[] as $event){
    $row=['id'=>$event['id'],'kind'=>$event['kind'],'on'=>$event['on'],'book'=>number_format($event['book_cents']/100,2,',',''),
        'counter_code'=>$event['counter_code']??'','tax'=>number_format($event['tax_cents']/100,2,',',''),'reason'=>$event['reason'],'legal_basis'=>$event['legal_basis'],
        'document_id'=>$event['document']['document_id'],'confirmed'=>!empty($event['confirmed'])?'1':'',
        'permanent'=>!empty($event['permanent'])?'1':'','tax_election'=>!empty($event['tax_election'])?'1':''];
    if($event['kind']==='disposal')$row+=['cost'=>number_format($event['cost_cents']/100,2,',',''),
        'allocation_document_id'=>$event['allocation_document']['document_id'],'no_proceeds'=>!empty($event['no_proceeds'])?'1':''];
    $valuationDraft[]=$row;
}
?>
<details <?= $valuationDraft?'open':'' ?>><summary><?= e($periodCostText('Bewertung oder belegten Abgang prüfen')) ?></summary>
<form method="get" data-period-cost-values><?= hidden('view','finance').hidden('tab','tax').hidden('balance','1').hidden('periods','1').hidden('period_id',$periodId).hidden('period_source',$selected).($sourceCatalogueQueryHidden??'').hidden('valuation_preview','1') ?>
<?php require __DIR__.'/../Assets/asset-cost-events.php'; ?>
<button class="btn btn-outline theme-secondary secondary"><?= e($periodCostText('Anlagenbewertung prüfen')) ?></button></form></details>
<script defer src="asset-guided.js?v=9"></script>
