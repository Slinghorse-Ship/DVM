<?php declare(strict_types=1);
$balanceLocalHandled=false;$balanceLocalRead=false;$balanceLocalState=null;$balanceLocalError='';$balanceLocalOutcome=null;
try{
 $balanceLocalRead=($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&array_key_exists('balance_local_outcome',$_GET);
 $balanceLocalPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\BalanceFilingForm::requested($_POST);
 if(!$balanceLocalRead&&!$balanceLocalPost)return;$balanceLocalHandled=$balanceLocalPost;
 if(!$workspaceAllowed)throw new DomainException('Aktuelle Finanzberechtigung für die E-Bilanz erforderlich.',403);
 if($balanceLocalRead){
  $q=$_GET;unset($q['balance_local_outcome'],$q['balance_local_xml']);\Kulturhus\Verein\Web\BalanceFilingForm::source($q);
  $key=$_GET['balance_local_outcome'];if(!is_string($key))throw new DomainException('Ursprüngliche E-Bilanz-Auftragskennung vollständig prüfen.',422);
  $original=(new \Kulturhus\Verein\BalanceFilingCommands($db,(string)$_SESSION['actor']))->receipt($key);
  if($original!==null&&($q['tax_year']??null)!==(string)$original['year'])throw new DomainException('Ursprüngliches E-Bilanz-Jahr vollständig prüfen.',409);
  $balanceLocalOutcome=['key'=>$key,'original'=>$original];
  if(array_key_exists('balance_local_xml',$_GET)){
   $which=$_GET['balance_local_xml'];if(!is_string($which)||!in_array($which,['before','original'],true)||$original===null)throw new DomainException('Ursprüngliche E-Bilanz-XML nicht gefunden.',404);
   $record=$which==='before'?$original['before']:$original['result'];$bytes=$record['xml_data']??null;
   if(!is_string($bytes)||($record['xml_sha256']??null)!==hash('sha256',$bytes))throw new DomainException('Ursprüngliche E-Bilanz-XML nicht gefunden.',404);
   header('Content-Type: application/xml; charset=utf-8');header('Content-Disposition: attachment; filename="E-Bilanz-'.$which.'.xml"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");echo $bytes;exit;
  }return;
 }
 $balanceLocalState=\Kulturhus\Verein\Web\BalanceFilingForm::handle($db,(string)$_SESSION['actor'],$_GET,$_POST,(string)$_SESSION['csrf'],$_SESSION);
 if(isset($balanceLocalState['navigate']))redirectTo($balanceLocalState['navigate']);
 if(isset($balanceLocalState['stored']))redirectTo(['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>(string)$balanceLocalState['stored']['year'],'balance_local_outcome'=>$balanceLocalState['stored']['key']]);
}catch(DomainException $e){http_response_code(in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);$balanceLocalError=$e->getMessage();}
catch(Throwable){http_response_code(503);$balanceLocalError='Speicherantwort unklar. Zuerst die E-Bilanz mit derselben Kennung lesen; keinen zweiten Auftrag beginnen.';}
if($balanceLocalError!==''&&($view!=='finance'||input($_GET,'tab')!=='tax'))$error=$balanceLocalError;
