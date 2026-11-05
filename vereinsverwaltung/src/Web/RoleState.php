<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Native\Integration\{AccountIdentity,ManagerAccess};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{AdmissionVotes,BoardAccess,EditorialPolicy,Permissions,RoleCatalogue,WebsiteWorkspace};
use Kulturhus\Verein\Settings\RoleRequests;

/** One current private role workspace; rejected controls never select a substitute row. */
final class RoleState
{
    public function __construct(private PDO $db,private string $actorId,private ?ManagerAccess $access=null,private ?AccountIdentity $identity=null){}

    private static function selection(array $query,string $field):string
    {
        $value=$query[$field]??'';
        if(!is_string($value)||preg_match('//u',$value)!==1||mb_strlen($value)>128||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche eigene Rolle oder Konto auswählen.',422);
        return$value;
    }

    private function page(RoleCatalogue $catalogue,string $kind,array $query):array
    {
        $prefix=$kind==='roles'?'roles':'role_users';$search=$query[$prefix.'_q']??'';$rawPage=$query[$prefix.'_page']??'1';$page=is_string($rawPage)||is_int($rawPage)?(string)$rawPage:'';$error='';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search)>150||preg_match('/[\x00-\x1f\x7f]/',$search))$error='Rollensuche mit höchstens 150 Zeichen eingeben.';
        if(!preg_match('/\A[1-9][0-9]{0,5}\z/D',$page))$error='Verfügbare Rollen- oder Kontenseite auswählen.';
        $result=['rows'=>[],'total'=>null,'pages'=>null,'page'=>null,'page_input'=>$page,'query'=>is_string($search)?$search:'','q'=>is_string($search)?$search:'','per_page'=>25,'error'=>$error];
        if($error!=='')return$result;
        $first=$catalogue->$kind([$prefix.'_q'=>$search,$prefix.'_page'=>'1'],25);
        $result['total']=$first['total'];$result['pages']=$first['pages'];
        if((int)$page>$first['pages']){$result['error']='Verfügbare Rollen- oder Kontenseite auswählen.';return$result;}
        $found=(int)$page===1?$first:$catalogue->$kind([$prefix.'_q'=>$search,$prefix.'_page'=>$page],25);
        $result['rows']=$found['rows'];$result['page']=(int)$page;
        return$result;
    }

    public function prepare(array $query):array
    {
        $owned=!$this->db->inTransaction();
        $state=['actor'=>null,'roles'=>null,'accounts'=>null,'selected_role'=>null,'selected_user'=>null,'role_input'=>'','user_input'=>'','new_role'=>false,'board'=>null,'identity'=>null,'effective'=>null,'can_board'=>false,'can_native'=>false,'receipt'=>null,'receipt_input'=>is_string($query['role_receipt']??null)?$query['role_receipt']:'','history'=>null,'errors'=>[],'http_status'=>0];
        try{
            if($owned)$this->db->exec('START TRANSACTION');
            $actor=Permissions::actor($this->db,$this->actorId);
            if($actor['role']==='editor'||!$actor['settings_access'])throw new DomainException('Eigene Rollenverwaltung erforderlich.',403);
            $state['actor']=$actor;$catalogue=new RoleCatalogue($this->db,$this->actorId);
            $part=static function(string $field,callable $read)use(&$state):mixed{
                try{return$read();}catch(\Throwable $failure){
                    $state['errors'][$field]=$failure instanceof DomainException?$failure->getMessage():'Rollenbereich konnte nicht geladen werden.';
                    $state['http_status']=max($state['http_status'],$failure instanceof DomainException?(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403):500);
                    return null;
                }
            };
            $state['roles']=$part('roles',fn():array=>$this->page($catalogue,'roles',$query));
            $state['accounts']=$part('accounts',fn():array=>$this->page($catalogue,'accounts',$query));
            foreach(['roles','accounts']as$field)if(($state[$field]['error']??'')!==''){$state['errors'][$field]=$state[$field]['error'];$state['http_status']=max($state['http_status'],422);}
            $state['role_input']=$part('selected_role',fn():string=>self::selection($query,'edit'))??'';
            $state['user_input']=$part('selected_user',fn():string=>self::selection($query,'role_user'))??'';
            if(array_key_exists('new_role',$query)){
                if($query['new_role']!=='1'){$state['errors']['selected_role']='Gültigen neuen Rollenentwurf öffnen.';$state['http_status']=max($state['http_status'],422);}
                else $state['new_role']=true;
            }
            if($state['new_role']&&$state['role_input']!==''){$state['new_role']=false;$state['errors']['selected_role']='Neuen Rollenentwurf oder bestehende Rolle getrennt auswählen.';$state['http_status']=max($state['http_status'],422);}
            if($state['role_input']!=='')$state['selected_role']=$part('selected_role',fn():array=>$catalogue->role($state['role_input']));
            if($state['user_input']!=='')$state['selected_user']=$part('selected_user',fn():array=>$catalogue->account($state['user_input']));
            $state['can_board']=BoardAccess::superuser($this->db,$this->actorId);
            if($state['can_board']&&$this->access&&$this->identity){
                $state['can_native']=$part('identity',function():bool{$this->access->require('accounts','manage');return$this->access->managerActor()===$this->actorId;})??false;
            }
            if($state['selected_user']){
                $user=$state['selected_user'];
                $state['effective']=$part('effective',function()use($user):array{
                    if(!(bool)$user['active'])return['available'=>false,'actor'=>null,'website'=>[]];
                    $current=Permissions::actor($this->db,$user['id']);$website=[];
                    foreach(EditorialPolicy::AREAS as$area=>$label)$website[$area]=WebsiteWorkspace::effectiveLevel($this->db,$current,$area);
                    return['available'=>true,'actor'=>$current,'website'=>$website];
                });
                if($state['can_board']&&$user['role']!=='editor')$state['board']=$part('board',function()use($user,$actor):array{
                    $row=Db::run($this->db,'SELECT actor_id,office,enabled,version FROM admission_board WHERE club_id=? AND actor_id=? FOR SHARE NOWAIT',[$actor['club_id'],$user['id']])->fetch()?:null;
                    $checks=$row?Db::run($this->db,'SELECT board_version,office,checked_by,checked_at FROM admission_board_checks WHERE club_id=? AND actor_id=? AND board_version=? ORDER BY office FOR SHARE NOWAIT',[$actor['club_id'],$user['id'],$row['version']])->fetchAll():[];
                    return['row'=>$row,'checks'=>$checks,'offices'=>AdmissionVotes::OFFICES];
                });
                if($state['can_native'])$state['identity']=$part('identity',function()use($user):?array{$rows=$this->identity->directory($user['id']);return$rows[0]??null;});
            }
            $requests=new RoleRequests($this->db,$this->actorId,$this->access,$this->identity);
            $state['history']=$part('history',fn():array=>$requests->history(['query'=>$query['role_receipts_query']??'','page'=>$query['role_receipts_page']??'1']));
            if($state['history']===null)$state['history']=['rows'=>[],'total'=>null,'pages'=>null,'page'=>null,'page_input'=>is_string($query['role_receipts_page']??'1')||is_int($query['role_receipts_page']??'1')?(string)($query['role_receipts_page']??'1'):'','query'=>is_string($query['role_receipts_query']??'')?($query['role_receipts_query']??''):'','per_page'=>25,'error'=>$state['errors']['history']??''];
            if(($state['history']['error']??'')!==''){$state['errors']['history']=$state['history']['error'];$state['http_status']=max($state['http_status'],422);}
            if(array_key_exists('role_receipt',$query)){
                if(!is_string($query['role_receipt'])||$query['role_receipt']===''){$state['errors']['receipt']='Ursprüngliche Änderungsbestätigung auswählen.';$state['http_status']=max($state['http_status'],422);}
                else $state['receipt']=$part('receipt',fn():array=>$requests->receipt($query['role_receipt']));
            }
            if($owned)$this->db->commit();
        }catch(\Throwable $failure){
            if($owned&&$this->db->inTransaction())$this->db->rollBack();
            foreach(['actor','roles','accounts','selected_role','selected_user','board','identity','effective','receipt','history']as$field)$state[$field]=null;
            $state['new_role']=false;$state['can_board']=false;$state['can_native']=false;
            $state['errors']['workspace']=$failure instanceof DomainException?$failure->getMessage():'Rollenverwaltung konnte nicht geladen werden.';
            $state['http_status']=$failure instanceof DomainException?(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403):500;
        }
        return$state;
    }
}
