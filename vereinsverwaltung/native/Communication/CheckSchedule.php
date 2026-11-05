<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

/** Meta runs all day; the personal app receives one random daytime check per local hour. */
final class CheckSchedule
{
    private static function hour(mixed $value,int $min,int $max):int|false
    {
        if(is_string($value)){if(!preg_match('/\A[0-9]{1,2}\z/D',trim($value)))return false;$value=(int)trim($value);}
        return is_int($value)&&$value>=$min&&$value<=$max?$value:false;
    }
    public static function config(string $channel,array $input):array
    {
        if($channel==='meta'){
            $seconds=filter_var($input['interval_seconds']??300,FILTER_VALIDATE_INT,['options'=>['min_range'=>60,'max_range'=>3600]]);
            if($seconds===false)throw new \DomainException('Meta-Abstand zwischen 60 und 3600 Sekunden wählen.');
            return ['interval_seconds'=>$seconds];
        }
        if($channel!=='stadtlandfunk')throw new \DomainException('Abrufkanal prüfen.');
        $start=self::hour($input['day_start']??8,0,23);
        $end=self::hour($input['day_end']??22,1,24);
        if($start===false||$end===false||$start>=$end)throw new \DomainException('Tagesbeginn und Tagesende mit mindestens einer Stunde wählen.');
        return ['mode'=>'hourly','day_start'=>$start,'day_end'=>$end];
    }
    /** Earlier stored windows become an hourly daytime span without rewriting the original JSON. */
    public static function stored(string $channel,array $config):array
    {
        if($channel==='meta')return self::config($channel,$config);
        if($channel!=='stadtlandfunk')throw new \DomainException('Abrufkanal prüfen.');
        if(($config['mode']??null)==='hourly')return self::config($channel,$config);
        if(array_key_exists('mode',$config))throw new \DomainException('Gespeicherten Tageszeitraum prüfen.');
        if(!is_array($config['windows']??null)||!$config['windows'])throw new \DomainException('Gespeicherten Tageszeitraum prüfen.');
        $starts=[];$ends=[];
        foreach($config['windows']as$window){
            if(!is_array($window)||array_keys($window)!==[0,1]||!is_int($window[0])||!is_int($window[1])||$window[0]<0||$window[1]>24||$window[0]>=$window[1])throw new \DomainException('Gespeicherten Tageszeitraum prüfen.');
            $starts[]=$window[0];$ends[]=$window[1];
        }
        return self::config($channel,['day_start'=>min($starts),'day_end'=>max($ends)]);
    }
    public static function hourKey(\DateTimeImmutable $time):string
    {return $time->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d').':hour-'.$time->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('H');}
    public static function currentSlot(array $config,\DateTimeImmutable $now):?string
    {
        $config=self::stored('stadtlandfunk',$config);$local=$now->setTimezone(new \DateTimeZone('Europe/Berlin'));$hour=(int)$local->format('G');
        return $hour>=$config['day_start']&&$hour<$config['day_end']?self::hourKey($local):null;
    }
    public static function next(string $channel,array $config,\DateTimeImmutable $now,array $previous=[],?string $completedSlot=null):array
    {
        $config=self::stored($channel,$config);
        if($channel==='meta')return ['at'=>$now->setTimezone(new \DateTimeZone('UTC'))->modify('+'.$config['interval_seconds'].' seconds')->format('Y-m-d H:i:s'),'slot'=>'meta','offsets'=>$previous];
        $local=$now->setTimezone(new \DateTimeZone('Europe/Berlin'));
        for($day=0;$day<3;$day++){
            $date=$local->setTime(0,0)->modify('+'.$day.' days');
            for($hour=$config['day_start'];$hour<$config['day_end'];$hour++){
                $start=$date->setTime($hour,0);if((int)$start->format('G')!==$hour)continue;
                $slot=self::hourKey($start);if($slot===$completedSlot)continue;
                // Minute precision leaves one full minute for normal hosting cron latency.
                $low=max(0,(int)ceil(($now->getTimestamp()+1-$start->getTimestamp())/60));$high=58;if($low>$high)continue;
                $old=$previous['last_hourly_minute']??null;$exclude=is_int($old)&&$old>=$low&&$old<=$high&&$high>$low;
                $minute=random_int($low,$high-($exclude?1:0));if($exclude&&$minute>=$old)$minute++;
                $previous['hourly']=1;$previous['last_hourly_minute']=$minute;
                return ['at'=>$start->setTimestamp($start->getTimestamp()+$minute*60)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'slot'=>$slot,'offsets'=>$previous];
            }
        }
        throw new \DomainException('Kein zukünftiges Abruffenster verfügbar.');
    }
}
