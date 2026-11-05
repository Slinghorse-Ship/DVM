<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,TaxClubData,TaxFilings,TaxFormSchema};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','member',1),('member','a','member',0),('other','b','treasurer',0)");
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
check(TaxClubData::profile([],2025)===[],'missing settings never confirm accounting or VAT');
check(TaxClubData::number(['number'=>'198/113/10010'])==='','regional tax number not guessed');
check(TaxClubData::number(['number'=>'9198 0113 10010'])==='9198011310010','formatted thirteen digit tax number normalized');
check(TaxClubData::profile(['small_business'=>false,'input_tax_eligibility'=>'no'],2025)===[],'no input tax deduction does not imply exempt VAT or small business');
$club=['name'=>'Synthetic Verein e.V.','street'=>'Teststrasse 1','postcode'=>'12345','city'=>'Teststadt','registered'=>true];
$tax=['number'=>'9198011310010','accounting_basis'=>'euer','small_business'=>true,'notice_type'=>'exemption','exemption_date'=>'2024-01-01','receipt_authority_reviewed'=>true,'notice_document_id'=>'synthetic'];
$save=static function(string $clubId,string $section,array $data)use($db):void{$q=$db->prepare('INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,1,?)');$q->execute([$clubId,$section,json_encode($data,JSON_THROW_ON_ERROR),gmdate('c')]);};
$save('a','club',$club);$save('a','tax',$tax);
$f=new TaxFilings($db,'board');$answers=$f->profile(2025)['scope']['answers'];
check($answers['accounting']==='euer'&&$answers['vat']==='small'&&$answers['nonprofit']==='yes','stored explicit club tax facts prefill new yearly profile');
check($answers['employees']==='unknown'&&$answers['notices_checked']==='unknown'&&$answers['kst_due']==='unknown','annual confirmations and employment remain unknown');
$answers['accounting']='balance';$answers['nonprofit']='no';$f->saveProfile(2025,$answers,0);
check($f->profile(2025)['scope']['answers']===$answers,'saved yearly decisions are never overwritten by current settings');
check((new TaxFilings($db,'other'))->profile(2025)['scope']['answers']['accounting']==='unknown','other club settings isolated');
try{TaxClubData::stored($db,'member');throw new RuntimeException('member access accepted');}catch(DomainException){check(true,'member cannot read tax settings');}
check(!isset(TaxClubData::profile(array_replace($tax,['notice_type'=>'determination']),2025)['nonprofit']),'60a determination does not certify actual management');
check(!isset(TaxClubData::profile(array_replace($tax,['exemption_date'=>'2026-01-01']),2025)['nonprofit']),'later notice not applied to earlier year automatically');
$node=static fn($name,$type)=>['name'=>$name,'type'=>$type,'min'=>0,'max'=>1,'label'=>$name];
$schema=new TaxFormSchema(['family'=>'kst','root_type'=>'Root','types'=>['Root'=>['children'=>[$node('Vorsatz','Vorsatz'),$node('KSt1','Main')]],'Vorsatz'=>['simple'=>false,'children'=>array_map(static fn($name)=>$node($name,'Text'),['StNr','Zeitraum','AbsName','AbsStr','AbsPlz','AbsOrt'])],'Main'=>['simple'=>false,'children'=>[$node('Allgemein','General')]],'General'=>['simple'=>false,'children'=>[$node('E8000101','Text')]],'Text'=>['simple'=>true,'maxLength'=>100,'base'=>'string']]]);
$form=TaxClubData::form($schema,TaxClubData::stored($db,'board'),2025);
check($form['fields'][TaxFormSchema::key('Vorsatz/StNr')]===$tax['number'],'exact schema tax number filled');
check($form['fields'][TaxFormSchema::key('KSt1/Allgemein/E8000101')]===$club['name'],'exact KSt club name filled');
check($form['header']['office']==='9198'&&str_contains($form['header']['supplier'],'Teststadt'),'transport sender and office reused');
check($schema->normalize($form['fields'])===$form['fields']&&$form['attachments']===[],'only schema allowed values, no automatic document attachment');
check(!isset($form['fields'][TaxFormSchema::key('KSt1/Steuerpflicht/Rechtsform/E8001105')]),'missing schema fields are not invented');
$catalog=getenv('VEREIN_ERIC_UI_CATALOG');
if($catalog){$official=json_decode((string)file_get_contents($catalog),true,64,JSON_THROW_ON_ERROR);$euer=TaxClubData::form(new TaxFormSchema($official['profiles']['EUER_2025']),['club'=>$club,'tax'=>$tax],2025);foreach(['E6000016'=>'name','E6000023'=>'street','E6000024'=>'postcode','E6000025'=>'city'] as $field=>$setting)check($euer['fields'][TaxFormSchema::key('EUER/Allg/'.$field)]===$club[$setting],'official EÜR schema reuses '.$setting);}
echo "$n tax settings reuse checks passed; synthetic MySQL only\n";
