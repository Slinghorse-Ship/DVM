<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;
use DomainException;
use Kulturhus\Verein\SettingsSchema;
final class ReminderInput
{
    public const FIELDS=['subject','intro','message','closing'];
    public static function initial(array $context):array{return self::capture(['invoice'=>$context['invoice']['id'],'day'=>$context['day'],'text'=>array_intersect_key($context['template'],array_flip(self::FIELDS)),'reason'=>'','channel'=>'letter','attach_original'=>!empty($context['template']['attach_pdf'])?'1':'0']);}
    public static function capture(array $raw):array
    {
        $keys=array_keys($raw);sort($keys);if($keys!==['attach_original','channel','day','invoice','reason','text']||!is_array($raw['text']))throw new DomainException('Ganze ursprüngliche Erinnerungsangaben prüfen.',422);
        $keys=array_keys($raw['text']);sort($keys);if($keys!==['closing','intro','message','subject'])throw new DomainException('Alle ganzen ursprünglichen Erinnerungstexte prüfen.',422);
        $values=[$raw['invoice'],$raw['day'],$raw['reason'],$raw['channel'],$raw['attach_original'],...array_values($raw['text'])];foreach($values as$value)if(!is_string($value)||strlen($value)>40000||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Alle ganzen ursprünglichen Erinnerungsfelder prüfen.',422);
        return ['invoice'=>$raw['invoice'],'day'=>$raw['day'],'text'=>array_replace(array_fill_keys(self::FIELDS,''),$raw['text']),'reason'=>$raw['reason'],'channel'=>$raw['channel'],'attach_original'=>$raw['attach_original']];
    }
    public static function normalize(array $raw):array
    {
        $input=self::capture($raw);if(!preg_match('/\A[a-f0-9]{32}\z/D',$input['invoice']))throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.',404);
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$input['day']))throw new DomainException('Ganzes Erinnerungsdatum prüfen.',422);$day=\DateTimeImmutable::createFromFormat('!Y-m-d',$input['day']);if(!$day||$day->format('Y-m-d')!==$input['day'])throw new DomainException('Ganzes Erinnerungsdatum prüfen.',422);
        if(!in_array($input['channel'],['letter','mail'],true))throw new DomainException('Brief oder E-Mail ausdrücklich auswählen.',422);
        if(!in_array($input['attach_original'],['0','1'],true))throw new DomainException('Ursprüngliche PDF-Anlage ausdrücklich auswählen.',422);
        if(trim($input['reason'])===''||mb_strlen($input['reason'])>2000)throw new DomainException('Ganzen eigenen Erinnerungsgrund mit höchstens 2.000 Zeichen angeben.',422);
        foreach(self::FIELDS as$field){$value=$input['text'][$field];if(mb_strlen($value)>($field==='subject'?200:6000)||(in_array($field,['subject','message'],true)&&trim($value)==='')||($field==='subject'&&preg_match('/[\r\n]/',$value)))throw new DomainException('Ganze Erinnerungstexte innerhalb der angezeigten Grenzen prüfen.',422);SettingsSchema::formatted($value,$field==='subject'?200:6000);}
        return $input;
    }
}
