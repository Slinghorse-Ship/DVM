<?php declare(strict_types=1);
namespace Kulturhus\Verein;
/** Called only after the bounded, DTD-free namespace parser accepted the XML. */
final class EInvoicePartyFields
{
 public static function read(string $xml,array $fields):array
 {
  if(!$fields)return ['fields'=>[],'bank_accounts'=>[]];
  $dom=new \DOMDocument();$dom->loadXML($xml,LIBXML_NONET);$xp=new \DOMXPath($dom);
  foreach(['cbc'=>'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2','cac'=>'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2','rsm'=>'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100','ram'=>'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100','udt'=>'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100'] as $p=>$ns)$xp->registerNamespace($p,$ns);
  $text=static function(string $path,?\DOMNode $context=null)use($xp):string{$values=[];foreach($xp->query($path,$context) as $n){$v=trim($n->textContent);if($v!=='')$values[]=mb_substr($v,0,300);if(count($values)>=10)break;}return implode(' / ',array_unique($values));};
  $cii=$dom->documentElement->localName==='CrossIndustryInvoice';
  $agreement='/*/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';
  $settlement='/*/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';
  $party=$cii?$agreement:'/*/cac:AccountingSupplierParty/cac:Party';
  $address=$party.($cii?'/ram:PostalTradeAddress':'/cac:PostalAddress');
  $parts=[];foreach($cii?['ram:LineOne','ram:LineTwo','ram:LineThree','ram:PostcodeCode','ram:CityName','ram:CountryID']:['cbc:StreetName','cbc:BuildingNumber','cbc:AdditionalStreetName','cac:AddressLine/cbc:Line','cbc:PostalZone','cbc:CityName','cac:Country/cbc:IdentificationCode'] as $part){$v=$text($address.'/'.$part);if($v!=='')$parts[]=$v;}
  if($parts)$fields['Anschrift Rechnungssteller']=implode(', ',$parts);
  if(($fields['Rechnungssteller']??'')==='')$fields['Rechnungssteller']=$text($party.($cii?'/ram:Name':'/cac:PartyName/cbc:Name'));
  $map=$cii?[
   'E-Mail Rechnungssteller'=>$party.'/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID',
   'Telefon Rechnungssteller'=>$party.'/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber',
   'Rechnungsbetrag netto'=>$settlement.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount',
   'Umsatzsteuerbetrag'=>$settlement.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount',
   'Fälligkeit'=>$settlement.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString',
  ]:[
   'E-Mail Rechnungssteller'=>$party.'/cac:Contact/cbc:ElectronicMail',
   'Telefon Rechnungssteller'=>$party.'/cac:Contact/cbc:Telephone',
   'Rechnungsbetrag netto'=>'/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount',
   'Umsatzsteuerbetrag'=>'/*/cac:TaxTotal/cbc:TaxAmount',
  ];
  foreach($map as $label=>$path){$value=$text($path);if($value!=='')$fields[$label]=$value;}
  foreach(['Rechnungsdatum','Fälligkeit'] as $label)if(preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/D',$fields[$label]??'',$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]))$fields[$label]=$m[1].'-'.$m[2].'-'.$m[3];
  $accounts=[];$paymentPath=$cii?$settlement.'/ram:SpecifiedTradeSettlementPaymentMeans':'/*/cac:PaymentMeans';
  foreach($xp->query($paymentPath) as $payment){
   $iban=$text($cii?'ram:PayeePartyCreditorFinancialAccount/ram:IBANID':'cac:PayeeFinancialAccount/cbc:ID',$payment);
   $bic=$text($cii?'ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID':'cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID',$payment);
   $name=$text($cii?'ram:PayeeSpecifiedCreditorFinancialInstitution/ram:Name':'cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cac:FinancialInstitution/cbc:Name',$payment);
   // Non-IBAN domestic account IDs remain outside the IBAN picker. No first-account default.
   $iban=strtoupper(preg_replace('/\s+/u','',$iban));
   if(preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D',$iban))$accounts[$iban.'|'.$bic]=['iban'=>$iban,'bic'=>$bic,'bank_name'=>$name];
   if(count($accounts)>=20)break;
  }
  return ['fields'=>array_filter($fields,static fn($v)=>$v!==''),'bank_accounts'=>array_values($accounts)];
 }
}
