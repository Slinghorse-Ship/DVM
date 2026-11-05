<?php declare(strict_types=1);
if(!array_key_exists('member_excel',$_GET))return;
try{
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)||($_GET['member_excel']??null)!=='template'||($_GET['view']??null)!=='members'||($_GET['import']??null)!=='1')throw new DomainException('Excel-Vorlage im ursprünglichen Mitgliederbereich öffnen.',422);
    if(!$registry->canWrite())throw new DomainException('Mitglieder-Schreibrecht erforderlich.',403);
    $bytes=\Kulturhus\Verein\MemberWorkbook::create();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="DVM-Mitglieder-Vorlage.xlsx"');header('Content-Length: '.strlen($bytes));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo$bytes;
}catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,422],true)?$failure->getCode():422);header('Content-Type: text/plain; charset=utf-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo$failure->getMessage();}
exit;
