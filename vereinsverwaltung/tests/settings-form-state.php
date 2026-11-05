<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/Web/View.php';
require_once __DIR__.'/../src/Auth/WorkspaceAccess.php';
use Kulturhus\Verein\{Database,Settings,SettingsSchema,WorkspaceAccess,Finance,Registry};
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(!(error_reporting()&$severity))return false;throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function rejected(callable $action):void{try{$action();}catch(DomainException){return;}throw new RuntimeException('Invalid save succeeded');}
function htmlDoc(string $html):DOMXPath{$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($dom);}
function value(DOMXPath $xp,string $name):string{return $xp->evaluate('string(//*[@name="'.$name.'"]/@value)');}
function checked(DOMXPath $xp,string $name):bool{return $xp->query('//*[@name="'.$name.'"][@checked]')->length===1;}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('test','Synthetic club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('manager','test','members',1); INSERT INTO actor_capabilities VALUES('manager','local_finance')");
$_SESSION=['actor'=>'manager','csrf'=>'synthetic-only'];$settings=new Settings($db,'manager');$workspaceAccess=new WorkspaceAccess($db,'manager');$finance=new Finance($db,'manager');$registry=new Registry($db,'manager');$settingsPreview=null;$canWrite=true;$contacts=[];
$templates=SettingsSchema::defaults('texts_invoices');$templates['invoice']['attach_pdf']=true;$settings->save('texts_invoices',$templates,0);
$posted=$templates;$posted['invoice']['message']='Neue Nachricht {{UNBEKANNTE_VARIABLE}}';$posted['invoice']['subject']='Neuer Betreff';unset($posted['invoice']['attach_pdf']);$posted['reminder']['attach_pdf']='1';
rejected(fn()=>$settings->save('texts_invoices',$posted,1));
$_GET=['view'=>'settings','tab'=>'texts','group'=>'invoices'];$_POST=['action'=>'settings-save','section'=>'texts_invoices','data'=>$posted,'version'=>'1'];$tab='texts';ob_start();require __DIR__.'/../views/Settings/settings.php';$html=ob_get_clean();$xp=htmlDoc($html);
check(value($xp,'data[invoice][subject]')==='Neuer Betreff','invalid variable keeps new nested subject');
check(str_contains($xp->evaluate('string(//*[@name="data[invoice][message]"])'),'{{UNBEKANNTE_VARIABLE}}'),'invalid variable remains visible for correction');
check(!checked($xp,'data[invoice][attach_pdf]')&&checked($xp,'data[reminder][attach_pdf]'),'error form preserves both unchecked and checked attachment switches');
check($settings->section('texts_invoices')['data']===$templates&&$settings->section('texts_invoices')['version']===1,'error rerender does not persist rejected data');
$numbers=SettingsSchema::defaults('numbers');$settings->save('numbers',$numbers,0);$numberPost=$numbers;$numberPost['invoice']['prefix']='NEU-';$numberPost['invoice']['width']='0';$numberPost['invoice']['next']='42';
rejected(fn()=>$settings->save('numbers',$numberPost,1));$sd=SettingsSchema::formValues('numbers',$numberPost,$numbers);$sv=1;ob_start();require __DIR__.'/../views/Settings/settings-numbers.php';$html=ob_get_clean();$xp=htmlDoc($html);
check(value($xp,'data[invoice][prefix]')==='NEU-'&&value($xp,'data[invoice][width]')==='0'&&value($xp,'data[invoice][next]')==='42','invalid numbering retains all submitted fields');
check(str_contains($html,'Vorschau nach Korrektur der Eingaben'),'invalid numbering does not display a misleading valid example');
check($settings->section('numbers')['data']===$numbers,'numbering counter unchanged after failed save');
$fields=['rows'=>[['name'=>'Prüfung','type'=>'checkbox','options'=>'','required'=>true]]];$settings->save('fields',$fields,0);
$fieldPost=['rows'=>[['name'=>'Neue Bezeichnung','type'=>'text','options'=>''],['name'=>'Neue Bezeichnung','type'=>'date','required'=>'1']]];rejected(fn()=>$settings->save('fields',$fieldPost,1));$sd=SettingsSchema::formValues('fields',$fieldPost,$fields);$section='fields';$tab='';$_POST=[];ob_start();require __DIR__.'/../views/Settings/settings-profile.php';$html=ob_get_clean();$xp=htmlDoc($html);
check(value($xp,'data[rows][0][name]')==='Neue Bezeichnung'&&value($xp,'data[rows][1][name]')==='Neue Bezeichnung','duplicate structured rows remain visible for correction');
check(!checked($xp,'data[rows][0][required]')&&checked($xp,'data[rows][1][required]'),'nested required switches retain submitted state');
$tier=Settings::catalogDefaults('service');$tier['name']='Staffel Test';$tier['pricing']='age';$tier['tiers']=[['from'=>0,'amount'=>'15']];$catalogId=$settings->saveCatalog('',0,'service',$tier);$tier['tiers']=[];rejected(fn()=>$settings->saveCatalog($catalogId,1,'service',$tier));unset($tier['tiers']);$_GET=['view'=>'settings','tab'=>'services','edit'=>$catalogId];$_POST=['action'=>'settings-catalog','data'=>$tier,'version'=>'1','catalog_id'=>$catalogId];$tab='services';ob_start();require __DIR__.'/../views/Settings/settings-catalog.php';$html=ob_get_clean();$xp=htmlDoc($html);
check($xp->query('//*[@data-settings-rows]//*[@name="data[tiers][0][from]"]')->length===0,'removing all tiers stays empty after validation error');
check(count(array_column($settings->catalog(),null,'id')[$catalogId]['data']['tiers'])===1,'failed tier removal leaves stored rate unchanged');
$canFinance=true;$taxCode='40000';$taxOld=$finance->taxAccounts()[$taxCode];
rejected(fn()=>$finance->saveTaxAccount($taxCode,'',false,(int)$taxOld['version']));
$_GET=['q'=>$taxCode,'active'=>''];$_POST=['action'=>'save-tax-account','code'=>$taxCode,'version'=>(string)$taxOld['version'],'name'=>''];ob_start();require __DIR__.'/../views/Finance/Tax/tax-accounts.php';$html=ob_get_clean();$xp=htmlDoc($html);
$editForm='//form[input[@name="code"][@value="'.$taxCode.'"]][not(input[@name="create_only"])]';
check($xp->query($editForm.'/ancestor::details[@open]')->length===1&&$xp->query($editForm.'//input[@name="active"][@checked]')->length===0,'failed tax-account edit stays open and does not restore active flag');
check($xp->evaluate('string('.$editForm.'//input[@name="name"]/@value)')==='','invalid empty tax-account label remains available for correction');
check($xp->evaluate('string(//form[input[@name="create_only"]]//input[@name="code"]/@value)')==='','failed edit does not populate unrelated new-account form');
$_POST=['action'=>'save-tax-account','create_only'=>'1','code'=>$taxCode,'version'=>'0','name'=>'Neuer Versuch'];rejected(fn()=>$finance->saveTaxAccount($taxCode,'Neuer Versuch',false,0,true));ob_start();require __DIR__.'/../views/Finance/Tax/tax-accounts.php';$html=ob_get_clean();$xp=htmlDoc($html);
check($xp->query('//form[input[@name="create_only"]]//input[@name="active"][@checked]')->length===0,'failed new tax-account form retains unchecked active flag');
$finance->saveTaxAccount($taxCode,'Gespeichert inaktiv',false,(int)$taxOld['version']);$_POST=[];ob_start();require __DIR__.'/../views/Finance/Tax/tax-accounts.php';$html=ob_get_clean();$xp=htmlDoc($html);
check($xp->query($editForm.'//input[@name="active"][@checked]')->length===0&&$xp->evaluate('string('.$editForm.'//input[@name="name"]/@value)')==='Gespeichert inaktiv','successful tax-account reload displays stored inactive state');
$_GET=['q'=>'06300','group'=>''];$_POST=[];ob_start();require __DIR__.'/../views/Finance/Accounting/booking-keys.php';$bookingHtml=ob_get_clean();
check(str_contains($bookingHtml,'06300 ist ein Steuerkonto, kein Buchungsschlüssel.')&&str_contains($bookingHtml,'Steuerkonto 06300 öffnen'),'account search explains 06300 and links to the tax-account list');
echo "$checks settings form state checks passed\n";
