<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;
use DomainException;
use Kulturhus\Verein\SettingsSchema;
final class ReminderText
{
    private static function resolve(string $text,array $values):string
    {
        preg_match_all('/\{\{([A-Z_]+)\}\}/',$text,$matches);$missing=array_values(array_unique(array_filter($matches[1],static fn(string $key):bool=>!array_key_exists($key,$values))));if($missing)throw new DomainException('Für diese Originalrechnung fehlen nachweisbare Erinnerungswerte: '.implode(', ',$missing).'. Ganze Texte gezielt berichtigen.',422);
        return str_replace(["\r\n","\r"],"\n",SettingsSchema::interpolate($text,$values));
    }
    public static function body(array $context,array $raw):array
    {
        $input=ReminderInput::normalize($raw);if($input['invoice']!==$context['invoice']['id']||$input['day']!==$context['day'])throw new DomainException('Genau diese Originalrechnung und dieses Erinnerungsdatum erneut prüfen.',409);
        $text=[];foreach(ReminderInput::FIELDS as$field)$text[$field]=self::resolve($input['text'][$field],$context['values']);if(trim($text['subject'])===''||mb_strlen($text['subject'])>300||preg_match('/[\r\n]/',$text['subject']))throw new DomainException('Ganzen aufgelösten Erinnerungsbetreff einzeilig mit höchstens 300 Zeichen prüfen.',422);
        $body=implode("\n\n",array_values(array_filter([$text['intro'],$text['message'],$text['closing']],static fn(string $part):bool=>$part!=='')));$body.="\n\nVerwendungszweck bei Überweisung (Rechnungsnummer): ".$context['invoice']['number']."\nOffener ursprünglicher Rechnungsbetrag: ".$context['values']['ZAHLBETRAG'];
        return ['input'=>$input,'subject'=>$text['subject'],'message'=>$body];
    }
    public static function frame(array $context,array $raw):array
    {
        $shown=self::body($context,$raw);$input=$shown['input'];$text=['subject'=>$shown['subject']];$body=$shown['message'];
        $document=['kind'=>'letter','title'=>$text['subject'],'date'=>(new \DateTimeImmutable($input['day']))->format('d.m.Y'),'recipient'=>$context['recipient'],'text'=>['message'=>$body],'values'=>$context['values'],'paper'=>$context['paper'],'assets'=>$context['assets']];
        $attachments=$input['attach_original']==='1'?[['name'=>$context['file']['name'],'mime'=>$context['file']['mime'],'bytes'=>$context['original']['original_pdf']]]:[];
        $html=SettingsSchema::html($body);return compact('input','document','attachments','html')+['subject'=>$text['subject'],'message'=>$body];
    }
}
