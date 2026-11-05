<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/CiiInvoice.php';
require_once __DIR__.'/HybridEInvoice.php';

/** One canonical, validated original for download, archive and the mail attachment. */
final class IssuedInvoiceFile
{
    public static function xml(array $invoice,string $pdf):string
    {
        if(($invoice['data']['domestic_confirmed']??false)!==true)throw new DomainException('Bitte bestätigen: Verein und Rechnungsempfänger sind in Deutschland ansässig.');
        $invoice['state']='issued';$sha=hash('sha256',$pdf);
        return CiiInvoice::build($invoice,['domestic_country'=>'DE','source_hash'=>CiiInvoice::sourceHash($invoice,$sha)],$sha,true);
    }

    public static function build(array $invoice,array $document):array
    {
        $original=DocumentPreview::pdf($document);
        if(($invoice['data']['invoice_format']??'')==='pdf')return ['document'=>$document,'bytes'=>$original];
        $xml=self::xml($invoice,$original);
        $kosit=EInvoiceValidator::run($xml);
        if($kosit['state']!=='valid')throw new DomainException('Die E-Rechnungsprüfung wurde nicht bestanden. Bitte Empfängerangaben, Referenz und Steuerbeträge prüfen. Die Rechnung wurde nicht ausgestellt.');
        $hybrid=HybridEInvoice::build($original,$xml,CiiInvoice::XRECHNUNG);
        $document['einvoice']=['format'=>'PDF/A-3b + XRechnung CII','xml'=>$xml,'xml_sha256'=>hash('sha256',$xml),'kosit'=>$kosit,'validation'=>$hybrid['validation']];
        return ['document'=>$document,'bytes'=>$hybrid['bytes']];
    }
}
