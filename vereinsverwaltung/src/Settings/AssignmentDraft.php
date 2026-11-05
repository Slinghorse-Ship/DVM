<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;

/** Private unsaved assignment proposal and independent source-choice windows. */
final class AssignmentDraft
{
    private static function scope(array $input,string $tab):string
    {
        CatalogRepository::kinds($tab);
        if(($input['tab']??null)!==$tab||!in_array($input['operation']??null,['create','end'],true))throw new DomainException('Eigene ursprüngliche Tarifzuweisung auswählen.',422);
        $id=CatalogInput::id($input['id']??null,$input['operation']==='create');
        if(($input['operation']==='create')!==($id===''))throw new DomainException('Ursprüngliche Zuweisung und Fassung gemeinsam prüfen.',422);
        return'assignment:'.$tab.':'.$input['operation'].':'.$id;
    }
    private static function choices(array $draft):array
    {
        $draft['choices']??=['catalog'=>['query'=>'','page'=>'1'],'contact'=>['query'=>'','page'=>'1']];
        return$draft;
    }
    public static function open(array &$session,array $actor,string $tab,array $input,bool $fresh=false):array
    {
        $draft=self::choices(GeneralDraft::open($session,$actor,self::scope($input,$tab),$input,$fresh));
        $session['general_settings_drafts'][$draft['id']]=$draft;return$draft;
    }
    public static function get(array $session,array $actor,string $tab,mixed $id):array
    {
        $stored=is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/D',$id)?($session['general_settings_drafts'][$id]??null):null;
        if(!is_array($stored)||!is_array($stored['input']??null))throw new DomainException('Eigenen ursprünglichen Zuweisungsentwurf öffnen.',404);
        return self::choices(GeneralDraft::get($session,$actor,self::scope($stored['input'],$tab),$id));
    }
    public static function replace(array $draft,array $input,bool $newKey=false):array
    {
        return!$newKey&&$draft['input']===$input?$draft:GeneralDraft::replace($draft,$input,$newKey);
    }
}
