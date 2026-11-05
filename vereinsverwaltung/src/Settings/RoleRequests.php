<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Integration\{AccountIdentity,ManagerAccess,ManagerBridge};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{AdmissionVotes,BoardAccess,Permissions,RoleCatalogue,Settings};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** New accepted role operations bind the first complete input to its actual canonical result. */
final class RoleRequests
{
    public function __construct(private PDO $db,private string $actorId,private ?ManagerAccess $access=null,private ?AccountIdentity $identity=null){}

    private function read(callable $read):array
    {
        $owned=!$this->db->inTransaction();
        try{
            if($owned)$this->db->exec('START TRANSACTION');
            $result=$read();
            if($owned)$this->db->commit();
            return$result;
        }catch(\Throwable $failure){
            if($owned&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rollenänderung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw$failure;
        }
    }

    private static function json(array $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }

    private function available():void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_role_requests'")->fetchColumn())throw new DomainException('Rollenänderungen benötigen das aktuelle installierte Paket.',503);
    }

    private function actor(string $operation):array
    {
        if(!in_array($operation,RoleInput::OPERATIONS,true))throw new DomainException('Gültigen Rollen- oder Kontenvorgang auswählen.',422);
        $actor=Permissions::actor($this->db,$this->actorId);
        if($actor['role']==='editor')throw new DomainException('Keine Berechtigung für die Rollenverwaltung.',403);
        if(in_array($operation,['role-save','role-assign'],true)){
            if(!$actor['settings_access'])throw new DomainException('Eigene Rollenverwaltung erforderlich.',403);
        }elseif(!BoardAccess::superuser($this->db,$this->actorId))throw new DomainException('Diesen getrennten Vorgang darf nur ein aktueller Superuser bearbeiten.',403);
        if(str_starts_with($operation,'identity-')){
            if(!$this->access||!$this->identity||$this->access->managerActor()!==$this->actorId)throw new DomainException('Persönliche native Kontoverwaltung erforderlich.',403);
            $this->access->require('accounts','manage');
        }
        return$actor;
    }

    /** Session bodies require the same current operation permission as stored originals. */
    public function assertAccess(string $operation):void
    {
        $this->read(fn():array=>$this->actor($operation));
    }

    private function nativeAccount(string $id,array $actor):array
    {
        $row=Db::run($this->db,"SELECT au.id,au.login_name,au.email,au.active AS account_active,au.suspended,au.verified,au.version,i.native_id,a.id AS manager_actor,a.club_id,a.contact_id,a.active,a.role,a.is_superuser,r.role_id,COALESCE(r.version,0) AS role_version,u.name,COALESCE(NULLIF(au.login_name,''),u.name,a.id) AS account_name FROM native_auth_accounts au JOIN native_manager_identities i ON i.native_id=au.actor_id JOIN actors a ON a.id=i.manager_actor_id AND a.club_id=i.manager_club_id JOIN native_users u ON u.id=i.native_id LEFT JOIN settings_role_assignments r ON r.actor_id=a.id AND r.club_id=a.club_id WHERE au.id=? AND a.club_id=? FOR SHARE NOWAIT",[$id,$actor['club_id']])->fetch();
        if(!$row||$row['native_id']!==ManagerBridge::actorKey($row['manager_actor'],$actor['club_id']))throw new DomainException('Eigenes persönliches Konto auswählen.',403);
        if($row['manager_actor']===$actor['id'])throw new DomainException('Eigene Anmeldezuordnung und technische Rechte bleiben geschützt.',403);
        return$row;
    }

    private function board(string $user,array $actor):array
    {
        $row=Db::run($this->db,'SELECT club_id,actor_id,office,enabled,version FROM admission_board WHERE club_id=? AND actor_id=? FOR SHARE NOWAIT',[$actor['club_id'],$user])->fetch()?:null;
        $checks=$row?Db::run($this->db,'SELECT board_version,office,checked_by,checked_at FROM admission_board_checks WHERE club_id=? AND actor_id=? AND board_version=? ORDER BY office FOR SHARE NOWAIT',[$actor['club_id'],$user,$row['version']])->fetchAll():[];
        return['board'=>$row,'checks'=>$checks];
    }

    /** Only selected current metadata; no authentication factors or invitation ciphertext. */
    private function context(string $operation,array $input,array $actor):array
    {
        $catalogue=new RoleCatalogue($this->db,$this->actorId);
        $context=['subject'=>null,'target'=>null,'role'=>null,'profile'=>null,'board'=>null,'matching_roles'=>[]];
        if($operation==='role-save'){
            if($input['id']!=='')$context['subject']=$catalogue->role($input['id']);
            $context['matching_roles']=Db::run($this->db,'SELECT id,name,version FROM settings_roles WHERE club_id=? AND BINARY name=BINARY ? AND id<>? ORDER BY id FOR SHARE NOWAIT',[$actor['club_id'],RoleText::name($input['data']['name']),$input['id']])->fetchAll();
        }elseif(in_array($operation,['role-assign','board-grant'],true)){
            $context['target']=$catalogue->account($input['user']);
            $context['subject']=$operation==='board-grant'?$this->board($input['user'],$actor):$context['target'];
            if($operation==='role-assign'&&$input['role']!=='__base__')$context['role']=$catalogue->role($input['role']);
        }else{
            $context['subject']=$this->nativeAccount($input['account'],$actor);
            $context['target']=$catalogue->account($context['subject']['manager_actor']);
            $context['board']=$this->board($context['subject']['manager_actor'],$actor);
            if($operation==='identity-assign'){
                if(!in_array($input['role'],['__base__','__keep__'],true))$context['role']=$catalogue->role($input['role']);
                if($input['contact']!==''){
                    $context['profile']=Db::run($this->db,"SELECT id,name,email FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL FOR SHARE NOWAIT",[$actor['club_id'],$input['contact']])->fetch()?:null;
                    if(!$context['profile'])throw new DomainException('Eigenes verfügbares persönliches Profil auswählen.',403);
                }
            }
        }
        return$context;
    }

    public function prepare(string $operation,array $input):array
    {
        $input=RoleInput::capture($operation,$input);
        return$this->read(function()use($operation,$input):array{
            $actor=$this->actor($operation);$this->available();
            $context=$this->context($operation,$input,$actor);
            $inputJson=self::json($input);
            return['operation'=>$operation,'input'=>$input,'context'=>$context,'input_sha256'=>hash('sha256',$operation."\0".$inputJson),'hash'=>hash('sha256',self::json(['operation'=>$operation,'input'=>$input,'context'=>$context]))];
        });
    }

    private function perform(string $operation,array $input,array $actor,array $context):array
    {
        switch($operation){
            case 'role-save':
                $id=(new Settings($this->db,$this->actorId))->saveRole($input['id'],RoleInput::version($input['version']),$input['data']);
                $state=Db::run($this->db,'SELECT * FROM settings_roles WHERE club_id=? AND id=? FOR SHARE',[$actor['club_id'],$id])->fetch();$label=$state['name'];break;
            case 'role-assign':
                $id=$input['user'];$base=$input['role']==='__base__';
                (new Settings($this->db,$this->actorId))->assignRole($id,$base?'':$input['role'],RoleInput::version($input['version']),$base);
                $state=Db::run($this->db,'SELECT a.id,a.role,a.active,a.contact_id,x.role_id,COALESCE(x.version,0) AS version,r.name AS role_name FROM actors a LEFT JOIN settings_role_assignments x ON x.actor_id=a.id AND x.club_id=a.club_id LEFT JOIN settings_roles r ON r.club_id=a.club_id AND r.id=x.role_id WHERE a.club_id=? AND a.id=? FOR SHARE',[$actor['club_id'],$id])->fetch();$state['account_name']=$context['target']['account_name'];$label=$state['account_name'];break;
            case 'board-grant':
                $id=$input['user'];
                (new AdmissionVotes($this->db,$this->actorId))->grant($id,RoleInput::version($input['version']),$input['office'],RoleInput::flag($input['enabled']),$input['office']!=='');
                $state=$this->board($id,$actor);$label=$context['target']['account_name'];break;
            case 'identity-assign':
                $id=$input['account'];$base=$input['role']==='__base__';$keep=$input['role']==='__keep__';
                $this->identity->assign($id,RoleInput::version($input['version']),RoleInput::version($input['role_version']),$input['expected_contact'],$base||$keep?'':$input['role'],$input['contact'],RoleInput::flag($input['enabled']),true,$base);
                $state=$this->nativeAccount($id,$actor);$label=$state['account_name'];break;
            case 'identity-superuser':
                $id=$input['account'];
                $this->identity->setSuperuser($id,RoleInput::version($input['version']),RoleInput::flag($input['enabled']),true);
                $state=$this->nativeAccount($id,$actor);$label=$state['account_name'];break;
            default:throw new DomainException('Gültigen Rollen- oder Kontenvorgang auswählen.',422);
        }
        return['object_id'=>$id,'label'=>$label,'state'=>$state,'changed'=>$context['subject']!==$state];
    }

    public function accept(string $operation,array $input,string $key,string $reviewHash,bool $approved):array
    {
        $input=RoleInput::capture($operation,$input);
        if(!$approved)throw new DomainException('Die vollständig geprüfte Änderung ausdrücklich bestätigen.',422);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Ursprüngliche Änderungskennung und Prüfung auswählen.',422);
        // Club first: two administrators editing each other must not wait while holding actor locks.
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();
        if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{
            return Transactions::run($this->db,function()use($operation,$input,$key,$reviewHash,$club):array{
                if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
                $actor=$this->actor($operation);
                if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
                $this->available();$inputJson=self::json($input);$inputHash=hash('sha256',$operation."\0".$inputJson);
                $old=Db::run($this->db,'SELECT * FROM native_role_requests WHERE club_id=? AND request_key=? FOR UPDATE',[$club,$key])->fetch();
                if($old){
                    if($old['actor_id']!==$actor['id']||$old['operation']!==$operation||!hash_equals($old['input_sha256'],$inputHash))throw new DomainException('Diese Änderungskennung gehört zu anderen ursprünglichen Angaben.',409);
                    return$this->decode($old);
                }
                $plan=$this->prepare($operation,$input);
                if(!hash_equals($plan['hash'],$reviewHash))throw new DomainException('Die geprüften Rollen- oder Kontenangaben wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
                $result=$this->perform($operation,$input,$actor,$plan['context']);
                $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                $original=self::json(['format'=>'dvm-role-change-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'operation'=>$operation,'input'=>$input,'review'=>$plan,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
                Db::run($this->db,'INSERT INTO native_role_requests(club_id,request_key,actor_id,operation,object_id,object_label,input_sha256,input_json,original_sha256,original_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],$operation,$result['object_id'],$result['label'],$inputHash,$inputJson,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
                $row=Db::run($this->db,'SELECT * FROM native_role_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch();
                return$this->decode($row);
            });
        }catch(\PDOException $failure){
            if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rollenänderung wird gerade bearbeitet. Bitte den ursprünglichen Vorgang prüfen.',503,$failure);
            throw$failure;
        }
    }

    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);
        $original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($original)||!hash_equals($row['input_sha256'],hash('sha256',$row['operation']."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-role-change-original/1'||($original['request_key']??null)!==$row['request_key']||($original['actor_id']??null)!==$row['actor_id']||($original['club_id']??null)!==$row['club_id']||($original['operation']??null)!==$row['operation']||($original['input']??null)!==$input||($original['approved']??null)!==true||($original['review']['operation']??null)!==$row['operation']||($original['review']['input']??null)!==$input||($original['review']['input_sha256']??null)!==$row['input_sha256']||($original['result']['object_id']??null)!==$row['object_id']||($original['result']['label']??null)!==$row['object_label'])throw new DomainException('Ursprüngliche Änderungsbestätigung bitte prüfen lassen.',503);
        $reviewHash=hash('sha256',self::json(['operation'=>$row['operation'],'input'=>$input,'context'=>$original['review']['context']]));
        $time=(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        if(!hash_equals($original['review']['hash']??'',$reviewHash)||($original['created_at']??null)!==$time)throw new DomainException('Ursprüngliche Änderungsbestätigung bitte prüfen lassen.',503);
        return['key'=>$row['request_key'],'actor_id'=>$row['actor_id'],'operation'=>$row['operation'],'input'=>$input,'context'=>$original['review']['context'],'result'=>$original['result'],'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }

    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Änderungsbestätigung nicht verfügbar.',404);
        return$this->read(function()use($key):array{
            $actor=Permissions::actor($this->db,$this->actorId);$this->available();
            $row=Db::run($this->db,'SELECT * FROM native_role_requests WHERE club_id=? AND actor_id=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],$key])->fetch();
            if(!$row)throw new DomainException('Ursprüngliche Änderungsbestätigung nicht verfügbar.',404);
            $this->actor($row['operation']);
            return$this->decode($row);
        });
    }

    /** Bounded private metadata; original input bodies open only by their explicit key. */
    public function history(array $query=[]):array
    {
        $search=$query['query']??'';$rawPage=$query['page']??'1';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search)>150||preg_match('/[\x00-\x1f\x7f]/',$search))throw new DomainException('Änderungssuche mit höchstens 150 Zeichen eingeben.',422);
        $page=is_string($rawPage)||is_int($rawPage)?(string)$rawPage:'';
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Verfügbare Änderungsseite auswählen.',422);
        return$this->read(function()use($search,$page):array{
            $this->available();$allowed=[];$actor=null;
            foreach(RoleInput::OPERATIONS as$operation){try{$actor=$this->actor($operation);$allowed[]=$operation;}catch(DomainException $failure){if($failure->getCode()!==403)throw$failure;}}
            if(!$actor||!$allowed)throw new DomainException('Keine Berechtigung für die Rollenverwaltung.',403);
            $where=' WHERE club_id=? AND actor_id=? AND operation IN ('.implode(',',array_fill(0,count($allowed),'?')).')';$args=[$actor['club_id'],$actor['id'],...$allowed];
            if($search!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT(request_key,CHAR(10),operation,CHAR(10),object_id,CHAR(10),object_label,CHAR(10),created_at)))>0";$args[]=$search;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_role_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));
            $error=(int)$page>$pages?'Verfügbare Änderungsseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,'SELECT request_key,operation,object_id,object_label,created_at FROM native_role_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            return['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$error===''?(int)$page:null,'page_input'=>$page,'query'=>$search,'per_page'=>25,'error'=>$error];
        });
    }
}
