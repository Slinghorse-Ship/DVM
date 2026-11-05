<?php declare(strict_types=1);
require __DIR__.'/../src/Finance/Tax/TaxCatalog.php';
use Kulturhus\Verein\TaxCatalog;
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
check(TaxCatalog::YEARS===[2025,2026],'only verified source years advertised');
$a=TaxCatalog::accounts(2025);$b=TaxCatalog::accounts(2026);$source=TaxCatalog::sourceData('skr42-2025.json');
check(count($a)===2714&&$source['source_entries']===1585,'2025 full-height table extraction includes bottom rows');
foreach(['43340'=>'Erlöse 7 % USt','63200'=>'Heizung','63250'=>'Gas, Strom, Wasser','65400'=>'Fahrzeug-Reparaturen','65500'=>'Garagenmiete','65600'=>'Mietleasing Kfz'] as $code=>$name)
    check($a[$code]['name']===$name&&!$a[$code]['blocked'],'2025 visually verified bottom row '.$code);
check($a['65400']['page']===21&&$a['63250']['page']===20&&$a['43340']['page']===12,'restored rows retain source page');
check($a['14591']['blocked']&&$a['43320']['blocked']&&$a['55640']['blocked']&&$a['47370']['blocked'],'restored reserved accounts and ambiguous ranges remain blocked');
check($a['68350']['name']==='Mieten für Einrichtungen (bewegliche Wirtschaftsgüter)'&&$b['68350']['name']===$a['68350']['name'],'full description is not cut at right column edge');
check($source['sha256']==='dcf8080446f4fc0ddb1d67409fdabc3689dab598ac669d2330322f895402e69d','2025 source bytes pinned');
check($source['download_source']==='https://tsg-sulzbach.de/wp-content/uploads/fix/DATEV-SKR42.pdf','mirror origin recorded separately from DATEV source');
check(count(array_unique(array_column($a,'year')))===1&&reset($a)['year']===2025,'all historical account metadata uses actual year');
check(count($b)===2727&&reset($b)['year']===2026&&TaxCatalog::accounts(2025)===$a,'per-year caches do not leak current catalog');
check($a['06300']['name']==='Betriebsausstattung'&&$a['06300']['page']===1&&$a['06300']['active']===1,'visually checked inventory account retains first-page source');
check($a['01430']['blocked']&&$a['47340']['blocked']&&TaxCatalog::reserved('01601',2025),'conflicts and reserved 2025 ranges not selectable');
check((bool)array_diff_key($b,$a),'2025 table is not a relabelled 2026 table');
check(count(TaxCatalog::bookingKeys(2025))===289&&count(TaxCatalog::supportedKeys(2025))===8&&count(TaxCatalog::supportedKeys(2026))===8&&TaxCatalog::sourceData('datev-keys-2025.json')['sha256']==='b6a71ea6156c55d801b404d182deb89efe36240883afe9ab3f7571611a382fd7','separately verified 2025 BU source and limited domestic rules');
foreach([0,2019,2022,2024,2027,9999] as $year)check(!TaxCatalog::hasYear($year)&&TaxCatalog::accounts($year)===[]&&TaxCatalog::supportedKeys($year)===[]&&TaxCatalog::reserved('06300',$year),'unavailable source year fails closed '.$year);
foreach(['2019-06-13'=>2019,'2022-04-04'=>2022,'2024-02-29'=>2024,'2025-07-22'=>2025,'2026-09-08'=>2026] as $date=>$year)check(TaxCatalog::documentYear($date)===$year,'original document year read exactly '.$date);
foreach(['','2025-02-29','2026-02-30','2022-4-04','04.04.2022',"2022-04-04\n",'0000-01-01'] as $date)check(TaxCatalog::documentYear($date)===0,'noncanonical or invalid date rejected '.json_encode($date));
foreach(['skr42-2024.json','../data/skr42-2026.json','datev-keys-2024.json'] as $file){try{TaxCatalog::sourceData($file);throw new RuntimeException('unverified file accepted');}catch(DomainException){check(true,'source whitelist '.$file);}}
echo "$n annual catalog checks passed\n";
