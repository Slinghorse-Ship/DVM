<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Presentation only: never changes the official schema, financial values or validation. */
final class TaxFormPresentation
{
    public static function sectionLabel(string $name):string
    {
        return ['KSt1'=>'Verein','Gem'=>'Gemeinnützigkeit','GK'=>'Gewinnermittlung','ZVE'=>'Steuerliches Einkommen','Vorsatz'=>'Steuernummer & Absender','Allg'=>'Allgemeine Angaben','Allgemein'=>'Allgemeine Angaben','Steuerfall'=>'Steuerangaben','DatenLieferant'=>'Datenlieferant','Belege'=>'Nachweise','Persoenliche_Daten'=>'Angaben zum Verein','AntragFuerVZ'=>'Zeitraum & beantragte Frist','Steuerpflichtiger'=>'Angaben zum Verein'][$name]??$name;
    }
    public static function primarySections(string $family,array $scope):array
    {
        if($family==='kst')return array_merge(['KSt1','Gem','Vorsatz'],($scope['answers']['economic']??'unknown')==='no'?[]:['GK','ZVE']);
        return [];
    }
    public static function fieldPriority(string $section,array $field):int
    {
        if($section==='KSt1')return preg_match('~^KSt1/(Allgemein|Steuerpflicht|Rechtsform|Steuerbefreiung|Wirtschaftsjahr)/~',$field['path'])?0:1;
        if($section==='Gem')return preg_match('~^Gem/(Mild|Wohlfahrt|Krankenhaus|Sport)/~',$field['path'])?1:0;
        return 0;
    }
    public static function errors(array $validation):array
    {
        $xml=$validation['response_xml']??'';
        if(!is_string($xml)||strlen($xml)>4194304||preg_match('/<!\s*(DOCTYPE|ENTITY)/i',$xml))return [];
        $d=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        try{if(!$d->loadXML($xml,LIBXML_NONET))return [];$out=[];
            foreach($d->getElementsByTagName('FehlerRegelpruefung') as $error){$text=$error->getElementsByTagName('Text')->item(0)?->textContent??'';$field=$error->getElementsByTagName('Feldidentifikator')->item(0)?->textContent??'';
                if($text!=='')$out[]=['text'=>preg_replace('/\$\/Elster[^$]+\$/','dem genannten Formularfeld',$text),'field'=>$field];
            }return array_slice($out,0,100);
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
