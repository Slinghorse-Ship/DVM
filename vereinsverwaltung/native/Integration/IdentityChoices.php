<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
use DomainException;
use Kulturhus\Native\Shared\Db;
use PDO;

/** Exact owned role/profile metadata, with bounded search and selected-original retention. */
final class IdentityChoices
{
    public function __construct(private PDO $db,private ManagerAccess $access){}
    public function listing(string $kind,array $input=[],?string $selected=null):array
    {
        if(!in_array($kind,['role','profile'],true))throw new DomainException('Persönliche Auswahl prüfen.');$prefix='identity_'.$kind;$term=$input[$prefix.'_q']??'';$page=$input[$prefix.'_page']??1;
        if(!is_string($term)||!mb_check_encoding($term,'UTF-8')||mb_strlen($term)>150||preg_match('/[\x00-\x1f\x7f]/',$term)||(!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page)||($selected!==null&&!preg_match('/\A[a-f0-9]{32}\z/D',$selected)))throw new DomainException('Profil- oder Rollensuche und Seite prüfen.');
        return Db::atomic($this->db,function()use($kind,$prefix,$term,$page,$selected):array{$owner=$this->access->require('accounts','manage');$club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->access->managerActor()])->fetchColumn();$base=$kind==='role'?' FROM settings_roles WHERE club_id=?':" FROM contacts WHERE club_id=? AND kind='person' AND archived_at IS NULL";$args=[$club];$current=$selected!==null?Db::run($this->db,'SELECT id,name'.$base.' AND id=?',[...$args,$selected])->fetch():null;$where=$base;$term=trim($term);
            if($term!==''){$where.=' AND LOCATE(LOWER(?),LOWER(name))>0';$args[]=$term;}$total=(int)Db::run($this->db,'SELECT COUNT(*)'.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);$rows=Db::run($this->db,'SELECT id,name'.$where.' ORDER BY name,id LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll();$after=$this->access->require('accounts','manage');if($after['id']!==$owner['id']||$after['club_id']!==$owner['club_id'])throw new DomainException('Persönliche Auswahl nach geänderten Rechten erneut öffnen.');return ['rows'=>$rows,'selected'=>$current?:null,'unavailable'=>$selected!==null&&!$current,'q'=>$term,'page'=>$page,'pages'=>$pages,'total'=>$total,'prefix'=>$prefix];
        });
    }
}
