<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Native\Shared\SecretBox;

/** Session drafts keep only authenticated ciphertext and a redacted public intention. */
final class MailDraft
{
    private SecretBox $box;
    public function __construct(#[\SensitiveParameter] private string $key)
    {if(strlen($key)<32)throw new DomainException('Geschützter Serverschlüssel fehlt.',503);$this->box=new SecretBox(hash_hkdf('sha256',$key,32,'dvm-mail-private-draft-v1'));}
    private function context(array $draft):string
    {return 'dvm-mail-draft/1'."\0".$draft['club']."\0".$draft['actor']."\0".$draft['section']."\0".$draft['id'];}
    private function seal(array $draft,#[\SensitiveParameter] array $input):array
    {$input=MailInput::capture($draft['section'],$input);$draft['cipher']=$this->box->seal($this->context($draft),$input);$draft['input']=MailInput::redact($draft['section'],$input,$this->key,$draft['club']."\0".$draft['actor']);return $draft;}
    public function input(array $draft):array
    {if($draft['accepted']!==null)throw new DomainException('Angenommenes Postfachoriginal über seine Kennung lesen.',409);return MailInput::capture($draft['section'],$this->box->open($this->context($draft),$draft['cipher']));}
    public function open(array &$session,array $actor,string $scope,#[\SensitiveParameter] array $input,bool $fresh=false):array
    {
        MailInput::scope($scope);$slot=hash('sha256',$actor['club_id']."\0".$actor['id']."\0".$scope);$id=$session['mail_settings_slots'][$slot]??null;
        if(!$fresh&&is_string($id)&&isset($session['mail_settings_drafts'][$id])){$draft=self::get($session,$actor,$scope,$id);if($draft['accepted']===null)return $draft;}
        $done=array_keys(array_filter($session['mail_settings_drafts']??[],static fn(array $d):bool=>$d['accepted']!==null));foreach(array_slice($done,0,max(0,count($done)-20))as $old)unset($session['mail_settings_drafts'][$old]);
        if(count(array_filter($session['mail_settings_drafts']??[],static fn(array $d):bool=>$d['accepted']===null))>=100)throw new DomainException('Zu viele offene Postfachentwürfe. Einen vorhandenen Entwurf öffnen oder verwerfen.',422);
        $draft=['id'=>bin2hex(random_bytes(16)),'actor'=>$actor['id'],'club'=>$actor['club_id'],'section'=>$scope,'slot'=>$slot,'key'=>bin2hex(random_bytes(16)),'review'=>null,'previous'=>[],'pending'=>false,'conflicted'=>false,'accepted'=>null];$draft=$this->seal($draft,$input);$session['mail_settings_slots'][$slot]=$draft['id'];$session['mail_settings_drafts'][$draft['id']]=$draft;return $draft;
    }
    public static function get(array $session,array $actor,string $scope,mixed $id):array
    {
        $draft=is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)?($session['mail_settings_drafts'][$id]??null):null;
        if(!is_array($draft)||$draft['actor']!==$actor['id']||$draft['club']!==$actor['club_id']||$draft['section']!==$scope)throw new DomainException('Eigenen ursprünglichen Postfachentwurf öffnen.',404);return $draft;
    }
    public function replace(array $draft,#[\SensitiveParameter] array $input,bool $newKey=false):array
    {
        if($draft['pending']||$draft['accepted']!==null)throw new DomainException('Zuerst den ursprünglichen Postfachausgang lesen.',409);
        $next=$this->seal($draft,$input);
        if($newKey||($draft['review']!==null&&$draft['input']!==$next['input'])){$next['previous'][]=['key'=>$draft['key'],'input'=>$draft['input'],'review'=>$draft['review']];$next['key']=bin2hex(random_bytes(16));}
        $next['review']=null;$next['conflicted']=false;return $next;
    }
    public function posted(array $draft,#[\SensitiveParameter] array $posted,?string $forget=null):array
    {
        $old=$this->input($draft);$next=MailInput::capture($draft['section'],['version'=>$old['version'],'data'=>$posted]);
        if($forget!==null&&!in_array($forget,MailInput::secrets($draft['section']),true))throw new DomainException('Eigenes vorgemerktes Passwort auswählen.',422);
        foreach(MailInput::secrets($draft['section'])as $path){if($forget===$path)MailInput::put($next['data'],$path,'');elseif(MailInput::value($next['data'],$path)==='')MailInput::put($next['data'],$path,MailInput::value($old['data'],$path));}
        return $this->replace($draft,$next);
    }
    public static function accepted(array $draft,string $key):array
    {$draft['accepted']=$key;$draft['pending']=false;$draft['cipher']='';$draft['previous']=[];return $draft;}
    public static function discard(array &$session,array $draft):void
    {unset($session['mail_settings_drafts'][$draft['id']]);if(($session['mail_settings_slots'][$draft['slot']]??null)===$draft['id'])unset($session['mail_settings_slots'][$draft['slot']]);}
}
