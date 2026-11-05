<?php declare(strict_types=1);
// Explicit public DATEV source only. No database, network or tax transmission.
require __DIR__.'/../src/Catalog/CatalogImportProcess.php';
use Kulturhus\Verein\CatalogImportProcess;
$source=json_decode(file_get_contents(__DIR__.'/../data/skr42-2026.json'),true,512,JSON_THROW_ON_ERROR);
$path=$argv[1]??getenv('VEREIN_CATALOG_TEST_PDF')?:'';
if(!is_file($path)||filesize($path)>8388608||hash_file('sha256',$path)!==$source['sha256'])throw new RuntimeException('Pass the pinned public 2026 DATEV original, not a club document.');
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
$before=glob(sys_get_temp_dir().'/kulturhus-catalog-*');
$request=['operation'=>'compare','from_year'=>2025,'year'=>2026,'source'=>$source['source'],'pdf_sha256'=>$source['sha256'],'appendix_pages'=>2];
$result=CatalogImportProcess::run($request,['original.pdf'=>file_get_contents($path)]);
check($result['result']['state']==='review_required','actual PHP/Python/PDF extraction chain completes');
$catalog=json_decode($result['files']['catalog.json'],true,512,JSON_THROW_ON_ERROR);
check($catalog['year']===2026&&$catalog['sha256']===$source['sha256'],'actual PDF year and original bytes retained');
$rows=array_column($catalog['accounts'],null,'code');
check($rows['06300']['name']==='Betriebsausstattung'&&$rows['06300']['page']===1,'first-page equipment survives extraction');
check($rows['68350']['name']==='Mieten für Einrichtungen (bewegliche Wirtschaftsgüter)','right-column long description survives extraction');
check(count($catalog['accounts'])===count($source['accounts']),'no lost source accounts');
check(count($catalog['source_notes']??[])===2,'source notes included');
$plan=json_decode($result['files']['comparison.json'],true,512,JSON_THROW_ON_ERROR);
$review=json_decode($result['files']['review.json'],false,512,JSON_THROW_ON_ERROR);
$review->reviewer='Synthetic process test';$review->note='Unresolved changes remain blocked';$review->source_and_notes_reviewed=true;$review->taxonomy_reviewed=true;
foreach($review->decisions as $decision)$decision->reason='Synthetic deferred decision, no tax authorization';
$files=['catalog.json'=>$result['files']['catalog.json'],'comparison.json'=>$result['files']['comparison.json'],'submitted-review.json'=>json_encode($review,JSON_THROW_ON_ERROR)];
$candidate=CatalogImportProcess::run(['operation'=>'approve','from_year'=>2025,'year'=>2026],$files);
check($candidate['result']['state']==='incomplete_candidate'&&$candidate['result']['production_authorized']===false,'real source package never silently activates incomplete rules');
check(str_starts_with($candidate['files']['package.zip'],'PK'),'review package delivered by PHP subprocess');
$rejected=false;
// Use the actual pinned 2026 PDF as a negative 2027 input. No synthetic file
// can establish that an unpublished official year has passed PDF extraction.
try{CatalogImportProcess::run(array_replace($request,['from_year'=>2026,'year'=>2027]),['original.pdf'=>file_get_contents($path)]);}
catch(\DomainException){$rejected=true;}
check($rejected,'actual 2026 PDF cannot become a 2027 catalog by changing the request');
check(glob(sys_get_temp_dir().'/kulturhus-catalog-*')===$before,'private subprocess workspaces removed');
echo "$n complete public-PDF process checks passed; no database or transmission\n";
