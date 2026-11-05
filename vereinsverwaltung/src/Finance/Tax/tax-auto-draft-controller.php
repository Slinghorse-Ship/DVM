<?php declare(strict_types=1);
$taxDraftHandled=false;$taxDraftState=null;$taxDraftError='';$taxDraftOutcome=null;$taxDraftRead=false;
try{
 $taxDraftRead=($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&array_key_exists('tax_draft_outcome',$_GET);$taxDraftPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\TaxAutoDraftForm::requested($_POST);if(!$taxDraftRead&&!$taxDraftPost)return;$taxDraftHandled=$taxDraftPost;
 if(!$workspaceAllowed)throw new DomainException('Aktuelle Finanzberechtigung für Steuerentwürfe erforderlich.',403);
 $query=$_GET;unset($query['tax_draft_outcome']);$draftYear=\Kulturhus\Verein\Web\TaxAutoDraftForm::source($query);
 if($taxDraftRead){$key=$_GET['tax_draft_outcome'];if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Steuerauftragskennung vollständig prüfen.',422);$taxDraftOutcome=['key'=>$key,'original'=>(new \Kulturhus\Verein\TaxAutoRequests($db,(string)$_SESSION['actor']))->receipt($key)];if($taxDraftOutcome['original']!==null&&$taxDraftOutcome['original']['year']!==$draftYear){$taxDraftOutcome=null;throw new DomainException('Ursprünglichen Steuerentwurf vollständig prüfen.',409);}return;}
 $taxDraftState=\Kulturhus\Verein\Web\TaxAutoDraftForm::handle($db,(string)$_SESSION['actor'],$_GET,$_POST,(string)$_SESSION['csrf'],$_SESSION);
 if(isset($taxDraftState['stored']))redirectTo(['view'=>'finance','tab'=>'tax','tax_auto'=>'1','tax_year'=>(string)$draftYear,'tax_draft_outcome'=>$taxDraftState['stored']['key']]);
 if(isset($taxDraftState['filing']))redirectTo(['view'=>'finance','tab'=>'tax','tax_filings'=>'1','filing'=>$taxDraftState['filing']]);
 if(isset($taxDraftState['package'])){$taxAutoPackageDisplay=true;$taxAutoPackageState=\Kulturhus\Verein\Web\TaxAutoPackageForm::handle($db,(string)$_SESSION['actor'],$_GET,$taxDraftState['package'],(string)$_SESSION['csrf'],$_SESSION);if(isset($taxAutoPackageState['existing']))$taxAutoPackageOutcome=['key'=>$taxAutoPackageState['key'],'saved'=>$taxAutoPackageState['existing']];}
}catch(DomainException$e){http_response_code(in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);$taxDraftError=$e->getMessage();}
catch(Throwable){http_response_code(503);$taxDraftError='Speicherantwort unklar. Zuerst den Steuerentwurf mit derselben Kennung lesen; keinen zweiten Stand beginnen.';}
if($taxDraftError!==''&&($view!=='finance'||input($_GET,'tab')!=='tax'||input($_GET,'tax_auto')!=='1')){$draftMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];$error=\Kulturhus\Native\Language\TaxAutoDraftMessages::text($taxDraftError,$draftMessages);}
