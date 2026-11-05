<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Native\Integration\ManagerAccess;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Permissions;

/** Six current owned suggestions; a rejected page never substitutes a valid page. */
final class RoleChoices
{
    public function __construct(private PDO $db,private string $actorId,private ?ManagerAccess $access=null){}

    public function listing(string $kind,array $input,string $selected=''):array
    {
        $prefix=$kind==='role'?'role_pick':'profile_pick';$query=$input[$prefix.'_q']??'';$rawPage=$input[$prefix.'_page']??'1';$page=is_string($rawPage)||is_int($rawPage)?(string)$rawPage:'';
        $state=['rows'=>[],'selected'=>null,'selection'=>$selected,'unavailable'=>false,'query'=>is_string($query)?$query:'','page_input'=>$page,'page'=>null,'pages'=>null,'total'=>null,'error'=>'','prefix'=>$prefix];
        $owned=!$this->db->inTransaction();
        try{
            if($owned)$this->db->exec('START TRANSACTION');
            $actor=Permissions::actor($this->db,$this->actorId);
            if($actor['role']==='editor'||!$actor['settings_access']||!in_array($kind,['role','profile'],true))throw new DomainException('Eigene Rollenverwaltung erforderlich.',403);
            if($kind==='profile'){if(!$this->access||$this->access->managerActor()!==$this->actorId)throw new DomainException('Persönliche native Kontoverwaltung erforderlich.',403);$this->access->require('accounts','manage');}
            $base=$kind==='role'?' FROM settings_roles WHERE club_id=?':" FROM contacts WHERE club_id=? AND kind='person' AND archived_at IS NULL";
            if($selected!==''&&!in_array($selected,['__base__','__keep__'],true)){
                $state['selected']=Db::run($this->db,'SELECT id,name'.$base.' AND id=? FOR SHARE NOWAIT',[$actor['club_id'],$selected])->fetch()?:null;
                $state['unavailable']=$state['selected']===null;
            }
            if(!is_string($query)||preg_match('//u',$query)!==1||mb_strlen($query)>150||preg_match('/[\x00-\x1f\x7f]/',$query))$state['error']='Rollensuche mit höchstens 150 Zeichen eingeben.';
            elseif(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))$state['error']='Verfügbare Auswahlseite auswählen.';
            else{
                $where=$base;$args=[$actor['club_id']];if($query!==''){$where.=' AND LOCATE(LOWER(?),LOWER(name))>0';$args[]=$query;}
                $state['total']=(int)Db::run($this->db,'SELECT COUNT(*)'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$state['pages']=max(1,(int)ceil($state['total']/6));
                if((int)$page>$state['pages'])$state['error']='Verfügbare Auswahlseite auswählen.';
                else{$state['page']=(int)$page;$state['rows']=Db::run($this->db,'SELECT id,name'.$where.' ORDER BY name,id LIMIT 6 OFFSET '.(((int)$page-1)*6).' FOR SHARE NOWAIT',$args)->fetchAll();}
            }
            if($owned)$this->db->commit();return$state;
        }catch(\Throwable $failure){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw$failure;}
    }
}
