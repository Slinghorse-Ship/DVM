<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DOMDocument;
use DOMElement;
use DomainException;

/** EN16931 CII for the original issued invoice. Later payments do not rewrite it. */
final class CiiInvoice
{
    public const XRECHNUNG='urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';
    public static function sourceHash(array $invoice,string $originalPdfSha):string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$originalPdfSha))throw new DomainException('Der Hash des ausgegebenen PDF-Originals fehlt.');
        return hash('sha256',json_encode([$invoice['id'],$invoice['number'],$invoice['kind'],$invoice['state'],$invoice['data'],$originalPdfSha],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public static function build(array $invoice,array $confirmation=[],string $originalPdfSha='',bool $xrechnung=false):string
    {
        if(!in_array($invoice['kind']??'', $xrechnung?['invoice','credit','cancellation']:['invoice'],true)||($invoice['state']??'')!=='issued')throw new DomainException('Eine ausgegebene Originalrechnung auswählen.');
        if(($confirmation['domestic_country']??'')!=='DE'||!is_string($confirmation['source_hash']??null)||!hash_equals(self::sourceHash($invoice,$originalPdfSha),$confirmation['source_hash']))throw new DomainException('Bitte anhand der angezeigten Originalanschriften bestätigen, dass Rechnungssteller und Empfänger in Deutschland ansässig sind.');
        $data=$invoice['data'];$issuer=$data['issuer']??[];$seller=$issuer['club']??[];
        // Legacy invoice snapshots contain no country field. The deliberate,
        // source-bound confirmation above is required; never infer it from a ZIP.
        foreach([$data['buyer_country']??'', $seller['country']??''] as $country)if($country!==''&&$country!=='DE')throw new DomainException('Dieser ZUGFeRD-Export unterstützt ausschließlich inländische Rechnungsparteien.');
        foreach(['name','street','postcode','city'] as $key)if(trim((string)($seller[$key]??''))==='')throw new DomainException('Die ausgegebenen Vereinsdaten sind unvollständig.');
        foreach(['buyer_name','buyer_street','buyer_postcode','buyer_city','document_date','delivery_date','due_date'] as $key)if(trim((string)($data[$key]??''))==='')throw new DomainException('Die ausgegebenen Rechnungsangaben sind unvollständig.');
        $taxNumber=trim((string)($issuer['tax']['number']??''));if($taxNumber==='')throw new DomainException('Steuernummer fehlt im ausgegebenen Original.');
        if($xrechnung){
            foreach(['buyer_reference'=>'Empfängerreferenz / Leitweg-ID','buyer_email'=>'E-Mail des Empfängers','seller_contact'=>'Ansprechperson / Rechnungsstelle'] as $key=>$label)if(trim((string)($data[$key]??''))==='')throw new DomainException('Bitte '.$label.' ergänzen.');
            if(!filter_var($data['buyer_email'],FILTER_VALIDATE_EMAIL)||!filter_var($seller['email']??'',FILTER_VALIDATE_EMAIL)||trim((string)($seller['phone']??''))==='')throw new DomainException('Für die E-Rechnung werden gültige E-Mail-Adressen und die Telefonnummer des Vereins benötigt.');
            if($invoice['kind']!=='invoice'&&(empty($data['original_number'])||!TaxCatalog::documentYear($data['original_date']??'')))throw new DomainException('Bezug zur ursprünglichen Rechnung fehlt.');
        }
        $register=trim((string)($seller['register']??''));if($register==='')throw new DomainException('Vereinsregisternummer und Registergericht fehlen im ausgegebenen Original.');
        $iban=Iban::normalize((string)($issuer['bank']['iban']??''));
        $positions=$data['positions']??[];if(!$positions||count($positions)>50)throw new DomainException('Ausgegebene Rechnungspositionen fehlen.');
        $groups=[];$net=0;$tax=0;$gross=0;
        foreach($positions as $p){
            if(!in_array((int)$p['rate'],[0,7,19],true)||(int)$p['quantity']<=0||(int)$p['gross']<=0||(int)$p['tax']<0||(int)$p['tax']>=(int)$p['gross']||!isset(OutgoingInvoices::UNITS[$p['unit']]))throw new DomainException('Ausgegebene Rechnungsposition ist nicht unterstützt.');
            $r=(int)$p['rate'];$lineNet=(int)$p['gross']-(int)$p['tax'];$groups[$r]['net']=($groups[$r]['net']??0)+$lineNet;$groups[$r]['tax']=($groups[$r]['tax']??0)+(int)$p['tax'];$net+=$lineNet;$tax+=(int)$p['tax'];$gross+=(int)$p['gross'];
        }
        if($gross!==(int)$data['total_cents'])throw new DomainException('Die Positionssumme stimmt nicht mit dem ausgegebenen Gesamtbetrag überein.');
        if(isset($groups[0])&&trim((string)($data['vat_reason']??''))==='')throw new DomainException('Steuerbefreiungsgrund fehlt im ausgegebenen Original.');
        $d=new DOMDocument('1.0','UTF-8');$d->formatOutput=true;
        $ns=['rsm'=>'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100','ram'=>'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100','udt'=>'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100'];
        $root=$d->createElementNS($ns['rsm'],'rsm:CrossIndustryInvoice');$d->appendChild($root);
        $add=static function(DOMElement $parent,string $name,?string $value=null,array $attrs=[])use($d,$ns):DOMElement{$node=$d->createElementNS($ns[explode(':',$name)[0]],$name);if($value!==null)$node->appendChild($d->createTextNode($value));foreach($attrs as $key=>$v)$node->setAttribute($key,$v);$parent->appendChild($node);return $node;};
        $money=static fn(int $n):string=>OutgoingInvoices::amount($n);
        $date=static function(DOMElement $parent,string $name,string $value)use($add):void{if(!TaxCatalog::documentYear($value))throw new DomainException('Originaldatum ungültig.');$add($add($parent,$name),'udt:DateTimeString',str_replace('-','',$value),['format'=>'102']);};
        $context=$add($root,'rsm:ExchangedDocumentContext');if($xrechnung)$add($add($context,'ram:BusinessProcessSpecifiedDocumentContextParameter'),'ram:ID','urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');$add($add($context,'ram:GuidelineSpecifiedDocumentContextParameter'),'ram:ID',$xrechnung?self::XRECHNUNG:'urn:cen.eu:en16931:2017');
        $document=$add($root,'rsm:ExchangedDocument');$add($document,'ram:ID',(string)$invoice['number']);$add($document,'ram:TypeCode',$invoice['kind']==='invoice'?'380':'381');$date($document,'ram:IssueDateTime',$data['document_date']);
        if(trim((string)($data['note']??''))!=='')$add($add($document,'ram:IncludedNote'),'ram:Content',$data['note']);
        $transaction=$add($root,'rsm:SupplyChainTradeTransaction');
        foreach($positions as $index=>$p){
            $line=$add($transaction,'ram:IncludedSupplyChainTradeLineItem');$add($add($line,'ram:AssociatedDocumentLineDocument'),'ram:LineID',(string)($index+1));$add($add($line,'ram:SpecifiedTradeProduct'),'ram:Name',$p['description']);
            $quantity=OutgoingInvoices::quantityText((int)$p['quantity']);$lineNet=(int)$p['gross']-(int)$p['tax'];
            // The exact original net line amount is the price for this basis quantity.
            // Avoids rounding a calculated unit net price and changing the invoice.
            $price=$add($add($line,'ram:SpecifiedLineTradeAgreement'),'ram:NetPriceProductTradePrice');$add($price,'ram:ChargeAmount',$money($lineNet));$add($price,'ram:BasisQuantity',$quantity,['unitCode'=>$p['unit']]);
            $add($add($line,'ram:SpecifiedLineTradeDelivery'),'ram:BilledQuantity',$quantity,['unitCode'=>$p['unit']]);
            $settlement=$add($line,'ram:SpecifiedLineTradeSettlement');$vat=$add($settlement,'ram:ApplicableTradeTax');$add($vat,'ram:TypeCode','VAT');$add($vat,'ram:CategoryCode',(int)$p['rate']===0?'E':'S');$add($vat,'ram:RateApplicablePercent',(string)$p['rate']);
            $add($add($settlement,'ram:SpecifiedTradeSettlementLineMonetarySummation'),'ram:LineTotalAmount',$money($lineNet));
        }
        $agreement=$add($transaction,'ram:ApplicableHeaderTradeAgreement');if(trim((string)($data['buyer_reference']??''))!=='')$add($agreement,'ram:BuyerReference',$data['buyer_reference']);
        foreach(['seller','buyer'] as $type){
            $party=$add($agreement,$type==='seller'?'ram:SellerTradeParty':'ram:BuyerTradeParty');$add($party,'ram:Name',$type==='seller'?$seller['name']:$data['buyer_name']);if($type==='seller')$add($add($party,'ram:SpecifiedLegalOrganization'),'ram:ID',$register);
            if($xrechnung&&$type==='seller'){$contact=$add($party,'ram:DefinedTradeContact');$add($contact,'ram:PersonName',$data['seller_contact']);$add($add($contact,'ram:TelephoneUniversalCommunication'),'ram:CompleteNumber',$seller['phone']);$add($add($contact,'ram:EmailURIUniversalCommunication'),'ram:URIID',$seller['email']);}
            $address=$add($party,'ram:PostalTradeAddress');$add($address,'ram:PostcodeCode',$type==='seller'?$seller['postcode']:$data['buyer_postcode']);$add($address,'ram:LineOne',$type==='seller'?$seller['street']:$data['buyer_street']);$add($address,'ram:CityName',$type==='seller'?$seller['city']:$data['buyer_city']);$add($address,'ram:CountryID','DE');
            $email=trim((string)($type==='seller'?($seller['email']??''):($data['buyer_email']??'')));if($email!==''){if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainException('E-Mail im Original ungültig.');$add($add($party,'ram:URIUniversalCommunication'),'ram:URIID',$email,['schemeID'=>'EM']);}
            if($type==='seller')$add($add($party,'ram:SpecifiedTaxRegistration'),'ram:ID',$taxNumber,['schemeID'=>'FC']);
        }
        $date($add($add($transaction,'ram:ApplicableHeaderTradeDelivery'),'ram:ActualDeliverySupplyChainEvent'),'ram:OccurrenceDateTime',$data['delivery_date']);
        $settlement=$add($transaction,'ram:ApplicableHeaderTradeSettlement');$add($settlement,'ram:PaymentReference',(string)$invoice['number']);$add($settlement,'ram:InvoiceCurrencyCode','EUR');
        $means=$add($settlement,'ram:SpecifiedTradeSettlementPaymentMeans');$add($means,'ram:TypeCode','58');$account=$add($means,'ram:PayeePartyCreditorFinancialAccount');$add($account,'ram:IBANID',$iban);if(trim((string)($issuer['bank']['holder']??''))!=='')$add($account,'ram:AccountName',$issuer['bank']['holder']);
        ksort($groups);foreach($groups as $rate=>$group){$vat=$add($settlement,'ram:ApplicableTradeTax');$add($vat,'ram:CalculatedAmount',$money($group['tax']));$add($vat,'ram:TypeCode','VAT');if($rate===0)$add($vat,'ram:ExemptionReason',$data['vat_reason']);$add($vat,'ram:BasisAmount',$money($group['net']));$add($vat,'ram:CategoryCode',$rate===0?'E':'S');$add($vat,'ram:RateApplicablePercent',(string)$rate);}
        $date($add($settlement,'ram:SpecifiedTradePaymentTerms'),'ram:DueDateDateTime',$data['due_date']);
        $total=$add($settlement,'ram:SpecifiedTradeSettlementHeaderMonetarySummation');foreach(['LineTotalAmount'=>$net,'ChargeTotalAmount'=>0,'AllowanceTotalAmount'=>0,'TaxBasisTotalAmount'=>$net,'TaxTotalAmount'=>$tax,'GrandTotalAmount'=>$gross,'TotalPrepaidAmount'=>0,'DuePayableAmount'=>$gross] as $key=>$value)$add($total,'ram:'.$key,$money($value),$key==='TaxTotalAmount'?['currencyID'=>'EUR']:[]);
        if($xrechnung&&$invoice['kind']!=='invoice'){$reference=$add($settlement,'ram:InvoiceReferencedDocument');$add($reference,'ram:IssuerAssignedID',$data['original_number']);$formatted=$add($reference,'ram:FormattedIssueDateTime');$stamp=$d->createElementNS('urn:un:unece:uncefact:data:standard:QualifiedDataType:100','qdt:DateTimeString');$stamp->setAttribute('format','102');$stamp->appendChild($d->createTextNode(str_replace('-','',$data['original_date'])));$formatted->appendChild($stamp);}
        return $d->saveXML();
    }
}
