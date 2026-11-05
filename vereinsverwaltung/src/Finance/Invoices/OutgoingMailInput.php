<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DomainException;
use Kulturhus\Verein\Settings\GeneralInput;
use Kulturhus\Verein\{SettingsSchema};

/** A whole personal message is independent of the already issued financial original. */
final class OutgoingMailInput
{
    public static function initial(array $context,string $kind='queue'):array
    {
        $data=$context['defaults'];
        if($kind==='replace'&&$context['delivery']){
            $stored=OutgoingMailMessage::open($context['delivery']);
            if(!self::neutralReplacement($context,$stored))$data=['subject'=>$stored['subject_input'],'message'=>$stored['message_input']];
        }
        return self::capture(['kind'=>$kind,'invoice'=>$context['invoice']['id'],'version'=>(string)$context['invoice']['version'],'data'=>$data]);
    }
    /** An explicitly new draft after full payment does not revive the earlier payment request. */
    public static function neutralReplacement(array $context,?array $stored=null):bool
    {
        if($context['invoice']['kind']!=='invoice'||($context['payment']['open_cents']??null)!==0||!$context['delivery'])return false;
        $stored??=OutgoingMailMessage::open($context['delivery']);
        return $stored['format']==='legacy-plain'||(int)$stored['payment_open_cents']>0;
    }
    public static function capture(array $input):array
    {
        $keys=array_keys($input);sort($keys);
        if($keys!==['data','invoice','kind','version']||!in_array($input['kind'],['queue','replace'],true)||!is_string($input['invoice'])||!preg_match('/\A[a-f0-9]{32}\z/D',$input['invoice'])||!is_array($input['data']))throw new DomainException('Vollständigen eigenen Rechnungs-Mailentwurf öffnen.',422);
        $keys=array_keys($input['data']);sort($keys);if($keys!==['message','subject'])throw new DomainException('Ganzen Betreff und Nachrichtentext prüfen.',422);
        foreach($input['data']as$field=>$value)if(!is_string($value)||strlen($value)>($field==='subject'?40000:160000)||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Ganzes ursprüngliches Nachrichtenfeld prüfen.',422);
        return['kind'=>$input['kind'],'invoice'=>$input['invoice'],'version'=>(string)GeneralInput::version($input['version']),'data'=>['subject'=>$input['data']['subject'],'message'=>$input['data']['message']]];
    }
    public static function normalize(array $input):array
    {
        $input=self::capture($input);$data=$input['data'];
        if(strpbrk($data['subject'],"\r\n")!==false)throw new DomainException('Betreff ohne Zeilenumbruch eingeben.',422);
        foreach(['subject'=>320,'message'=>20000]as$field=>$max){
            $data[$field]=trim($data[$field]);
            if(mb_strlen($data[$field],'UTF-8')>$max||($field==='subject'&&$data[$field]===''))throw new DomainException($field==='subject'?'Betreff mit einem bis 320 Zeichen eingeben.':'Nachricht mit höchstens 20.000 Zeichen eingeben.',422);
            try{$data[$field]=SettingsSchema::formatted($data[$field],160000);}
            catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
        }
        return$data;
    }
}
