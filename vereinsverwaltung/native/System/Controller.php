<?php declare(strict_types=1);
namespace Kulturhus\Native\System;

final class Controller
{
    public const ACTIONS=['system-task-save','system-task-request','system-notice-ack'];
    private array $original=[];
    public function __construct(private readonly \PDO $db,private readonly Authority $authority,private readonly PrivateFiles $private,private readonly string $csrf){}
    public function authorize():void
    {try{$this->authority->require();}catch(\DomainException){throw new RequestRejected('Keine Berechtigung für die Systemverwaltung.',403);}}
    private static function scalar(array $post,string $key):string{if(!is_string($post[$key]??null))throw new RequestRejected('Systemeingabe prüfen.');return $post[$key];}
    private static function counter(array $post,string $key):int{$value=self::scalar($post,$key);if(!preg_match('/^[0-9]{1,10}$/D',$value)||(float)$value>2147483646)throw new RequestRejected('Ursprüngliche Systemversion prüfen.');return (int)$value;}
    private function accepted(string $notice):string{$this->original=[];return $notice;}
    public function action(array $post,array $query=[]):string
    {
        $this->original=SystemForm::capture($post);$this->authorize();
        if($this->csrf===''||!is_string($post['csrf']??null)||!hash_equals($this->csrf,$post['csrf']))throw new RequestRejected('Aktuelles Systemformular erneut öffnen.',403);
        SystemForm::query($query);$action=self::scalar($post,'action');if(!in_array($action,self::ACTIONS,true))throw new RequestRejected('Bekannte Systemaktion auswählen.');
        $keys=$action==='system-notice-ack'?['action','csrf','event']:($action==='system-task-save'?['action','csrf','task','version','enabled','interval']:['action','csrf','task','version']);if(array_diff(array_keys($post),$keys))throw new RequestRejected('Systemformular enthält unbekannte Eingaben.');
        try{
            if($action==='system-notice-ack'){(new Journal($this->db,$this->authority))->acknowledge(self::scalar($post,'event'),time());return $this->accepted('Betriebshinweis für dein Konto bestätigt.');}
            $tasks=new Tasks($this->db,$this->authority);$task=self::scalar($post,'task');$version=self::counter($post,'version');
            if($action==='system-task-request'){$tasks->request($task,$version,time());return $this->accepted('Lokale Aufgabe für den privaten Dienst angefordert.');}
            $enabled=array_key_exists('enabled',$post)?$post['enabled']:'0';if(!is_string($enabled)||!in_array($enabled,['0','1'],true))throw new RequestRejected('Zeitplan ausdrücklich aktivieren oder pausieren.');$tasks->save($task,$version,$enabled==='1',self::counter($post,'interval'),time());return $this->accepted('Zeitplan gespeichert.');
        }catch(\DomainException $error){throw $error;}catch(\InvalidArgumentException){throw new RequestRejected('Systemeingaben prüfen.');}catch(\Throwable $error){throw new OperationUnconfirmed($error);}
    }
    public function render(array $query=[],string $notice='',string $error='',?array $draft=null,array $messages=[],string $locale='de'):string
    {$this->authorize();return View::render($this->db,$this->authority,$this->private,$this->csrf,$query,$notice,$error,$error!==''?($draft??$this->original):[],$messages,$locale);}
}
