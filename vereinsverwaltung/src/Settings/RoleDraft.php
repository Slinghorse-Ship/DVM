<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use Kulturhus\Verein\EditorialPolicy;

/** Session drafts keep original proposals separate from deliberate current reviews. */
final class RoleDraft
{
    public static function role(?array $role):array
    {
        $data=['name'=>$role['name']??'','description'=>$role['description']??''];
        foreach(RoleInput::RIGHTS as$field)$data[$field]=$role[$field]??'none';
        $data['settings_access']=!empty($role['settings_access'])?'1':'0';
        $data['website_custom']=($role['website_access']??null)!==null?'1':'0';
        $levels=($role['website_access']??null)!==null?json_decode($role['website_access'],true,32,JSON_THROW_ON_ERROR):[];
        foreach(EditorialPolicy::AREAS as$area=>$label)$data['website_levels'][$area]=$levels[$area]??EditorialPolicy::level($role??$data,$area);
        return['id'=>$role['id']??'','version'=>(string)($role['version']??0),'data'=>$data];
    }

    public static function open(array &$session,array $actor,string $operation,array $input,bool $fresh=false):array
    {
        $target=$input['id']??$input['user']??$input['account']??'';
        $slot=hash('sha256',$actor['club_id']."\0".$actor['id']."\0".$operation."\0".(is_string($target)?$target:serialize($target)));
        $id=$session['role_draft_slots'][$slot]??'';
        if(!$fresh&&is_string($id)&&isset($session['role_drafts'][$id])){
            $old=self::get($session,$actor,$id);
            if($old['review']===null||$old['pending'])return$old;
        }
        $accepted=array_keys(array_filter($session['role_drafts']??[],static fn(array $row):bool=>$row['accepted']!==null));
        foreach(array_slice($accepted,0,max(0,count($accepted)-20))as$oldId)unset($session['role_drafts'][$oldId]);
        if(count(array_filter($session['role_drafts']??[],static fn(array $row):bool=>$row['accepted']===null))>=100)throw new DomainException('Zu viele offene Rollenentwürfe. Einen vorhandenen Entwurf öffnen oder verwerfen.',422);
        $draft=['id'=>bin2hex(random_bytes(16)),'club'=>$actor['club_id'],'actor'=>$actor['id'],'operation'=>$operation,'input'=>$input,'key'=>bin2hex(random_bytes(16)),'review'=>null,'previous'=>[],'pending'=>false,'conflicted'=>false,'accepted'=>null,'slot'=>$slot,'query'=>[]];
        $session['role_draft_slots'][$slot]=$draft['id'];$session['role_drafts'][$draft['id']]=$draft;return$draft;
    }

    public static function get(array $session,array $actor,mixed $id):array
    {
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Eigenen ursprünglichen Rollenentwurf öffnen.',404);
        $draft=$session['role_drafts'][$id]??null;
        if(!is_array($draft)||$draft['actor']!==$actor['id']||$draft['club']!==$actor['club_id'])throw new DomainException('Eigenen ursprünglichen Rollenentwurf öffnen.',404);
        return$draft;
    }

    public static function issues(array $plan):array
    {
        $input=$plan['input'];$context=$plan['context'];$operation=$plan['operation'];$issues=[];
        $version=match($operation){'role-save'=>$context['subject']['version']??0,'role-assign'=>$context['target']['version'],'board-grant'=>$context['subject']['board']['version']??0,default=>$context['subject']['version']};
        if((string)$version!==$input['version'])$issues[]='Die ursprüngliche Fassung ist nicht mehr aktuell.';
        if($operation==='identity-assign'&&((string)$context['subject']['role_version']!==$input['role_version']||($context['subject']['contact_id']??'')!==$input['expected_contact']))$issues[]='Die ursprüngliche persönliche Zuordnung ist nicht mehr aktuell.';
        if($operation==='role-save'&&$context['matching_roles'])$issues[]='Dieser Rollenname ist inzwischen vergeben.';
        if($operation==='role-assign'&&!$context['target']['active'])$issues[]='Eigenes Konto nicht aktiv.';
        if($operation==='identity-superuser'&&(!$context['subject']['verified']||!$context['subject']['active']||!$context['subject']['account_active']||$context['subject']['role']==='editor'))$issues[]='Aktiven persönlichen Zugang außerhalb der getrennten Redaktions-Grundrolle auswählen.';
        if($operation==='role-save'&&$input['data']['website_custom']==='0'){
            foreach(EditorialPolicy::AREAS as$area=>$label)if($input['data']['website_levels'][$area]!==EditorialPolicy::level($input['data'],$area)){$issues[]='Website-Voreinstellungen vor dem Speichern ausdrücklich einsetzen oder eigene Freigaben wählen.';break;}
        }
        return$issues;
    }

    /** An explicit defaults action fills only its six website choices after validating all other input. */
    public static function defaults(array $input):array
    {
        if(!is_array($input['data']??null))throw new DomainException('Vollständige ursprüngliche Rollenfelder prüfen.',422);
        $input['data']['website_custom']='0';$input['data']['website_levels']=array_fill_keys(array_keys(EditorialPolicy::AREAS),'none');
        $input=RoleInput::capture('role-save',$input);
        foreach(EditorialPolicy::AREAS as$area=>$label)$input['data']['website_levels'][$area]=EditorialPolicy::level($input['data'],$area);
        return$input;
    }

    public static function current(array $draft,array $plan):array
    {
        $draft['previous'][]=['key'=>$draft['key'],'input'=>$draft['input'],'review'=>$draft['review']];
        $input=$plan['input'];$context=$plan['context'];
        $input['version']=(string)match($draft['operation']){'role-save'=>$context['subject']['version']??0,'role-assign'=>$context['target']['version'],'board-grant'=>$context['subject']['board']['version']??0,default=>$context['subject']['version']};
        if($draft['operation']==='identity-assign'){$input['role_version']=(string)$context['subject']['role_version'];$input['expected_contact']=$context['subject']['contact_id']??'';}
        $draft['input']=$input;$draft['key']=bin2hex(random_bytes(16));$draft['review']=null;$draft['pending']=false;$draft['conflicted']=false;$draft['accepted']=null;return$draft;
    }

    /** A failed current review cannot erase the earlier offered review or reuse its key. */
    public static function failedCurrent(array $draft,array $before):array
    {
        if($draft['key']===$before['key']&&$before['review']!==null){
            $draft['previous'][]=['key'=>$before['key'],'input'=>$before['input'],'review'=>$before['review']];
            $draft['key']=bin2hex(random_bytes(16));$draft['review']=null;
        }
        return$draft;
    }

    public static function discard(array &$session,array $draft):void
    {
        unset($session['role_drafts'][$draft['id']]);
        if(($session['role_draft_slots'][$draft['slot']]??null)===$draft['id'])unset($session['role_draft_slots'][$draft['slot']]);
    }
}
