<?php declare(strict_types=1);
namespace Kulturhus\Banking;
use DomainException;use DOMDocument;use DOMXPath;
/** Read bank entry amounts as decimals; never infer currency or posting IDs. */
final class CamtReader
{
 public static function rows(string $xml,string $expectedIban):array{
  if(strlen($xml)>10*1024*1024||str_contains($xml,'<!DOCTYPE')||str_contains($xml,'<!ENTITY')||str_contains($xml,"\0"))throw new DomainException('Unsicheres oder zu großes Bank-XML.');
  $prior=libxml_use_internal_errors(true);try{$reader=new \XMLReader();if(!$reader->XML($xml,null,LIBXML_NONET))throw new DomainException('Bank-XML unlesbar.');$n=0;while($reader->read())if(++$n>200000||$reader->depth>64)throw new DomainException('Bank-XML ist zu komplex.');$reader->close();if(libxml_get_errors())throw new DomainException('Bank-XML ist fehlerhaft.');$d=new DOMDocument();if(!$d->loadXML($xml,LIBXML_NONET))throw new DomainException('Bank-XML ist unlesbar.');}
  finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
  $ns=$d->documentElement->namespaceURI;if(!preg_match('/^urn:iso:std:iso:20022:tech:xsd:camt\.05[23]\.001\.\d{2}$/D',$ns??''))throw new DomainException('Nicht unterstütztes CAMT-Kontoformat.');$x=new DOMXPath($d);$x->registerNamespace('c',$ns);$rows=[];
  foreach($x->query('/c:Document/c:BkToCstmrStmt/c:Stmt | /c:Document/c:BkToCstmrAcctRpt/c:Rpt') as $statement){$iban=$x->evaluate('string(c:Acct/c:Id/c:IBAN)',$statement);if($iban===''||\Kulturhus\Verein\Iban::normalize($iban)!==\Kulturhus\Verein\Iban::normalize($expectedIban))throw new DomainException('Bank-XML enthält ein anderes Konto.');
   foreach($x->query('c:Ntry',$statement) as $entry){$get=fn($path)=>trim((string)$x->evaluate('string('.$path.')',$entry));$direction=$get('c:CdtDbtInd');$party=$direction==='CRDT'?'Dbtr':'Cdtr';$names=[];$purposes=[];
    foreach($x->query('c:NtryDtls/c:TxDtls/c:RltdPties/c:'.$party.'/c:Nm | c:NtryDtls/c:TxDtls/c:RltdPties/c:'.$party.'/c:Pty/c:Nm',$entry) as $name)$names[]=trim($name->textContent);
    foreach($x->query('c:NtryDtls/c:TxDtls/c:RmtInf/c:Ustrd | c:NtryDtls/c:TxDtls/c:RmtInf/c:Strd/c:CdtrRefInf/c:Ref | c:NtryDtls/c:TxDtls/c:Refs/c:EndToEndId',$entry) as $purpose)if(trim($purpose->textContent)!=='NOTPROVIDED')$purposes[]=trim($purpose->textContent);
    $reference=$get('c:AcctSvcrRef');if(in_array(strtoupper($reference),['NONREF','NOTPROVIDED'],true))$reference='';
    $status=$get('c:Sts/c:Cd')?:$get('c:Sts');$date=$get('c:BookgDt/c:Dt')?:substr($get('c:BookgDt/c:DtTm'),0,10);
    $rows[]=['entry_reference'=>$reference,'transaction_amount'=>['currency'=>$get('c:Amt/@Ccy'),'amount'=>$get('c:Amt')],'credit_debit_indicator'=>$direction,'status'=>$status,'booking_date'=>$date,'debtor'=>['name'=>mb_substr(implode(', ',array_unique($names)),0,180)],'creditor'=>['name'=>mb_substr(implode(', ',array_unique($names)),0,180)],'remittance_information'=>[mb_substr(implode(' ',array_unique($purposes))?:$get('c:AddtlNtryInf'),0,480)],'reversal_indicator'=>$get('c:RvslInd'),'camt_entry'=>$d->saveXML($entry)];
    if(count($rows)>10000)throw new DomainException('Zu viele Bankeinträge.');
   }
  }return $rows;
 }
}
