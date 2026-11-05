<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{TaxAutoMapping2025 as M,TaxFilingScope,TaxFormSchema};
$d=require __DIR__.'/fixtures/tax-auto-data.php';$s=$d['snapshot'];$a=$d['details'];$scope=TaxFilingScope::build(2025,$d['answers']);
$map=M::build($s,$a,$scope);
if(in_array('--json',$argv,true)){echo json_encode($map,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;}
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$value=fn($map,$path)=>$map['fields'][TaxFormSchema::key($path)]??null;
check($map['ready'],'complete synthetic EÜR charity case ready');
check($value($map,'Gem/Gesamteinnahmen/E8400904')==='1000','one aggregate rounded, not individual payments');
check($map['rounding'][2025]['cents']===100049,'source cents retained');
check($value($map,'KSt1/Steuerpflicht/Rechtsform/E8001105')==='520','registered association legal form');
check($value($map,'Gem/Allgemein/Zweck[0]/E8401004')==='52','cultural purpose without deductible dues');
check($map['header']['office']==='9198','office from canonical synthetic tax number');
foreach(['fully_exempt','domestic','source_reviewed','rounding','statute_date','purpose','tax_number'] as $key){$copy=$a;$copy[$key]='';check(!M::build($s,$copy,$scope)['ready'],'unknown '.$key.' blocks');}
$copy=$a;$copy['tax_number']='123/456/78901';check(!M::build($s,$copy,$scope)['ready'],'regional tax number never guessed');
$copy=$a;$copy['statute_date']='2026-01-01';check(!M::build($s,$copy,$scope)['ready'],'future statute blocked');
$copy=$s;$copy['accounting_basis']='balance';check(!M::build($copy,$a,$scope)['ready'],'balance never treated as EÜR');
$copy=$s;$copy['tax']['data']['fiscal_start']='2025-07-01';check(!M::build($copy,$a,$scope)['ready'],'noncalendar year blocked');
$copy=$s;$copy['to']=2026;$copy['reports'][2026]=$copy['reports'][2025];$copy['year_notes'][2026]=$copy['year_notes'][2025];check(!M::build($copy,$a,$scope)['ready'],'no 2025 mapping reused for 2026');
$copy=$s;$copy['reports'][2025]['annual']['spheres'][2]['income_cents']=4500000;$sc=$scope;$sc['answers']['economic']='yes';$copy['declaration']['economic_activities']=['answer'=>'yes','note'=>'Synthetischer Zweckbetrieb'];
check(M::build($copy,$a,$sc)['ready'],'exact 45000 not above threshold');$copy['reports'][2025]['annual']['spheres'][2]['income_cents']++;
check(!M::build($copy,$a,$sc)['ready'],'45000 plus one cent cannot be rounded below threshold');
foreach(['member_benefits','reserves','asset_donations','funds_forwarded'] as $key){$copy=$s;$copy['declaration'][$key]=['answer'=>'yes','note'=>'Sonderfall'];check(!M::build($copy,$a,$scope)['ready'],'special '.$key.' needs individual mapping');}
check(!M::build($s,$a,TaxFilingScope::build(2025,[]))['ready'],'incomplete tax profile blocked');
$copy=$s;$copy['year_notes'][2025]['fixed_assets_book_value_cents']=null;check(!M::build($copy,$a,$scope)['ready'],'unknown asset value not zero');
check(!M::build($s,$a,$scope,['Ungeklärte Quelle'])['ready'],'source blocker propagates');
$copy=$a;$copy['statute_filed']='no';check($value(M::build($s,$copy,$scope),'KSt1/E8000012')==='X','statute evidence pending marked');
check(M::suggest(['name'=>'Kulturclub'],[])['purpose']==='','club name does not imply tax purpose');
check(M::suggest([],$d['tax'])['purpose']==='52','exact stored purpose reused');
check($s===$d['snapshot'],'mapping never mutates sources');
echo "$n checks passed\n";
