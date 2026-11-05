<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Contributions;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Whole conscious outbox decisions, separate from annual scheduling. */
final class ContributionDeliveryInput
{
    public const OPERATIONS=['release','cancel','hold','confirm_sent','confirm_not_sent'];
    public static function initial(string $job):array
    {
        return ['job'=>$job,'operation'=>'','data'=>['reason'=>'','evidence'=>'','sent_at'=>'','sent_offset'=>'']];
    }
    public static function capture(mixed $raw):array
    {
        if(!is_array($raw)||array_diff(array_keys($raw),['job','operation','data'])||count($raw)!==3||!is_array($raw['data']??null)||array_diff(array_keys($raw['data']),['reason','evidence','sent_at','sent_offset'])||count($raw['data'])!==4)throw new DomainException('Ganze Angaben zur Beitragsmail übermitteln.',422);
        foreach([$raw['job'],$raw['operation'],...array_values($raw['data'])]as$value)if(!is_string($value)||preg_match('//u',$value)!==1||strlen($value)>40000)throw new DomainException('Ganze gültige Textangaben zur Beitragsmail übermitteln.',422);
        return ['job'=>$raw['job'],'operation'=>$raw['operation'],'data'=>['reason'=>$raw['data']['reason'],'evidence'=>$raw['data']['evidence'],'sent_at'=>$raw['data']['sent_at'],'sent_offset'=>$raw['data']['sent_offset']]];
    }
    public static function normalize(array $raw):array
    {
        $raw=self::capture($raw);$data=$raw['data'];
        if($raw['job']===''||mb_strlen($raw['job'],'UTF-8')>128||preg_match('/[\x00-\x1f\x7f]/',$raw['job'])||!in_array($raw['operation'],self::OPERATIONS,true))throw new DomainException('Genau einen vorhandenen Beitragsmailauftrag und eine angebotene Aktion auswählen.',422);
        foreach(['reason','evidence']as$field)if(mb_strlen($data[$field],'UTF-8')>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$data[$field]))throw new DomainException('Grund und Nachweis mit jeweils höchstens 2.000 vollständigen Zeichen prüfen.',422);
        $reason=trim($data['reason']);$evidence=trim($data['evidence']);if($reason==='')throw new DomainException('Einen eigenen Grund für genau diese Auftragsentscheidung angeben.',422);
        if(in_array($raw['operation'],['confirm_sent','confirm_not_sent'],true)&&$evidence==='')throw new DomainException('Den tatsächlich geprüften Nachweis zum Versandausgang angeben.',422);
        $sent=null;
        if($raw['operation']==='confirm_sent'){
            if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/D',$data['sent_at'])||!in_array($data['sent_offset'],['','+01:00','+02:00'],true))throw new DomainException('Bestätigten Versandzeitpunkt vollständig als Ortszeit Berlin angeben.',422);
            $zone=new DateTimeZone('Europe/Berlin');$choices=[];
            foreach(['+01:00','+02:00']as$offset){$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:iP',$data['sent_at'].$offset);if($date&&$date->setTimezone($zone)->format('Y-m-d\TH:iP')===$data['sent_at'].$offset)$choices[$offset]=$date;}
            if(!$choices)throw new DomainException('Diese Berliner Ortszeit existiert nicht. Tatsächlichen Versandzeitpunkt prüfen.',422);
            if($data['sent_offset']===''&&count($choices)>1)throw new DomainException('Diese Ortszeit kommt bei der Zeitumstellung zweimal vor. Sommer- oder Winterzeit ausdrücklich auswählen.',422);
            $date=$data['sent_offset']===''?reset($choices):($choices[$data['sent_offset']]??null);
            if(!$date||$date>new DateTimeImmutable('now',new DateTimeZone('UTC')))throw new DomainException('Tatsächlich bestätigten vergangenen Versandzeitpunkt und Zeitumstellung prüfen.',422);
            $sent=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:00\Z');
        }
        return ['job'=>$raw['job'],'operation'=>$raw['operation'],'reason'=>$reason,'evidence'=>$evidence,'sent_at'=>$sent];
    }
}
