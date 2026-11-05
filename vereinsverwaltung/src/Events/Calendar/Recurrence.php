<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Finite civil-date recurrence. Registry validates every included local timestamp before any write. */
final class Recurrence
{
    public static function expand(array $data,array $rule):array
    {
        $zone=new DateTimeZone('UTC');$dates=[];
        foreach(['start','end']as$key){$value=$data[$key]??null;if(!is_string($value)||!preg_match('/\A(18|19|20)[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/D',$value))throw new DomainException('Beginn und Ende der Serienvorlage prüfen.');$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$zone);if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new DomainException('Ungültiges Datum in der Serienvorlage.');$dates[$key]=$date;}
        if($dates['end']<=$dates['start'])throw new DomainException('Das Ende muss nach dem Beginn liegen.');
        $frequency=$rule['frequency']??'';$every=$rule['every']??'1';$until=$rule['until']??'';
        if(!in_array($frequency,['daily','weekly','monthly'],true)||(!is_string($every)&&!is_int($every))||!preg_match('/\A[1-9][0-9]?\z/D',(string)$every)||(int)$every>52||!is_string($until)||!preg_match('/\A(18|19|20)[0-9]{2}-[0-9]{2}-[0-9]{2}\z/D',$until))throw new DomainException('Serienrhythmus, Abstand (1–52) und letztes Datum prüfen.');
        $last=DateTimeImmutable::createFromFormat('!Y-m-d',$until,$zone);if(!$last||$last->format('Y-m-d')!==$until||$until<$dates['start']->format('Y-m-d'))throw new DomainException('Das letzte Seriendatum darf nicht vor dem ersten Termin liegen.');
        $skip=$rule['skip']??'';if(is_string($skip)){if(strlen($skip)>4096)throw new DomainException('Höchstens 366 Ausnahmedaten angeben.');$skip=trim($skip)===''?[]:preg_split('/[\r\n,;]+/',trim($skip));}
        if(!is_array($skip)||!array_is_list($skip)||count($skip)>366)throw new DomainException('Ausnahmedaten prüfen.');
        foreach($skip as&$value){if(!is_string($value))throw new DomainException('Ausnahmedatum prüfen.');$value=trim($value);}unset($value);$skip=array_values(array_unique($skip));
        $anchor=$dates['start']->setTime(0,0);$duration=(int)$anchor->diff($dates['end']->setTime(0,0))->days;$slots=[];$included=0;$potential=[];
        for($index=0;$index<=366;$index++){
            $offset=$index*(int)$every;
            if($frequency==='monthly'){$month=$anchor->modify('first day of this month')->modify('+'.$offset.' months');$day=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$anchor->format('d'),(int)$month->format('t')));}
            else $day=$anchor->modify('+'.($offset*($frequency==='weekly'?7:1)).' days');
            $key=$day->format('Y-m-d');if($key>$until)break;if($index===366)throw new DomainException('Höchstens 366 mögliche Termine pro Serie; Zeitraum eingrenzen.');$potential[]=$key;$excluded=in_array($key,$skip,true);
            $slots[$index]=['start'=>$key.'T'.$dates['start']->format('H:i'),'end'=>$day->modify('+'.$duration.' days')->format('Y-m-d').'T'.$dates['end']->format('H:i'),'excluded'=>$excluded];if(!$excluded)++$included;
        }
        if(array_diff($skip,$potential))throw new DomainException('Jedes Ausnahmedatum muss zum gewählten Serienrhythmus gehören.');
        if(!$included)throw new DomainException('Mindestens einen Serientermin beibehalten.');
        return ['rule'=>['frequency'=>$frequency,'every'=>(int)$every,'until'=>$until,'skip'=>$skip],'slots'=>$slots,'count'=>$included];
    }
}
