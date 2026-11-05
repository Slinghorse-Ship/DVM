<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

use DomainException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Bounded administrative metadata only; authentication secrets never enter this catalogue. */
final class AccountCatalogue
{
    private const STATE="CASE WHEN a.suspended=1 OR u.active=0 THEN 'blocked' WHEN a.verified=0 OR a.password_hash IS NULL THEN 'pending' WHEN a.active=0 OR (a.email_factor=1 AND NOT(a.factor_email <=> a.email)) THEN 'blocked' ELSE 'active' END";
    private const COLUMNS='a.id,a.actor_id,a.email,a.login_name,a.active,a.suspended,a.verified,a.version,(a.totp_secret IS NOT NULL) AS totp_enabled,a.email_factor,u.name,u.active AS person_active';
    public function __construct(private PDO $db,private Access $access){}

    private function owner():array{return $this->access->require('accounts','manage');}
    private static function controls(array $input,string $prefix):array
    {
        $term=$input[$prefix.'_q']??'';$page=$input[$prefix.'_page']??1;
        if(!is_string($term)||!mb_check_encoding($term,'UTF-8')||mb_strlen($term)>150||preg_match('/[\x00-\x1f\x7f]/',$term)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Kontosuche und Seite prüfen.');
        return [trim($term),(int)$page];
    }
    private static function window(int $total,int $page):array{$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);return ['total'=>$total,'page'=>$page,'pages'=>$pages,'offset'=>($page-1)*6];}
    private static function dto(array $row):array{foreach(['active','suspended','verified','totp_enabled','email_factor','person_active'] as $field)$row[$field]=(bool)$row[$field];$row['version']=(int)$row['version'];return $row;}
    private function unchanged(array $owner):void{$current=$this->owner();if($current['id']!==$owner['id']||$current['club_id']!==$owner['club_id'])throw new DomainException('Kontoverwaltung nach geänderten Rechten erneut öffnen.');}

    public function listing(array $input=[]):array
    {
        [$term,$page]=self::controls($input,'accounts');$state=$input['accounts_state']??'';if(!is_string($state)||!in_array($state,['','active','blocked','pending'],true))throw new DomainException('Kontostatus auswählen.');
        return Db::atomic($this->db,function()use($term,$page,$state):array{$owner=$this->owner();$args=[$owner['club_id']];$where=' FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE u.club_id=?';
            if($term!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT(u.name,CHAR(10),a.email,CHAR(10),COALESCE(a.login_name,''))))>0";$args[]=$term;}
            if($state!==''){$where.=' AND ('.self::STATE.')=?';$args[]=$state;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*)'.$where,$args)->fetchColumn();$window=self::window($total,$page);$rows=Db::run($this->db,'SELECT '.self::COLUMNS.','.self::STATE.' AS state'.$where.' ORDER BY u.name,a.email,a.id LIMIT 6 OFFSET '.$window['offset'],$args)->fetchAll();$this->unchanged($owner);unset($window['offset']);return ['rows'=>array_map(self::dto(...),$rows),'q'=>$term,'state'=>$state]+$window;
        });
    }
    public function account(string $id):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Eigenes persönliches Konto auswählen.');$owner=$this->owner();$row=Db::run($this->db,'SELECT '.self::COLUMNS.','.self::STATE.' AS state FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE u.club_id=? AND a.id=?',[$owner['club_id'],$id])->fetch();$this->unchanged($owner);if(!$row)throw new DomainException('Eigenes persönliches Konto ist nicht mehr verfügbar.');return self::dto($row);
    }
    public function people(array $input=[],?string $selected=null):array
    {
        [$term,$page]=self::controls($input,'people');if($selected!==null&&($selected===''||strlen($selected)>128||!mb_check_encoding($selected,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$selected)))throw new DomainException('Person im Verein auswählen.');
        return Db::atomic($this->db,function()use($term,$page,$selected):array{$owner=$this->owner();$args=[$owner['club_id']];$base=' FROM native_users u LEFT JOIN native_auth_accounts a ON a.actor_id=u.id WHERE u.club_id=? AND u.active=1 AND (a.id IS NULL OR (a.verified=0 AND a.password_hash IS NULL AND a.suspended=0))';$columns="u.id,u.name,COALESCE(a.email,'') AS email,COALESCE(a.version,0) AS account_version";$current=null;
            if($selected!==null){$current=Db::run($this->db,'SELECT '.$columns.$base.' AND u.id=?',[...$args,$selected])->fetch();if(!$current)throw new DomainException('Gewählte Person kann nicht mehr eingeladen werden. Aktuellen Kontostand öffnen.');$current['account_version']=(int)$current['account_version'];}
            $where=$base;if($term!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT(u.name,CHAR(10),COALESCE(a.email,''))))>0";$args[]=$term;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*)'.$where,$args)->fetchColumn();$window=self::window($total,$page);$rows=Db::run($this->db,'SELECT '.$columns.$where.' ORDER BY u.name,u.id LIMIT 6 OFFSET '.$window['offset'],$args)->fetchAll();foreach($rows as &$row)$row['account_version']=(int)$row['account_version'];unset($row);$this->unchanged($owner);unset($window['offset']);return ['rows'=>$rows,'selected'=>$current,'q'=>$term]+$window;
        });
    }
}
