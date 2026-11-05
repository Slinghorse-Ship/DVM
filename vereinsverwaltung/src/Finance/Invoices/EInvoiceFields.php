<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/EInvoiceLineItems.php';
require_once __DIR__.'/EInvoicePartyFields.php';
/** Bounded, namespace-aware display extraction; not financial validation or automatic booking. */
final class EInvoiceFields
{
 public static function details(string $bytes):array{return EInvoicePartyFields::read($bytes,self::read($bytes))+EInvoiceLineItems::read($bytes);}
 public static function read(string $bytes):array
 {
  $names=['urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'=>'cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2'=>'cac','urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100'=>'rsm','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100'=>'ram','urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100'=>'udt'];
  $paths=['rsm:ExchangedDocument/ram:TypeCode'=>'Belegtyp-Code','cbc:ID'=>'Rechnungsnummer','cbc:IssueDate'=>'Rechnungsdatum','cbc:DueDate'=>'Fälligkeit','cbc:DocumentCurrencyCode'=>'Währung','cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName'=>'Rechnungssteller','cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName'=>'Empfänger','cac:LegalMonetaryTotal/cbc:PayableAmount'=>'Zahlbetrag laut Datei','cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'=>'Rechnungsbetrag brutto','rsm:ExchangedDocument/ram:ID'=>'Rechnungsnummer','rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'=>'Rechnungsdatum','rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name'=>'Rechnungssteller','rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name'=>'Empfänger','rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode'=>'Währung','rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount'=>'Zahlbetrag laut Datei','rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount'=>'Rechnungsbetrag brutto'];
  $reader=new \XMLReader();$previous=libxml_use_internal_errors(true);$stack=[];$field=null;$values=[];$nodes=0;$root='';
  try{
   libxml_clear_errors();if(strlen($bytes)>10485760||!$reader->XML($bytes,null,LIBXML_NONET))return [];$reader->setParserProperty(\XMLReader::LOADDTD,false);$reader->setParserProperty(\XMLReader::SUBST_ENTITIES,false);
   while($reader->read()){
    if(++$nodes>200000||$reader->depth>64||in_array($reader->nodeType,[\XMLReader::DOC_TYPE,\XMLReader::ENTITY_REF],true))return [];
    if($reader->nodeType===\XMLReader::ELEMENT){if($reader->depth===0){if(!in_array('{'.$reader->namespaceURI.'}'.$reader->localName,['{urn:oasis:names:specification:ubl:schema:xsd:Invoice-2}Invoice','{urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2}CreditNote','{urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100}CrossIndustryInvoice'],true))return [];$root=$reader->localName;continue;}$stack[$reader->depth]=($names[$reader->namespaceURI]??'{'.$reader->namespaceURI.'}').':'.$reader->localName;$stack=array_filter($stack,fn($depth)=>$depth<=$reader->depth,ARRAY_FILTER_USE_KEY);$path=implode('/',$stack);$field=isset($paths[$path])&&!$reader->isEmptyElement?[$paths[$path],$reader->depth]:null;if($field){if(!isset($values[$field[0]]))$values[$field[0]]='';else $values[$field[0]].=' / ';}}
    elseif(in_array($reader->nodeType,[\XMLReader::TEXT,\XMLReader::CDATA],true)&&$field)$values[$field[0]]=mb_substr($values[$field[0]].$reader->value,0,300);
    elseif($reader->nodeType===\XMLReader::END_ELEMENT&&$field&&$reader->depth===$field[1])$field=null;
   }
   if(libxml_get_errors())return [];$type=trim((string)($values['Belegtyp-Code']??''));$values['Belegart']=$root==='Invoice'?'Rechnung':($root==='CreditNote'?'Gutschrift':($type==='380'?'Rechnung':($type==='381'?'Gutschrift':'Unbekannt')));return array_filter(array_map('trim',$values),fn($v)=>$v!=='');
  }finally{$reader->close();libxml_clear_errors();libxml_use_internal_errors($previous);}
 }
}
