<?php declare(strict_types=1);
require __DIR__.'/document-xml.php';
require_once __DIR__.'/../src/Finance/Invoices/EInvoiceInspection.php';
use Kulturhus\Verein\EInvoiceInspection;
foreach(['Invoice'=>'Invoice','CreditNote'=>'CreditNote'] as $element=>$namespace){
    $s=EInvoiceInspection::inspect('<'.$element.' xmlns="urn:oasis:names:specification:ubl:schema:xsd:'.$namespace.'-2"/>');
    check($s['state']==='recognized'&&!$s['validated'],'recognized '.$element.' is never declared valid');
}
check(EInvoiceInspection::inspect('<CrossIndustryInvoice xmlns="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"/>')['state']==='recognized','CII root recognized');
check(EInvoiceInspection::inspect('<Invoice xmlns="urn:fake"/>')['state']==='unchecked','name without trusted namespace not recognized');
check(EInvoiceInspection::inspect('<wrapper><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/></wrapper>')['state']==='unchecked','nested spoof not recognized');
check(EInvoiceInspection::inspect('<Invoice>')['state']==='invalid','malformed original fails');
check(EInvoiceInspection::inspect('<!DOCTYPE i SYSTEM "file:///never"><i/>')['state']==='invalid','external DTD blocked');
check(EInvoiceInspection::inspect('<!DOCTYPE i [<!ENTITY a "unsafe">]><i>&a;</i>')['state']==='invalid','entities blocked');
check(EInvoiceInspection::inspect(str_repeat('<i>',70).str_repeat('</i>',70))['state']==='unchecked','depth limit remains unvalidated');
check(EInvoiceInspection::inspect(str_repeat('x',10485761))['state']==='unchecked','size limit remains unvalidated');
$s=EInvoiceInspection::document($documents,$id,1);
check($s['revision']===1&&$s['sha256']===hash('sha256',$xml),'result bound to exact authorized original revision');
denied(fn()=>EInvoiceInspection::document(new \Kulturhus\Verein\Documents($db,'member'),$id),'inspection cannot bypass member protection');
denied(fn()=>EInvoiceInspection::document(new \Kulturhus\Verein\Documents($db,'foreign'),$id),'inspection cannot bypass club protection');
echo "$checks combined XML inspection checks passed\n";
