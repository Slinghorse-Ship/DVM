<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Catalogue-only filters. They never define a posting or a completeness check. */
final class BalancePeriodSourceQuery
{
    public const FIELDS=['source_kind','source_q','source_state','source_direction','source_from','source_to','source_amount','source_page'];
    public static function read(array$input,string$selectedKind='invoice'):array
    {
        $defaults=['source_kind'=>in_array($selectedKind,['invoice','transaction','depreciation'],true)?$selectedKind:'invoice','source_q'=>'','source_state'=>'all','source_direction'=>'all','source_from'=>'','source_to'=>'','source_amount'=>'','source_page'=>'1'];$q=[];
        foreach($defaults as$key=>$default){$value=$input[$key]??$default;if(!is_string($value))throw new DomainException('Belegsuche vollständig und mit einzelnen Angaben ausfüllen.',422);$q[$key]=$value;}
        if(!in_array($q['source_kind'],['invoice','transaction','depreciation'],true)||!in_array($q['source_state'],['all','open','current','other'],true)||!in_array($q['source_direction'],['all','in','out'],true))throw new DomainException('Verfügbare Belegart, Übernahme und Richtung auswählen.',422);
        if(!mb_check_encoding($q['source_q'],'UTF-8')||mb_strlen($q['source_q'])>200||preg_match('/[\x00-\x1f]/u',$q['source_q']))throw new DomainException('Suchtext mit höchstens 200 Zeichen ohne Steuerzeichen angeben.',422);
        foreach(['source_from','source_to']as$key)if($q[$key]!==''){if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$q[$key]))throw new DomainException('Gültige Datumsgrenzen für die Belegsuche auswählen.',422);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$q[$key]);if(!$date||$date->format('Y-m-d')!==$q[$key])throw new DomainException('Gültige Datumsgrenzen für die Belegsuche auswählen.',422);}
        if($q['source_from']!==''&&$q['source_to']!==''&&$q['source_from']>$q['source_to'])throw new DomainException('Der Beginn der Belegsuche muss vor ihrem Ende liegen.',422);
        if($q['source_amount']!==''){$cents=Finance::cents($q['source_amount']);if($cents<0)throw new DomainException('Für die Belegsuche einen Betrag ohne Vorzeichen angeben und die Richtung gesondert auswählen.',422);}
        if($q['source_kind']==='depreciation'&&($q['source_amount']!==''||$q['source_direction']!=='all'))throw new DomainException('Anlagenwerte ohne Betrags- und Richtungsfilter suchen.',422);
        if(!preg_match('/\A[1-9][0-9]{0,6}\z/D',$q['source_page'])||(int)$q['source_page']>1000000)throw new DomainException('Verlaufsseite der Belegsuche vollständig auswählen.',422);
        return$q;
    }
    public static function like(string$value):string{return'%'.str_replace(['~','%','_'],['~~','~%','~_'],$value).'%';}
    public static function page(array$q,int$page):array{$q['source_page']=(string)$page;return$q;}
}
