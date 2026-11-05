<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{AssignmentPrices,Permissions};

/** Current owned assignment sources and bounded choices; no assignment or financial writes. */
final class AssignmentRepository
{
    public function __construct(private PDO $db,private string $actorId){}

    public function read(callable $operation):mixed
    {
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->beginTransaction();
            $result=$operation();
            if($own)$this->db->commit();
            return$result;
        }catch(Throwable $failure){
            if($own&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarifzuweisungen werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw$failure;
        }
    }
    public function actor(bool $write=false):array
    {
        if(!$this->db->inTransaction())return$this->read(fn():array=>$this->actor($write));
        $actor=Permissions::actor($this->db,$this->actorId);$allowed=$write?['write']:['read','write'];
        if(!in_array($actor['finance_access'],$allowed,true)||!in_array($actor['members_access'],$allowed,true))throw new DomainException('Aktuelle Finanz- und Mitgliederberechtigung für Tarifzuweisungen erforderlich.',403);
        return$actor;
    }
    public static function tab(string $kind):string
    {
        return match($kind){'service'=>'services','contribution','donation'=>'rates',default=>throw new DomainException('Ursprüngliche Tarifart bitte prüfen lassen.',503)};
    }
    private static function catalogValue(array $row):array
    {
        $data=$row['data'];
        if((bool)$row['active']!==($data['active']??null))throw new DomainException('Ursprünglichen Tarifstand bitte prüfen lassen.',503);
        return['id'=>$row['id'],'kind'=>$row['kind'],'version'=>(int)$row['version'],'data'=>$data,'active'=>(bool)$row['active']];
    }
    private static function contactValue(array $row):array
    {
        return['id'=>$row['id'],'name'=>$row['name'],'email'=>$row['email'],'version'=>(int)$row['version']];
    }
    public function contact(string $id):array
    {
        if($id===''||strlen($id)>64||!mb_check_encoding($id,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$id))throw new DomainException('Aktiven eigenen Kontakt auswählen.',422);
        return$this->read(function()use($id):array{
            $actor=$this->actor();
            $row=Db::run($this->db,'SELECT id,name,email,version FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetch();
            if(!$row)throw new DomainException('Aktiven eigenen Kontakt auswählen.',404);
            return self::contactValue($row);
        });
    }
    public function source(string $catalog,string $contact,string $tab):array
    {
        CatalogRepository::kinds($tab);
        return$this->read(function()use($catalog,$contact,$tab):array{
            $this->actor();$row=(new CatalogRepository($this->db,$this->actorId))->find($catalog,$tab);
            $catalog=self::catalogValue($row+['active'=>$row['data']['active']]);
            return['catalog'=>$catalog,'contact'=>$this->contact($contact),'linked_membership_tariff'=>$row['linked_membership_types']!==[]];
        });
    }
    public function state(string $id,string $tab):array
    {
        CatalogInput::id($id);CatalogRepository::kinds($tab);
        return$this->read(function()use($id,$tab):array{
            $this->actor();$context=(new AssignmentPrices($this->db,$this->actorId))->context($id);
            $row=$context['assignment'];$catalog=self::catalogValue($context['catalog']);
            if(self::tab($catalog['kind'])!==$tab)throw new DomainException('Tarifzuweisung im passenden Bereich auswählen.',404);
            return['id'=>$row['id'],'tab'=>$tab,'version'=>(int)$row['version'],'data'=>['catalog_id'=>$row['catalog_id'],'contact_id'=>$row['contact_id'],'start'=>$row['starts_on'],'end'=>$row['ends_on']??''],'source'=>['catalog'=>$catalog,'contact'=>self::contactValue($context['contact']),'linked_membership_tariff'=>$context['linked']]];
        });
    }
    public function overlaps(array $data):bool
    {
        return$this->read(function()use($data):bool{
            $actor=$this->actor();
            return Db::run($this->db,"SELECT 1 FROM catalog_assignments WHERE club_id=? AND catalog_id=? AND contact_id=? AND starts_on<=? AND COALESCE(ends_on,'9999-12-31')>=? LIMIT 1 FOR SHARE NOWAIT",[$actor['club_id'],$data['catalog_id'],$data['contact_id'],$data['end']?:'9999-12-31',$data['start']])->fetchColumn()!==false;
        });
    }
    private static function query(array $input):array
    {
        $q=$input['query']??'';$page=$input['page']??'1';
        if(!is_string($q)||!mb_check_encoding($q,'UTF-8')||mb_strlen($q,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$q)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Vollständige Auswahlsuche und Seite prüfen.',422);
        return[$q,(string)$page];
    }
    public function contacts(array $input=[]):array
    {
        [$query,$page]=self::query($input);
        return$this->read(function()use($query,$page):array{
            $actor=$this->actor();$args=[$actor['club_id']];$where=' WHERE club_id=? AND archived_at IS NULL';
            if($query!==''){$where.=' AND (LOCATE(LOWER(?),LOWER(name))>0 OR LOCATE(LOWER(?),LOWER(email))>0)';array_push($args,$query,$query);}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM contacts'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));
            if((int)$page>$pages)throw new DomainException('Verfügbare Kontaktauswahlseite auswählen.',422);
            $rows=Db::run($this->db,'SELECT id,name,email,version FROM contacts'.$where.' ORDER BY name,id LIMIT 6 OFFSET '.(((int)$page-1)*6).' FOR SHARE NOWAIT',$args)->fetchAll();
            return['rows'=>$rows,'query'=>$query,'page'=>(int)$page,'page_input'=>$page,'pages'=>$pages,'total'=>$total,'per_page'=>6];
        });
    }
    public function catalogs(string $tab,array $input=[]):array
    {
        $kinds=CatalogRepository::kinds($tab);[$query,$page]=self::query($input);
        return$this->read(function()use($kinds,$query,$page):array{
            $actor=$this->actor();$args=[$actor['club_id'],...$kinds];$where=' WHERE club_id=? AND active=1 AND kind IN ('.implode(',',array_fill(0,count($kinds),'?')).')';
            if($query!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name')),JSON_UNQUOTE(JSON_EXTRACT(payload,'$.description')),JSON_UNQUOTE(JSON_EXTRACT(payload,'$.tags')))))>0";$args[]=$query;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM settings_catalog'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));
            if((int)$page>$pages)throw new DomainException('Verfügbare Tarifauswahlseite auswählen.',422);
            $rows=Db::run($this->db,"SELECT id,kind,version,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name')) AS name FROM settings_catalog".$where." ORDER BY JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name')),id LIMIT 25 OFFSET ".(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll();
            return['rows'=>$rows,'query'=>$query,'page'=>(int)$page,'page_input'=>$page,'pages'=>$pages,'total'=>$total,'per_page'=>25];
        });
    }
    public function revisions(string $id,string $tab,mixed $pageInput='1'):array
    {
        [, $page]=self::query(['page'=>$pageInput]);
        return$this->read(function()use($id,$tab,$page):array{
            $this->state($id,$tab);$actor=$this->actor();$args=[$actor['club_id'],'assignment:'.$id];
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM settings_revisions WHERE club_id=? AND section=? FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));
            if((int)$page>$pages)throw new DomainException('Verfügbare Zuweisungsfassung auswählen.',422);
            $rows=Db::run($this->db,'SELECT version,actor_id,happened_at FROM settings_revisions WHERE club_id=? AND section=? ORDER BY version DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll();
            return['rows'=>$rows,'page'=>(int)$page,'page_input'=>$page,'total'=>$total,'pages'=>$pages,'per_page'=>25];
        });
    }
    public function revision(string $id,string $tab,mixed $version):array
    {
        $version=GeneralInput::version($version);if($version<1)throw new DomainException('Ursprüngliche Zuweisungsfassung nicht verfügbar.',404);
        return$this->read(function()use($id,$tab,$version):array{
            $this->state($id,$tab);$actor=$this->actor();
            $row=Db::run($this->db,'SELECT * FROM settings_revisions WHERE club_id=? AND section=? AND version=? FOR SHARE NOWAIT',[$actor['club_id'],'assignment:'.$id,$version])->fetch();
            if(!$row)throw new DomainException('Ursprüngliche Zuweisungsfassung nicht verfügbar.',404);
            return['id'=>$id,'tab'=>$tab,'version'=>$version,'actor_id'=>$row['actor_id'],'happened_at'=>$row['happened_at'],'data'=>json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR),'payload_json'=>$row['payload'],'sha256'=>hash('sha256',$row['payload'])];
        });
    }
}
