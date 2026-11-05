<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;

/** Known safety controls only; callers escape the final text in its HTML context. */
final class HseInterfaceText
{
    public static function text(string $source,array $messages):string
    {
        return class_exists(\Kulturhus\Native\Language\HseMessages::class)
            ? \Kulturhus\Native\Language\HseMessages::text($source,$messages) : $source;
    }
    public static function format(string $source,array $values,array $messages):string
    {
        $arguments=[];
        foreach($values as$key=>$value)$arguments['{'.$key.'}']=(string)$value;
        return strtr(self::text($source,$messages),$arguments);
    }
    public static function date(string $source,string $locale,array $messages):string
    {
        if($source==='')return self::text('Ohne Frist',$messages);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$source);
        return $date&&$date->format('Y-m-d')===$source
            ? $date->format(explode('-',$locale,2)[0]==='en'?'Y-m-d':'d.m.Y') : $source;
    }
    public static function timestamp(string $source,string $locale):string
    {
        return (new \DateTimeImmutable($source,new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Berlin'))
            ->format(explode('-',$locale,2)[0]==='en'?'Y-m-d H:i':'d.m.Y H:i');
    }
}
