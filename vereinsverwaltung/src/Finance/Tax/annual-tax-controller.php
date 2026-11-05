<?php declare(strict_types=1);
$annualTaxHandled=false;$annualTaxState=null;$annualTaxError='';$annualTaxOutcome=null;$annualTaxReadOnlyError=false;
try{
 $annualGet=($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&array_key_exists('annual_tax_outcome',$_GET);$annualPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\AnnualTaxForm::requested($_POST);
 if(!$annualGet&&!$annualPost)return;$annualTaxHandled=$annualPost;
 if(!$workspaceAllowed)throw new DomainException('Finanzberechtigung für Jahresunterlagen erforderlich.',403);
 \Kulturhus\Verein\Web\AnnualTaxForm::source($_GET);$annualService=new \Kulturhus\Verein\TaxPreparation($db,(string)$_SESSION['actor']);
 if($annualGet){if(!is_string($_GET['annual_tax_outcome'])||!preg_match('/\A[a-f0-9]{32}\z/D',$_GET['annual_tax_outcome']))throw new DomainException('Ursprüngliche Kennung der Jahresunterlagen vollständig prüfen.',422);$annualTaxOutcome=['key'=>$_GET['annual_tax_outcome'],'original'=>$annualService->savedRequest($_GET['annual_tax_outcome'])];return;}
 $annualTaxState=\Kulturhus\Verein\Web\AnnualTaxForm::handle($db,(string)$_SESSION['actor'],$_GET,$_POST,(string)$_SESSION['csrf'],$_SESSION);
 if(isset($annualTaxState['preview']))$taxPreview=$annualTaxState['preview'];
 if(isset($annualTaxState['stored']))redirectTo(['view'=>'finance','tab'=>'tax','tax_workpapers'=>'1','annual_tax_outcome'=>$annualTaxState['key']]);
}catch(DomainException$e){http_response_code(in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);$annualTaxError=$e->getMessage();}
catch(Throwable){http_response_code(503);$annualTaxError='Speicherantwort unklar. Zuerst den gespeicherten Jahresausgang mit derselben Kennung lesen; keinen zweiten Stand beginnen.';}
if($annualTaxError!==''&&($annualGet??false))$annualTaxReadOnlyError=true;
if($annualTaxError!==''&&($view!=='finance'||input($_GET,'tab')!=='tax'||input($_GET,'tax_workpapers')!=='1')){$annualMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];$error=\Kulturhus\Native\Language\AnnualTaxMessages::text($annualTaxError,$annualMessages);}
