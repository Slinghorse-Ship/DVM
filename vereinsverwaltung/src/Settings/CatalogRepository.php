<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use PDO;
use DomainException;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,Settings};

/** Current owned values and bounded catalogues; no price calculation or billing. */
final class CatalogRepository
{
    public function __construct(private PDO $db,private string $actorId){}
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->beginTransaction();$result=$operation();if($own)$this->db->commit();return$result;}
        catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarife werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    public function actor(bool $write=false):array
    {
        if(!$this->db->inTransaction())return$this->read(fn():array=>$this->actor($write));
        $actor=Permissions::actor($this->db,$this->actorId);
        if($actor['role']==='editor'||!in_array($actor['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Aktuelle Finanzberechtigung für Tarife erforderlich.',403);
        return$actor;
    }
    public static function kinds(string $tab):array{return match($tab){'rates'=>['contribution','donation'],'services'=>['service'],default=>throw new DomainException('Eigenen Tarifbereich auswählen.',422)};}
    public function find(string $id,string $tab):array
    {
        CatalogInput::id($id);$kinds=self::kinds($tab);
        return$this->read(function()use($id,$kinds):array{$actor=$this->actor();$kind=Db::run($this->db,'SELECT kind FROM settings_catalog WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetchColumn();if(!is_string($kind)||!in_array($kind,$kinds,true))throw new DomainException('Tarif nicht verfügbar.',404);return$this->state($id,$kind);});
    }
    public function state(string $id,string $kind):array
    {
        CatalogInput::id($id,true);if(!in_array($kind,CatalogValues::KINDS,true))throw new DomainException('Eigene Tarifart auswählen.',422);
        return$this->read(function()use($id,$kind):array{
            $actor=$this->actor();$row=$id===''?false:Db::run($this->db,'SELECT * FROM settings_catalog WHERE club_id=? AND id=? AND kind=? FOR SHARE NOWAIT',[$actor['club_id'],$id,$kind])->fetch();
            if($id!==''&&!$row)throw new DomainException('Tarif nicht verfügbar.',404);
            $data=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):Settings::catalogDefaults($kind);
            if($row&&(bool)$row['active']!==($data['active']??null))throw new DomainException('Ursprünglichen Tarifstand bitte prüfen lassen.',503);
            $linked=$id===''?[]:Db::run($this->db,'SELECT membership_type FROM membership_contribution_rates WHERE club_id=? AND catalog_id=? ORDER BY membership_type FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetchAll(PDO::FETCH_COLUMN);
            return['id'=>$id,'kind'=>$kind,'data'=>$data,'version'=>(int)($row['version']??0),'linked_membership_types'=>$linked];
        });
    }
    public function linkedMembershipRates():array
    {
        return $this->read(function():array{
            $actor=$this->actor();
            $rows=Db::run($this->db,'SELECT membership_type,catalog_id FROM membership_contribution_rates WHERE club_id=? ORDER BY membership_type FOR SHARE NOWAIT',[$actor['club_id']])->fetchAll();
            $rates=[];
            foreach($rows as$row)if(in_array($row['membership_type'],['single','family'],true))$rates[$row['membership_type']]=$this->state($row['catalog_id'],'contribution');
            return$rates;
        });
    }
    public function page(string $tab,array $input=[]):array
    {
        $kinds=self::kinds($tab);$search=$input['query']??'';$raw=$input['page']??'1';$page=is_string($raw)||is_int($raw)?(string)$raw:'';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$search))throw new DomainException('Tarifsuche mit höchstens 150 Zeichen eingeben.',422);
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Verfügbare Tarifseite auswählen.',422);
        return$this->read(function()use($kinds,$search,$page):array{
            $actor=$this->actor();$args=[$actor['club_id'],...$kinds];$where=' WHERE club_id=? AND kind IN ('.implode(',',array_fill(0,count($kinds),'?')).')';
            if($search!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name')),JSON_UNQUOTE(JSON_EXTRACT(payload,'$.description')),JSON_UNQUOTE(JSON_EXTRACT(payload,'$.tags')))))>0";$args[]=$search;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM settings_catalog'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare Tarifseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,"SELECT * FROM settings_catalog".$where." ORDER BY kind,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name')),id LIMIT 25 OFFSET ".(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            foreach($rows as&$row){$row['data']=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);unset($row['payload']);}unset($row);
            return['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$error===''?(int)$page:null,'page_input'=>$page,'query'=>$search,'per_page'=>25,'error'=>$error];
        });
    }
    public function revisions(string $id,string $kind,mixed $pageInput='1'):array
    {
        CatalogInput::id($id);$page=is_string($pageInput)||is_int($pageInput)?(string)$pageInput:'';if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Verfügbare Fassungsseite auswählen.',422);
        return$this->read(function()use($id,$kind,$page):array{$this->state($id,$kind);$actor=$this->actor();$args=[$actor['club_id'],'catalog:'.$id];$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM settings_revisions WHERE club_id=? AND section=? FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare Fassungsseite auswählen.':'';$rows=$error===''?Db::run($this->db,'SELECT version,actor_id,happened_at FROM settings_revisions WHERE club_id=? AND section=? ORDER BY version DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];return['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$page,'page'=>$error===''?(int)$page:null,'error'=>$error];});
    }
    public function revision(string $id,string $kind,mixed $version):array
    {
        CatalogInput::id($id);$version=GeneralInput::version($version);if($version<1)throw new DomainException('Ursprüngliche Tariffassung nicht verfügbar.',404);
        return$this->read(function()use($id,$kind,$version):array{$this->state($id,$kind);$actor=$this->actor();$row=Db::run($this->db,'SELECT * FROM settings_revisions WHERE club_id=? AND section=? AND version=? FOR SHARE NOWAIT',[$actor['club_id'],'catalog:'.$id,$version])->fetch();if(!$row)throw new DomainException('Ursprüngliche Tariffassung nicht verfügbar.',404);return['id'=>$id,'kind'=>$kind,'version'=>$version,'actor_id'=>$row['actor_id'],'happened_at'=>$row['happened_at'],'data'=>json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR),'payload_json'=>$row['payload'],'sha256'=>hash('sha256',$row['payload'])];});
    }
}
