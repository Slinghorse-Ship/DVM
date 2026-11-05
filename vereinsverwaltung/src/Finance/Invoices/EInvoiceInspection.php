<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/EInvoiceFields.php';

/** Format inspection only: never substitutes for XSD/Schematron validation. */
final class EInvoiceInspection
{
    public static function document(Documents $documents,string $id,int $revision=0):array
    {
        $file=$documents->file($id,$revision);
        $bytes=(string)$file['bytes'];$source=str_starts_with($bytes,'%PDF-')?'embedded_xml':'xml';
        try{$xml=self::xmlBytes($bytes);$result=$xml===null?['state'=>'unchecked','label'=>'Keine eingebettete E-Rechnung','format'=>'PDF','validated'=>false]:self::inspect($xml);
            if($result['state']==='recognized')$result+=EInvoiceFields::details($xml);
        }catch(\DomainException $e){$result=['state'=>'invalid','label'=>'Eingebettete Rechnungsdaten nicht sicher auswertbar','format'=>'PDF','validated'=>false,'error'=>$e->getMessage()];}
        return $result+['source'=>$source,'document_id'=>$id,'revision'=>(int)$file['revision'],'sha256'=>hash('sha256',$bytes)];
    }
    /** XML bytes remain server-side; original document ACL/hash/revision stays authoritative. */
    public static function xmlBytes(string $bytes):?string
    {
        if(!str_starts_with($bytes,'%PDF-'))return $bytes;
        require_once __DIR__.'/InvoiceRecognition.php';
        $result=InvoiceRecognition::extract($bytes,'application/pdf','embedded_xml');
        if(($result['state']??'')!=='ready')throw new \DomainException((string)($result['error']??'PDF-Anhänge nicht sicher auswertbar.'));
        if(empty($result['embedded']))return null;
        $xml=base64_decode((string)($result['xml_base64']??''),true);
        if($xml===false||strlen($xml)>2097152||self::inspect($xml)['state']!=='recognized')throw new \DomainException('Der XML-Anhang ist keine sicher erkannte UBL-/CII-Rechnung. Bitte Original prüfen.');
        return $xml;
    }
    public static function inspect(string $bytes):array
    {
        $result=['state'=>'unchecked','label'=>'Fachlich ungeprüft','format'=>'Nicht erkannt','validated'=>false];
        if(!class_exists(\XMLReader::class))return $result+['error'=>'XML-Laufzeit fehlt.'];
        if(strlen($bytes)>10*1024*1024)return $result+['error'=>'Datei für die lokale Erkennung zu groß (maximal 10 MB).'];
        $xml=new \XMLReader();$previous=libxml_use_internal_errors(true);$root=null;$nodes=0;
        try{
            libxml_clear_errors();
            if(!$xml->XML($bytes,null,LIBXML_NONET))throw new \RuntimeException();
            $xml->setParserProperty(\XMLReader::LOADDTD,false);
            $xml->setParserProperty(\XMLReader::SUBST_ENTITIES,false);
            while($xml->read()){
                if(++$nodes>200000||$xml->depth>64)return $result+['error'=>'Datei überschreitet die Grenzen der lokalen Erkennung.'];
                if(in_array($xml->nodeType,[\XMLReader::DOC_TYPE,\XMLReader::ENTITY_REF],true))throw new \RuntimeException();
                if($xml->nodeType===\XMLReader::ELEMENT&&$xml->depth===0)$root='{'.$xml->namespaceURI.'}'.$xml->localName;
            }
            if(!$root||libxml_get_errors())throw new \RuntimeException();
            $formats=[
                '{urn:oasis:names:specification:ubl:schema:xsd:Invoice-2}Invoice'=>'UBL-Rechnung',
                '{urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2}CreditNote'=>'UBL-Gutschrift',
                '{urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100}CrossIndustryInvoice'=>'UN/CEFACT CII',
            ];
            if(isset($formats[$root]))$result=array_replace($result,['state'=>'recognized','label'=>'Format erkannt · fachlich ungeprüft','format'=>$formats[$root]]);
            return $result;
        }catch(\Throwable){return array_replace($result,['state'=>'invalid','label'=>'XML fehlerhaft oder nicht sicher auswertbar']);}
        finally{$xml->close();libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
