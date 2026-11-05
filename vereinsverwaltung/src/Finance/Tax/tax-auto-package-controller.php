<?php declare(strict_types=1);
$taxAutoPackageHandled=false;$taxAutoPackageDisplay=false;$taxAutoPackageState=null;$taxAutoPackageError='';$taxAutoPackageOutcome=null;
try{
 $packageGet=($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&array_key_exists('tax_auto_package_outcome',$_GET);$packagePost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\TaxAutoPackageForm::requested($_POST);
 if(!$packageGet&&!$packagePost)return;$taxAutoPackageDisplay=true;$taxAutoPackageHandled=$packagePost;
 if(!$workspaceAllowed)throw new DomainException('Finanzberechtigung für Jahresunterlagen erforderlich.',403);
 \Kulturhus\Verein\Web\TaxAutoPackageForm::source($_GET);$packageService=new \Kulturhus\Verein\TaxAutoPreparation($db,(string)$_SESSION['actor']);
 if($packageGet){$key=$_GET['tax_auto_package_outcome'];if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Jahrespaketkennung vollständig prüfen.',422);$taxAutoPackageOutcome=['key'=>$key,'saved'=>$packageService->packageRequest($key)];return;}
 $taxAutoPackageState=\Kulturhus\Verein\Web\TaxAutoPackageForm::handle($db,(string)$_SESSION['actor'],$_GET,$_POST,(string)$_SESSION['csrf'],$_SESSION);
 if(isset($taxAutoPackageState['existing'])){$taxAutoPackageOutcome=['key'=>$taxAutoPackageState['key'],'saved'=>$taxAutoPackageState['existing']];return;}
 if(isset($taxAutoPackageState['stored']))redirectTo(['view'=>'finance','tab'=>'tax','tax_auto'=>'1','tax_year'=>$taxAutoPackageState['fields']['tax_year'],'tax_auto_package_outcome'=>$taxAutoPackageState['key']]);
}catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$taxAutoPackageError=$failure->getMessage();}
catch(Throwable){http_response_code(503);$taxAutoPackageError='Speicherantwort des Jahrespakets unklar. Zuerst den Ausgang mit derselben Kennung lesen; keinen zweiten Auftrag beginnen.';}
if($taxAutoPackageError!==''&&($view!=='finance'||input($_GET,'tab')!=='tax'||input($_GET,'tax_auto')!=='1')){$packageUiMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];$error=\Kulturhus\Native\Language\TaxAutoPackageMessages::text($taxAutoPackageError,$packageUiMessages);}
