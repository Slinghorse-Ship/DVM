<?php declare(strict_types=1);
namespace Kulturhus\Verein;
/** Original decimal strings only. No accounting amounts, tax decisions or auto-booking. */
final class EInvoiceLineItems
{
 public static function read(string $bytes):array
 {
  $empty=['items'=>[],'items_truncated'=>false];if(strlen($bytes)>10485760)return $empty;
  $names=['urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'=>'cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2'=>'cac','urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100'=>'rsm','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100'=>'ram'];
  $ubl=['cbc:ID'=>'number','cbc:InvoicedQuantity'=>'quantity','cbc:CreditedQuantity'=>'quantity','cac:Item/cbc:Name'=>'name','cac:Price/cbc:PriceAmount'=>'unit_price','cac:Price/cbc:BaseQuantity'=>'price_base_quantity','cbc:LineExtensionAmount'=>'net_amount','cac:Item/cac:ClassifiedTaxCategory/cbc:Percent'=>'tax_rate','cac:Item/cac:ClassifiedTaxCategory/cbc:ID'=>'tax_category'];
  $cii=['ram:AssociatedDocumentLineDocument/ram:LineID'=>'number','ram:SpecifiedTradeProduct/ram:Name'=>'name','ram:SpecifiedLineTradeDelivery/ram:BilledQuantity'=>'quantity','ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount'=>'unit_price','ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity'=>'price_base_quantity','ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'=>'net_amount','ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent'=>'tax_rate','ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'=>'tax_category'];
  $reader=new \XMLReader();$previous=libxml_use_internal_errors(true);$stack=[];$items=[];$item=null;$lineDepth=0;$capture=null;$seen=[];$nodes=0;$truncated=false;
  try{
   libxml_clear_errors();if(!$reader->XML($bytes,null,LIBXML_NONET))return $empty;$reader->setParserProperty(\XMLReader::LOADDTD,false);$reader->setParserProperty(\XMLReader::SUBST_ENTITIES,false);
   while($reader->read()){
    if(++$nodes>200000||$reader->depth>64||in_array($reader->nodeType,[\XMLReader::DOC_TYPE,\XMLReader::ENTITY_REF],true))return $empty;
    if($reader->nodeType===\XMLReader::ELEMENT){
     if($reader->depth===0){$root='{'.$reader->namespaceURI.'}'.$reader->localName;if(!in_array($root,['{urn:oasis:names:specification:ubl:schema:xsd:Invoice-2}Invoice','{urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2}CreditNote','{urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100}CrossIndustryInvoice'],true))return $empty;$linePaths=str_ends_with($root,'}CrossIndustryInvoice')?['rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem']:(str_ends_with($root,'}CreditNote')?['cac:CreditNoteLine']:['cac:InvoiceLine']);continue;}
     $stack[$reader->depth]=($names[$reader->namespaceURI]??'{'.$reader->namespaceURI.'}').':'.$reader->localName;$stack=array_filter($stack,fn($depth)=>$depth<=$reader->depth,ARRAY_FILTER_USE_KEY);$path=implode('/',$stack);
     if(in_array($path,$linePaths,true)&&!$reader->isEmptyElement){$lineDepth=$reader->depth;$item=array_fill_keys(['number','name','quantity','unit','unit_price','price_base_quantity','net_amount','tax_rate','tax_category'],'');$item['ambiguous']=false;$seen=[];$capture=null;continue;}
     if($item===null)continue;$relative=implode('/',array_filter($stack,fn($depth)=>$depth>$lineDepth,ARRAY_FILTER_USE_KEY));$map=$lineDepth===1?$ubl:$cii;$key=$map[$relative]??null;
     if($key!==null&&!$reader->isEmptyElement){if(isset($seen[$key])){$item['ambiguous']=true;$capture=null;}else{$seen[$key]=true;$capture=[$key,$reader->depth];if($key==='quantity')$item['unit']=mb_substr((string)$reader->getAttribute('unitCode'),0,30);}}else $capture=null;
    }elseif(in_array($reader->nodeType,[\XMLReader::TEXT,\XMLReader::CDATA],true)&&$capture&&$item!==null)$item[$capture[0]]=mb_substr($item[$capture[0]].$reader->value,0,300);
    elseif($reader->nodeType===\XMLReader::END_ELEMENT){if($item!==null&&$reader->depth===$lineDepth){if(count($items)<200){foreach($item as $k=>$value)if(is_string($value))$item[$k]=trim($value);$items[]=$item;}else $truncated=true;$item=null;$capture=null;}elseif($capture&&$reader->depth===$capture[1])$capture=null;}
   }
   if(libxml_get_errors())return $empty;return ['items'=>$items,'items_truncated'=>$truncated];
  }finally{$reader->close();libxml_clear_errors();libxml_use_internal_errors($previous);}
 }
}
