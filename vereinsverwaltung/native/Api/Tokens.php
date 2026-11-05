<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;

/** Personal, explicitly scoped credentials; only their SHA-256 is persisted. */
final class Tokens
{
    public const PREFIX='khapi_v1_';
    private \Closure $clock;
    public function __construct(private readonly \PDO $db,private readonly \Kulturhus\Native\Auth\Service $auth,#[\SensitiveParameter]private readonly string $key,?\Closure $clock=null){if(strlen($key)!==32)throw new \LogicException('Privater API-Schlüssel erforderlich.');$this->clock=$clock??time(...);}
    private function now():int{return ($this->clock)();}
    private function account(string $id):array
    {
        $lock=$this->db->inTransaction()?' FOR SHARE':'';
        $row=Db::run($this->db,'SELECT a.*,u.active AS user_active,u.club_id AS native_club,i.manager_actor_id,i.manager_club_id FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id JOIN native_manager_identities i ON i.native_id=a.actor_id WHERE a.id=?'.$lock,[$id])->fetch();
        if(!$row||!(int)$row['active']||!(int)$row['verified']||(int)$row['suspended']||!(int)$row['user_active']||!$row['password_hash']||((int)$row['email_factor']&&$row['factor_email']!==$row['email'])||!hash_equals(ManagerBridge::actorKey($row['manager_actor_id'],$row['manager_club_id']),$row['actor_id'])||!hash_equals(ManagerBridge::clubKey($row['manager_club_id']),$row['native_club']))throw Problem::unauthorized();
        return $row;
    }
    private function stamp(array $account):string
    {
        return hash_hmac('sha256',json_encode([$account['id'],$account['actor_id'],$account['manager_actor_id'],$account['manager_club_id'],(int)$account['version'],$account['password_hash'],$account['totp_secret'],(bool)$account['email_factor'],$account['factor_email']],JSON_THROW_ON_ERROR),$this->key);
    }
    private function own(#[\SensitiveParameter]string $session):array
    {
        try{$account=$this->auth->resolve($session);return $this->account($account['id']);}catch(\Throwable){throw Problem::unauthorized();}
    }
    public function prepare(#[\SensitiveParameter]string $session,string $requestId,string $label,array $scopes,int $days,#[\SensitiveParameter]string $password):array
    {
        if($this->db->inTransaction())throw new \LogicException('API-Zugang außerhalb einer Geschäftsbuchung anlegen.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestId)||!preg_match('//u',$label)||trim($label)===''||mb_strlen($label)>100||preg_match('/[\x00-\x1f\x7f]/',$label)||$days<1||$days>90)throw Problem::invalid();
        $scopes=Scopes::validate($scopes);$context=Factory::session($this->db,$session);foreach($scopes as $scope)$context->require($scope);
        if(Db::run($this->db,'SELECT 1 FROM native_api_token_requests WHERE id=?',[$requestId])->fetchColumn())throw new Problem('request_used',409,'Dieser Anlegevorgang wurde bereits verwendet. Neuen Vorgang öffnen.');
        // Auth rate limits and second-factor consumption must survive a rejected API operation.
        $this->auth->reauthenticatePassword($session,$password);$state=$this->auth->reauthenticationState($session);
        return Db::atomic($this->db,function()use($session,$requestId,$label,$scopes,$days,$state,$context):array{
            $account=$this->own($session);if($account['id']!==$context->accountId)throw Problem::unauthorized();foreach($scopes as $scope)$context->require($scope);
            $current=$this->auth->reauthenticationState($session);if(!hash_equals($state['stamp'],$current['stamp']))throw Problem::unauthorized();
            $now=$this->now();Db::run($this->db,'INSERT INTO native_api_token_requests VALUES(?,?,?,?,?,?,?,?,?,?,?,NULL)',[$requestId,$account['id'],hash('sha256',$session),$this->stamp($account),$state['stamp'],trim($label),json_encode($scopes,JSON_THROW_ON_ERROR),$days,(int)$state['mfa'],$now,$now+300]);
            return ['id'=>$requestId,'needs_factor'=>$state['mfa'],'factor'=>$state['factor'],'expires_at'=>$now+300];
        });
    }
    private function pending(#[\SensitiveParameter]string $session,string $id,bool $lock=false):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw Problem::invalid();$account=$this->own($session);
        $row=Db::run($this->db,'SELECT * FROM native_api_token_requests WHERE id=? AND account_id=? AND session_hash=?'.($lock?' FOR UPDATE':''),[$id,$account['id'],hash('sha256',$session)])->fetch();
        if(!$row||$row['consumed_at']!==null||(int)$row['expires_at']<=$this->now()||!hash_equals($row['security_stamp'],$this->stamp($account)))throw new Problem('request_expired',409,'Der Anlegevorgang ist abgelaufen oder bereits abgeschlossen.');return $row;
    }
    public function requestState(#[\SensitiveParameter]string $session,string $id):array
    {
        $pending=$this->pending($session,$id);$state=$this->auth->reauthenticationState($session);if(!hash_equals($state['stamp'],$pending['auth_stamp']))throw Problem::unauthorized();return ['id'=>$id,'needs_factor'=>(bool)$pending['needs_factor'],'factor'=>$state['factor'],'expires_at'=>(int)$pending['expires_at']];
    }
    /** The returned plaintext exists only in this response, never in a DB/session/flash. */
    public function issue(#[\SensitiveParameter]string $session,string $id,#[\SensitiveParameter]string $factor=''):array
    {
        if($this->db->inTransaction())throw new \LogicException('API-Zugang außerhalb einer Geschäftsbuchung bestätigen.');
        $pending=$this->pending($session,$id);
        if((bool)$pending['needs_factor'])$this->auth->reauthenticateFactor($session,$factor,$pending['auth_stamp']);
        $context=Factory::session($this->db,$session);
        return Db::atomic($this->db,function()use($session,$id,$pending,$context):array{
            Db::run($this->db,'SELECT id FROM native_auth_accounts WHERE id=? FOR UPDATE',[$pending['account_id']]);
            $pending=$this->pending($session,$id,true);$account=$this->own($session);if($account['id']!==$context->accountId)throw Problem::unauthorized();$scopes=Scopes::validate(json_decode($pending['scopes'],true,16,JSON_THROW_ON_ERROR));foreach($scopes as $scope)$context->require($scope);
            $now=$this->now();$token=self::PREFIX.bin2hex(random_bytes(32));$tokenId=bin2hex(random_bytes(16));
            $count=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_api_tokens WHERE account_id=? AND revoked_at IS NULL AND expires_at>?',[$account['id'],$now])->fetchColumn();if($count>=20)throw new Problem('token_limit',409,'Zuerst einen nicht mehr benötigten API-Zugang widerrufen.');
            Db::run($this->db,'INSERT INTO native_api_tokens VALUES(?,?,?,?,?,?,?,?,?,?,?,NULL,NULL)',[$tokenId,hash('sha256',$token),$account['id'],$account['actor_id'],$account['manager_actor_id'],$account['manager_club_id'],$this->stamp($account),$pending['label'],json_encode($scopes,JSON_THROW_ON_ERROR),$now,$now+(int)$pending['ttl_days']*86400]);
            Db::run($this->db,'UPDATE native_api_token_requests SET consumed_at=? WHERE id=?',[$now,$id]);
            return ['id'=>$tokenId,'token'=>$token,'label'=>$pending['label'],'scopes'=>$scopes,'expires_at'=>$now+(int)$pending['ttl_days']*86400];
        });
    }
    public function resolve(#[\SensitiveParameter]string $token):array
    {
        if(!preg_match('/^'.self::PREFIX.'[a-f0-9]{64}$/D',$token))throw Problem::unauthorized();
        $row=Db::run($this->db,'SELECT * FROM native_api_tokens WHERE token_hash=?'.($this->db->inTransaction()?' FOR SHARE':''),[hash('sha256',$token)])->fetch();
        if(!$row||$row['revoked_at']!==null||(int)$row['expires_at']<=$this->now())throw Problem::unauthorized();$account=$this->account($row['account_id']);
        if($row['native_actor']!==$account['actor_id']||$row['manager_actor']!==$account['manager_actor_id']||$row['manager_club']!==$account['manager_club_id']||!hash_equals($row['security_stamp'],$this->stamp($account)))throw Problem::unauthorized();
        Scopes::validate(json_decode($row['scopes'],true,16,JSON_THROW_ON_ERROR));return $row;
    }
    public function touch(#[\SensitiveParameter]string $token):void
    {
        $row=$this->resolve($token);$now=$this->now();Db::run($this->db,'UPDATE native_api_tokens SET last_used_at=? WHERE id=? AND (last_used_at IS NULL OR last_used_at<?)',[$now,$row['id'],$now-60]);
    }
    public function listing(#[\SensitiveParameter]string $session,int $page=1):array
    {
        if($page<1||$page>100000)throw Problem::invalid();$owner=$this->own($session);$now=$this->now();
        $count=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_api_tokens WHERE account_id=?',[$owner['id']])->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min($page,$pages);
        $rows=Db::run($this->db,'SELECT id,label,scopes,created_at,expires_at,revoked_at,last_used_at,security_stamp FROM native_api_tokens WHERE account_id=? ORDER BY created_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6),[$owner['id']])->fetchAll();
        foreach($rows as &$row){$row['state']=$row['revoked_at']!==null?'revoked':((int)$row['expires_at']<=$now?'expired':(!hash_equals($row['security_stamp'],$this->stamp($owner))?'security_changed':'active'));unset($row['security_stamp']);$row['scopes']=json_decode($row['scopes'],true,16,JSON_THROW_ON_ERROR);foreach(['created_at','expires_at','revoked_at','last_used_at'] as $key)$row[$key]=$row[$key]===null?null:(int)$row[$key];}unset($row);
        return ['rows'=>$rows,'total'=>$count,'pages'=>$pages,'page'=>$page];
    }
    public function revoke(#[\SensitiveParameter]string $session,string $id):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw Problem::invalid();
        Db::atomic($this->db,function()use($session,$id):void{$owner=$this->own($session);$row=Db::run($this->db,'SELECT id FROM native_api_tokens WHERE account_id=? AND id=? FOR UPDATE',[$owner['id'],$id])->fetch();if(!$row)throw Problem::missing();Db::run($this->db,'UPDATE native_api_tokens SET revoked_at=COALESCE(revoked_at,?) WHERE id=?',[$this->now(),$id]);});
    }
}
