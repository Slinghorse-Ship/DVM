<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Owned, counted role/account summaries; no auth secrets, invitation payloads or whole directory. */
final class RoleCatalogue
{
    public function __construct(private PDO $db,private string $actorId){}
    /** Current summaries own only their read transaction, never a caller's earlier work. */
    private function currentRead(callable $read):array
    {
        $owned=!$this->db->inTransaction();
        try{
            if($owned)$this->db->exec('START TRANSACTION');
            $result=$read();
            if($owned)$this->db->commit();
            return$result;
        }catch(\Throwable $failure){
            if($owned&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rollenverwaltung wird gerade bearbeitet. Bitte erneut öffnen.',503,$failure);
            throw$failure;
        }
    }
    private function currentLock():string{return $this->db->inTransaction()?' FOR SHARE NOWAIT':'';}
    private function actor():array{$a=Permissions::actor($this->db,$this->actorId);if(!$a['settings_access'])throw new DomainException('Eigene Rollenverwaltung erforderlich.');return $a;}
    private static function controls(array $input,string $prefix):array
    {
        $q=$input[$prefix.'_q']??'';$page=$input[$prefix.'_page']??'1';if(!is_string($q)||!mb_check_encoding($q,'UTF-8')||mb_strlen($q)>150||preg_match('/[\x00-\x1f\x7f]/',$q)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,5}\z/D',(string)$page))throw new DomainException('Eigene Suchangaben und Seite prüfen.');return[trim($q),(int)$page];
    }
    public function roles(array $input=[],int $perPage=6):array
    {
        return$this->currentRead(function()use($input,$perPage):array{
        if(!in_array($perPage,[6,25],true))throw new DomainException('Verfügbare Rollenseitengröße auswählen.',422);$a=$this->actor();[$term,$page]=self::controls($input,'roles');$args=[$a['club_id']];$where=' WHERE club_id=?';if($term!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT(name,CHAR(10),description)))>0';$args[]=$term;}
        $q=$this->db->prepare('SELECT COUNT(*) FROM settings_roles'.$where.$this->currentLock());$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$q=$this->db->prepare('SELECT * FROM settings_roles'.$where.' ORDER BY name,id LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage).$this->currentLock());$q->execute($args);return['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'pages'=>$pages,'page'=>$page,'q'=>$term];
        });
    }
    public function role(string $id):array
    {
        return$this->currentRead(function()use($id):array{
            $a=$this->actor();$q=$this->db->prepare('SELECT * FROM settings_roles WHERE club_id=? AND id=?'.$this->currentLock());$q->execute([$a['club_id'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Eigene Rolle auswählen.');return$row;
        });
    }
    private function accountsSql():array
    {
        $native=ProductionRuntime::nativeManager()&&Database::tableExists($this->db,'native_manager_identities');$name=$native?"COALESCE(NULLIF(au.login_name,''),u.name,a.id)":'a.id';$from=' FROM actors a LEFT JOIN settings_role_assignments x ON x.actor_id=a.id AND x.club_id=a.club_id LEFT JOIN settings_roles r ON r.id=x.role_id AND r.club_id=a.club_id';if($native)$from.=' LEFT JOIN native_manager_identities i ON i.manager_actor_id=a.id AND i.manager_club_id=a.club_id LEFT JOIN native_users u ON u.id=i.native_id LEFT JOIN native_auth_accounts au ON au.actor_id=i.native_id';return[$name,$from];
    }
    public function accounts(array $input=[],int $perPage=6):array
    {
        return$this->currentRead(function()use($input,$perPage):array{
        if(!in_array($perPage,[6,25],true))throw new DomainException('Verfügbare Rollenseitengröße auswählen.',422);$a=$this->actor();[$term,$page]=self::controls($input,'role_users');[$name,$from]=$this->accountsSql();$args=[$a['club_id']];$where=' WHERE a.club_id=?';if($term!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT($name,CHAR(10),a.id,CHAR(10),COALESCE(r.name,''))))>0";$args[]=$term;}
        $q=$this->db->prepare('SELECT COUNT(*)'.$from.$where.$this->currentLock());$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$q=$this->db->prepare('SELECT a.id,a.role,a.active,a.contact_id,x.role_id,COALESCE(x.version,0) AS version,r.name AS role_name,'.$name.' AS account_name'.$from.$where.' ORDER BY account_name,a.id LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage).$this->currentLock());$q->execute($args);return['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'pages'=>$pages,'page'=>$page,'q'=>$term];
        });
    }
    public function account(string $id):array
    {
        return$this->currentRead(function()use($id):array{
            $a=$this->actor();[$name,$from]=$this->accountsSql();$q=$this->db->prepare('SELECT a.id,a.role,a.active,a.contact_id,x.role_id,COALESCE(x.version,0) AS version,r.name AS role_name,'.$name.' AS account_name'.$from.' WHERE a.club_id=? AND a.id=?'.$this->currentLock());$q->execute([$a['club_id'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Eigenes Konto auswählen.');return$row;
        });
    }
    public function accountForNativeAccount(string $id):array
    {
        return$this->currentRead(function()use($id):array{
            $a=$this->actor();$q=$this->db->prepare('SELECT i.manager_actor_id FROM native_auth_accounts au JOIN native_manager_identities i ON i.native_id=au.actor_id WHERE au.id=? AND i.manager_club_id=?'.$this->currentLock());$q->execute([$id,$a['club_id']]);$actor=$q->fetchColumn();if(!$actor)throw new DomainException('Eigenes persönliches Konto auswählen.');return $this->account((string)$actor);
        });
    }
    public function choices(string $term,?string $selected=null):array
    {
        return$this->currentRead(function()use($term,$selected):array{
        $page=$this->roles(['roles_q'=>$term]);$rows=[];foreach($page['rows']as$row)$rows[$row['id']]=['id'=>$row['id'],'name'=>$row['name']];if($selected&&!isset($rows[$selected])){$own=$this->role($selected);$rows[$selected]=['id'=>$own['id'],'name'=>$own['name']];}return['rows'=>array_values($rows),'total'=>$page['total']];
        });
    }
}
