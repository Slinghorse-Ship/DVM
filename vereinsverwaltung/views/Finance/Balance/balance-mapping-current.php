<?php declare(strict_types=1);
$annualMappingDisplay=new \Kulturhus\Verein\BalanceDisplayText($managerLanguageMessages??[]);
try{if(input($_GET,'annual_mapping_review')!=='current')throw new DomainException('Aktuellen Zuordnungsstand vollständig auswählen.',422);$annualMappingCurrent=(new \Kulturhus\Verein\BalanceMappingCurrentRead($db,(string)$_SESSION['actor']))->get(input($_GET,'balance_year'));}
catch(DomainException $failure){if(!headers_sent())http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);echo '<p role="alert">'.e($annualMappingText($failure->getMessage())).'</p>';return;}
?>
<link rel="stylesheet" href="tax-guided.css?v=3"><div class="content" data-tax-guided data-annual-mapping-current><div class="page-heading"><h1><?= e($annualMappingText('Aktueller Zuordnungsstand')) ?></h1></div>
<?php $annualMappingShowCurrentLink=false;require __DIR__.'/balance-mapping-current-summary.php'; ?>
<?php if($annualMappingPreparation!==null&&$annualMappingPreparation['current']): ?><section class="card"><h2><?= e($annualMappingText('Gespeicherte Auswahl für diesen Buchstand')) ?></h2>
<?php if($annualMappingPreparation['mapping']===[]): ?><p><?= e($annualMappingText('Für diesen Buchstand sind keine aktiven Konten zuzuordnen. Die ausdrücklich gespeicherte leere Auswahl ist keine Freigabe einer Steuerabgabe.')) ?></p><?php endif ?>
<?php $annualMappingTargets=\Kulturhus\Verein\BalanceTaxFiling::targets();foreach($annualMappingPreparation['mapping']as$id=>$target):$account=$annualMappingReport['calculation']['accounts'][$id]??null;$choices=$account===null?[]:\Kulturhus\Verein\BalanceTaxMapping::choices($annualMappingCurrent['year'],$account,$annualMappingTargets); ?><article><h3><?= e($account===null?(string)$id:$annualMappingDisplay->account($account)) ?></h3><p><?= e($choices[$target]??$target) ?></p><?php if(!isset($choices[$target])): ?><p><?= e($annualMappingText('Die gespeicherte Auswahl mit den aktuellen Zuordnungsregeln gesondert prüfen.')) ?></p><?php endif ?></article><?php endforeach ?></section><?php endif ?>
</div>
