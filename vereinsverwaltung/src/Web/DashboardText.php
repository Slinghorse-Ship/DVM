<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DateTimeImmutable;
use DateTimeZone;
use Kulturhus\Native\Language\DashboardMessages;
use Kulturhus\Verein\Documents\PersonalDocumentText;

final class DashboardText
{
    public static function text(string $source,array $messages):string{return DashboardMessages::text($source,$messages);}
    public static function format(string $source,array $values,array $messages):string
    {
        $replace=[];foreach($values as$key=>$value)$replace['{'.$key.'}']=(string)$value;
        return strtr(self::text($source,$messages),$replace);
    }
    public static function money(int $cents,string $locale):string{return PersonalDocumentText::money($cents,$locale);}
    public static function date(string $value,string $locale,bool $time=false):string
    {
        $date=(new DateTimeImmutable($value,new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('Europe/Berlin'));
        return$date->format((explode('-',$locale,2)[0]==='en'?'Y-m-d':'d.m.Y').($time?' H:i':''));
    }
    public static function relative(int $days,array $messages):string
    {
        return match($days){0=>self::text('Heute',$messages),1=>self::text('Morgen',$messages),-1=>self::text('Gestern',$messages),default=>self::format($days<0?'vor {count} Tagen':'in {count} Tagen',['count'=>abs($days)],$messages)};
    }
    public static function initial(string $name):string
    {
        return preg_match('/\X/u',trim($name),$match)===1?mb_strtoupper($match[0]):'D';
    }
    public static function age(float $age,string $locale):string
    {
        return number_format($age,1,explode('-',$locale,2)[0]==='en'?'.':',','');
    }
    public static function anniversary(array $row,bool $systemDefault,array $messages):string
    {
        if(!$systemDefault)return$row['anniversary'];$years=intdiv((int)$row['months'],12);
        return self::format($years===1?'{count} Jahr':'{count} Jahre',['count'=>$years],$messages);
    }
}
