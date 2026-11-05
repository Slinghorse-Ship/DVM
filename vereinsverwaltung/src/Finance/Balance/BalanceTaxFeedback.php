<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** EBilanzRueckmeldung SSb 1.1 section 2: changed tax values, NOT an acknowledgement.
 * SGEB/WGB submissions are excluded. Absence of a return proves nothing.
 * Read-only policy; never unlocks sending or changes accounting data.
 */
final class BalanceTaxFeedback
{
    public static function describe(array $filing,array $attempts):array
    {
        $state=$filing['state']??'';
        $out=['technical'=>'not_sent','later'=>'not_determined','pollable'=>false,'tax_approved'=>false,'message'=>'Noch nicht übermittelt.'];
        if(in_array($state,['sending','unknown','sent_test','sent_production'],true)){
            $out['technical']='uncertain';$out['message']='Eingang nicht sicher bestätigt. Nicht erneut senden; Übermittlungsprotokoll prüfen.';
            foreach($attempts as $attempt)if(in_array($state,['sent_test','sent_production'],true)&&($attempt['state']??null)===$state&&($attempt['receipt_bound']??false)===true){
                $out['technical']='confirmed';$out['message']='Technischer Eingang bestätigt. Dies ist kein Steuerbescheid.';break;
            }
        }
        $xml=$filing['xml_data']??'';$hash=$filing['xml_sha256']??'';
        if(!is_string($xml)||$xml===''||strlen($xml)>8388608||!is_string($hash)||!hash_equals(hash('sha256',$xml),$hash)||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))return $out;
        $previous=libxml_use_internal_errors(true);
        try{
            $doc=new \DOMDocument();if(!$doc->loadXML($xml,LIBXML_NONET))return $out;
            $xp=new \DOMXPath($doc);$xp->registerNamespace('g','http://www.xbrl.de/taxonomies/de-gcd-2025-04-01');
            $xp->registerNamespace('i','http://www.w3.org/2001/XMLSchema-instance');
            // Do not let document prefixes replace our fixed taxonomy namespaces.
            $sgeb=$xp->query('//g:genInfo.report.id.reportElement.reportElements.SGEB[not(@i:nil="true") and not(@i:nil="1")]',null,false);
            if(($filing['data_version']??'')==='Bilanz_6_9'&&$sgeb->length>0){
                $out['later']='not_applicable_sgeb';
                $out['later_message']='Für diese Geschäftsbetriebsdarstellung stellt ELSTER keine spätere E-Bilanz-Rückmeldung bereit. Steuerbescheide gesondert prüfen.';
            }else{
                $out['later']='eligibility_review';
                $out['later_message']='Rückübermittlung geänderter Steuerwerte gesondert prüfen. Eine ausbleibende Rückmeldung ist keine Bestätigung.';
            }
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        return $out;
    }
}
