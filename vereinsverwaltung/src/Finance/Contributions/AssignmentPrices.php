<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Optional original per-assignment gross prices; global tariffs and invoices stay unchanged. */
final class AssignmentPrices
{
    public function __construct(private PDO $db,private string $actorId){}
    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true)||$a['members_access']==='none')throw new DomainException('Finanz- und Mitgliederberechtigung für persönliche Tarifpreise erforderlich.');
        return $a;
    }
    private function currentRead(callable $operation):mixed
    {
        try {
            return Transactions::run($this->db, $operation);
        } catch (\PDOException $failure) {
            if (in_array((int)($failure->errorInfo[1] ?? 0), [1205,3572], true)) {
                throw new DomainException('Tarifpreis wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            }
            throw $failure;
        }
    }
    public function context(string $id,bool $lock=false):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Eigene Tarifzuweisung auswählen.',422);
        return $this->currentRead(function()use($id,$lock):array{
            $a=$this->actor();$suffix=$lock?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT';
            $q=$this->db->prepare('SELECT * FROM catalog_assignments WHERE club_id=? AND id=?'.$suffix);$q->execute([$a['club_id'],$id]);$assignment=$q->fetch(PDO::FETCH_ASSOC);
            if(!$assignment)throw new DomainException('Eigene Tarifzuweisung auswählen.',404);
            $q=$this->db->prepare('SELECT * FROM settings_catalog WHERE club_id=? AND id=?'.$suffix);$q->execute([$a['club_id'],$assignment['catalog_id']]);$catalog=$q->fetch(PDO::FETCH_ASSOC);
            if(!$catalog)throw new DomainException('Eigenen Tarif auswählen.',404);
            $catalog['data']=json_decode($catalog['payload'],true,512,JSON_THROW_ON_ERROR);
            $q=$this->db->prepare('SELECT * FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL'.$suffix);$q->execute([$a['club_id'],$assignment['contact_id']]);$contact=$q->fetch(PDO::FETCH_ASSOC);
            if(!$contact)throw new DomainException('Aktiven eigenen Kontakt auswählen.',404);
            $q=$this->db->prepare('SELECT catalog_id FROM membership_contribution_rates WHERE club_id=? AND catalog_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$catalog['id']]);$linked=$q->fetchColumn()!==false;
            return compact('assignment','catalog','contact','linked');
        });
    }
    /** Current ordinary reads remain compatible with an older installation and never perform DDL. */
    public function get(string $id,bool $lock=false):?array
    {
        return $this->currentRead(function()use($id,$lock):?array{
            $a=$this->actor();if(!Database::tableExists($this->db,'native_assignment_prices'))return null;
            $q=$this->db->prepare('SELECT * FROM native_assignment_prices WHERE club_id=? AND assignment_id=?'.($lock?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT'));$q->execute([$a['club_id'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if($row){
                $q=$this->db->prepare('SELECT contact_id FROM catalog_assignments WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$contact=$q->fetchColumn();
                if(!$contact)throw new DomainException('Eigene Tarifzuweisung auswählen.',404);
                $q=$this->db->prepare('SELECT id FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL FOR SHARE NOWAIT');$q->execute([$a['club_id'],$contact]);
                if(!$q->fetchColumn())throw new DomainException('Aktiven eigenen Kontakt auswählen.',404);
            }
            return$row;
        });
    }
    public function history(string $id,int $page=1):array
    {
        if($page<1)throw new DomainException('Verfügbare persönliche Preisverlaufsseite auswählen.',422);
        return$this->currentRead(function()use($id,$page):array{
            $this->context($id);$a=$this->actor();
            if(!Database::tableExists($this->db,'native_assignment_price_changes')){
                if($page!==1)throw new DomainException('Verfügbare persönliche Preisverlaufsseite auswählen.',422);
                return['rows'=>[],'total'=>0,'pages'=>1,'page'=>1];
            }
            $q=$this->db->prepare('SELECT COUNT(*) FROM native_assignment_price_changes WHERE club_id=? AND assignment_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/6));
            if($page>$pages)throw new DomainException('Verfügbare persönliche Preisverlaufsseite auswählen.',422);
            $q=$this->db->prepare('SELECT * FROM native_assignment_price_changes WHERE club_id=? AND assignment_id=? ORDER BY version DESC LIMIT 6 OFFSET '.(($page-1)*6).' FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);
            return['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'pages'=>$pages,'page'=>$page];
        });
    }
    public function change(string $id,int $version,string $mode,string $value,string $key):array
    {
        $a=$this->actor(true);if(!Database::tableExists($this->db,'native_assignment_prices'))throw new DomainException('Sonderpreise benötigen das aktuelle installierte Paket.');
        if(strlen($value)>30||!mb_check_encoding($value,'UTF-8')||!preg_match('/\A[a-f0-9]{32}\z/D',$key)||$version<0||!in_array($mode,['none','fixed','percent'],true))throw new DomainException('Preisart und ursprüngliches Formular prüfen.');
        $units=$mode==='none'?0:Finance::cents($value);if($mode==='percent'&&$units>10000)throw new DomainException('Rabatt zwischen 0 und 100 Prozent auswählen.');
        $hash=hash('sha256',json_encode([$id,$version,$mode,$value,$a['id']],JSON_THROW_ON_ERROR));
        return Transactions::run($this->db,function()use($a,$id,$version,$mode,$units,$key,$hash){
            $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE');$q->execute([$a['club_id']]);$fresh=$this->actor(true);if($fresh['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.');
            $q=$this->db->prepare('SELECT * FROM native_assignment_price_changes WHERE club_id=? AND request_key=? FOR UPDATE');$q->execute([$a['club_id'],$key]);if($old=$q->fetch(PDO::FETCH_ASSOC)){if(!hash_equals($old['request_hash'],$hash))throw new DomainException('Vorgangskennung gehört zu einem anderen Sonderpreis. Neu öffnen.');return json_decode($old['state_json'],true,512,JSON_THROW_ON_ERROR);}
            $context=$this->context($id,true);if($context['linked'])throw new DomainException('Verknüpfte Einzel- und Familienjahresbeiträge behalten ihren vollständigen Tarif.');if(!(int)$context['catalog']['active'])throw new DomainException('Aktiven zusätzlichen Tarif auswählen.');
            $old=$this->get($id,true);if((int)($old['version']??0)!==$version)throw new DomainException('Sonderpreis inzwischen geändert. Aktuellen Stand neu öffnen.');
            $state=['club_id'=>$a['club_id'],'assignment_id'=>$id,'version'=>$version+1,'mode'=>$mode,'value_units'=>$units,'changed_by'=>$a['id']];
            $q=$this->db->prepare('INSERT INTO native_assignment_prices VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),mode=VALUES(mode),value_units=VALUES(value_units),changed_by=VALUES(changed_by),changed_at=VALUES(changed_at)');$q->execute(array_values($state));
            $q=$this->db->prepare('INSERT INTO native_assignment_price_changes VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$a['club_id'],$key,$hash,$id,$version+1,$a['id'],json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);return $state;
        });
    }
    public static function value(array $row):string{$v=(int)$row['value_units'];return intdiv($v,100).'.'.str_pad((string)($v%100),2,'0',STR_PAD_LEFT);}
    public static function amount(int $base,?array $row):int
    {
        if(!$row||$row['mode']==='none')return $base;
        if($row['mode']==='fixed')return(int)$row['value_units'];
        return intdiv($base*(10000-(int)$row['value_units'])+5000,10000);
    }
}
