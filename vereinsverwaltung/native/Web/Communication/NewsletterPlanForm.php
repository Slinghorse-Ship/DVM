<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
final class NewsletterPlanForm
{
    public static function number(mixed $value,int $max=4294967295):int
    {
        try{return TemplateFormState::number($value,$max);}catch(DomainException){throw new DomainException('Planangaben prüfen.',422);}
    }
    public static function flag(mixed $value):bool
    {
        if(!in_array($value,[null,false,true,0,1,'0','1','on'],true))throw new DomainException('Auswahl zum Plan prüfen.',422);return in_array($value,[true,1,'1','on'],true);
    }
    public static function period(mixed $value):string
    {
        if(!is_string($value)||!in_array($value,['calendar','month','quarter'],true))throw new DomainException('Newsletterplan erneut öffnen.',422);return$value;
    }
    public static function hidden(string $name,mixed $value):string
    {
        if(is_array($value)){$h='';foreach($value as$key=>$part)$h.=self::hidden($name.'['.$key.']',$part);return$h;}
        return \Kulturhus\Native\Web\Html::hidden($name,is_scalar($value)?(string)$value:'');
    }
}
