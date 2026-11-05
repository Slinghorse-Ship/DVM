<?php declare(strict_types=1);
$taxLocalHandled=false;$taxLocalRead=false;$taxLocalState=null;$taxLocalError='';$taxLocalOutcome=null;
try{
 $taxLocalRead=($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&array_key_exists('tax_local_outcome',$_GET);$taxLocalPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\TaxFilingForm::requested($_POST);if(!$taxLocalRead&&!$taxLocalPost)return;$taxLocalHandled=$taxLocalPost;
 if(!$workspaceAllowed)throw new DomainException('Aktuelle Finanzberechtigung für Steuererklärungen erforderlich.',403);
 $q=$_GET;unset($q['tax_local_outcome'],$q['tax_local_pdf']);$source=\Kulturhus\Verein\Web\TaxFilingForm::source($q);$localYear=$source['year'];
 if($taxLocalRead){$key=$_GET['tax_local_outcome'];if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Erklärungsauftragskennung vollständig prüfen.',422);$service=new \Kulturhus\Verein\TaxFilingCommands($db,(string)$_SESSION['actor']);$original=$service->receipt($key);if($original!==null&&$original['year']!==$localYear)throw new DomainException('Ursprüngliches Erklärungsjahr und Formular vollständig prüfen.',409);$taxLocalOutcome=['key'=>$key,'original'=>$original];
  if(array_key_exists('tax_local_pdf',$_GET)){$which=$_GET['tax_local_pdf'];if(!is_string($which)||!in_array($which,['before','original'],true))throw new DomainException('Ursprüngliche PDF-Vorschau vollständig prüfen.',422);$file=$service->file($key,$which==='before');header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="'.$file['name'].'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");echo$file['bytes'];exit;}return;
 }
 $taxLocalState=\Kulturhus\Verein\Web\TaxFilingForm::handle($db,(string)$_SESSION['actor'],$_GET,$_POST,(string)$_SESSION['csrf'],$_SESSION);
 if(isset($taxLocalState['navigate']))redirectTo($taxLocalState['navigate']);
 if(isset($taxLocalState['stored']))redirectTo(['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>(string)$localYear,'tax_local_outcome'=>$taxLocalState['stored']['key']]);
}catch(DomainException$e){http_response_code(in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);$taxLocalError=$e->getMessage();}
catch(Throwable){http_response_code(503);$taxLocalError='Speicherantwort unklar. Zuerst die Erklärung mit derselben Kennung lesen; keinen zweiten Auftrag beginnen.';}
if($taxLocalError!==''&&($view!=='finance'||input($_GET,'tab')!=='tax'||input($_GET,'tax_filings')!=='1')){$localMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];$error=\Kulturhus\Native\Language\TaxFilingMessages::text($taxLocalError,$localMessages);}
