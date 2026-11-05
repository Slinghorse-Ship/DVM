<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\MemberCustomFields;

/** Unsaved row operations never change current definitions or accepted originals. */
final class GeneralDraft
{
    public static function initial(string $section,array $state):array
    {
        $data=$state['data'];if($section==='fields')foreach($data['rows']as&$row)$row['id']=MemberCustomFields::id($row);unset($row);
        return['version'=>(string)$state['version'],'data'=>$data];
    }
    public static function open(array &$session,array $actor,string $section,array $input,bool $fresh=false):array
    {
        $slot=hash('sha256',$actor['club_id']."\0".$actor['id']."\0".$section);$id=$session['general_settings_slots'][$slot]??null;
        if(!$fresh&&is_string($id)&&isset($session['general_settings_drafts'][$id])){$draft=self::get($session,$actor,$section,$id);if($draft['review']===null||$draft['pending'])return$draft;}
        $done=array_keys(array_filter($session['general_settings_drafts']??[],static fn(array $row):bool=>$row['accepted']!==null));foreach(array_slice($done,0,max(0,count($done)-20))as$old)unset($session['general_settings_drafts'][$old]);
        if(count(array_filter($session['general_settings_drafts']??[],static fn(array $row):bool=>$row['accepted']===null))>=100)throw new DomainException('Zu viele offene Einstellungsentwürfe. Einen vorhandenen Entwurf öffnen oder verwerfen.',422);
        $draft=['id'=>bin2hex(random_bytes(16)),'actor'=>$actor['id'],'club'=>$actor['club_id'],'section'=>$section,'slot'=>$slot,'key'=>bin2hex(random_bytes(16)),'input'=>$input,'review'=>null,'previous'=>[],'pending'=>false,'conflicted'=>false,'accepted'=>null];
        $session['general_settings_slots'][$slot]=$draft['id'];$session['general_settings_drafts'][$draft['id']]=$draft;return$draft;
    }
    public static function get(array $session,array $actor,string $section,mixed $id):array
    {
        $draft=is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)?($session['general_settings_drafts'][$id]??null):null;
        if(!is_array($draft)||$draft['actor']!==$actor['id']||$draft['club']!==$actor['club_id']||$draft['section']!==$section)throw new DomainException('Eigenen ursprünglichen Einstellungsentwurf öffnen.',404);
        return$draft;
    }
    public static function replace(array $draft,array $input,bool $newKey=false):array
    {
        if($newKey||($draft['review']!==null&&$draft['input']!==$input)){
            $draft['previous'][]=['key'=>$draft['key'],'input'=>$draft['input'],'review'=>$draft['review']];$draft['key']=bin2hex(random_bytes(16));
        }
        $draft['input']=$input;$draft['review']=null;$draft['conflicted']=false;return$draft;
    }
    public static function row(string $section,array $input,string $action):array
    {
        if(!in_array($section,['salutations','fields'],true)||!is_array($input['data']['rows']??null))throw new DomainException('Gültige ungespeicherte Zeilenaktion auswählen.',422);
        $rows=$input['data']['rows'];if(count($rows)>($section==='salutations'?30:50))throw new DomainException('Zu viele oder ungültige Zeilen.',422);$rows=array_values($rows);
        if($action==='add'){
            if(count($rows)>=($section==='salutations'?30:50))throw new DomainException('Die höchste Zeilenzahl ist erreicht.',422);
            $rows[]=$section==='salutations'?['name'=>'','personal'=>'','formal'=>'']:['id'=>bin2hex(random_bytes(16)),'name'=>'','type'=>'text','options'=>'','required'=>'0'];
        }else{
            if(!preg_match('/\A(remove|up|down):([0-9]{1,2})\z/D',$action,$parts)||!array_key_exists((int)$parts[2],$rows))throw new DomainException('Gültige ungespeicherte Zeilenaktion auswählen.',422);$index=(int)$parts[2];
            if($parts[1]==='remove')array_splice($rows,$index,1);
            else{$other=$index+($parts[1]==='up'?-1:1);if(!array_key_exists($other,$rows))throw new DomainException('Gültige ungespeicherte Zeilenaktion auswählen.',422);[$rows[$index],$rows[$other]]=[$rows[$other],$rows[$index]];}
        }
        $input['data']['rows']=$rows;return$input;
    }
    public static function discard(array &$session,array $draft):void
    {
        unset($session['general_settings_drafts'][$draft['id']]);if(($session['general_settings_slots'][$draft['slot']]??null)===$draft['id'])unset($session['general_settings_slots'][$draft['slot']]);
    }
}
