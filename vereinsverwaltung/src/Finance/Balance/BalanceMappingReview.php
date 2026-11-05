<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once __DIR__.'/BalanceXmlOverview.php';

/** Reviewed XML choices for the ongoing ledger. Never closes a year or approves XML. */
final class BalanceMappingReview
{
    public function __construct(private PDO $db,private string $actor){}
    public static function digest(array $r):string
    {
        $data=[];foreach(['year','accounts','opening','entries','calculation','asset_schedule'] as $key)$data[$key]=$r[$key]??null;
        return hash('sha256',json_encode(OwnIntangibleStorage::normal($data),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    private function access(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actor);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if($write&&((new Settings($this->db,$this->actor))->section('tax')['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Für diesen Verein ist keine Bilanz eingestellt.');
        return $a;
    }
    public function read(array $r):array
    {
        if(!array_key_exists('asset_schedule',$r))$r['asset_schedule']=(new BalanceAssetSchedule($this->db,$this->actor))->build($r['year']);
        $a=$this->access();$out=['version'=>0,'current'=>false,'mapping'=>[],'digest'=>self::digest($r)];
        if(!Database::tableExists($this->db,'balance_mapping_reviews'))return $out;
        $q=$this->db->prepare('SELECT * FROM balance_mapping_reviews WHERE club_id=? AND tax_year=?');$q->execute([$a['club_id'],$r['year']]);$row=$q->fetch();
        if(!$row)return $out;
        $out['version']=(int)$row['version'];$out['current']=hash_equals($out['digest'],$row['source_sha256']);
        if($out['current'])$out['mapping']=json_decode($row['mapping'],true,64,JSON_THROW_ON_ERROR);
        return $out;
    }
    public function save(int $year,int $ledgerVersion,int $reviewVersion,string $digest,array $mapping,bool $reviewed):void
    {
        if(!$reviewed||!BalanceLedger::enabled())throw new DomainException('Konten und tatsächliche Bedeutung ausdrücklich prüfen.');
        if($this->db->inTransaction())throw new DomainException('Eigener Speichervorgang erforderlich.');
        $a=$this->access(true);$this->db->beginTransaction();
        try{
            $club=$a['club_id'];DocumentCatalog::query($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE',[$club]);$a=$this->access(true);
            if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.');
            $r=(new BalanceLedger($this->db,$this->actor))->report($year);
            if($r['state']!=='open'||$r['version']!==$ledgerVersion)throw new DomainException('Offenen Buchstand neu laden.');
            $r['asset_schedule']=(new BalanceAssetSchedule($this->db,$this->actor))->build($year);
            $stored=$this->read($r);
            if($stored['version']!==$reviewVersion||!hash_equals($stored['digest'],$digest))throw new DomainException('Buchstand oder Zuordnungen inzwischen geändert. Erneut prüfen.');
            FinancialReserveXml::assertExportable($r['calculation']['accounts']);
            $targets=BalanceTaxFiling::targets();$accounts=$r['calculation']['accounts'];$checked=[];
            if(array_diff(array_keys($mapping),array_keys($accounts)))throw new DomainException('Unbekanntes Bilanzkonto.');
            foreach($accounts as $id=>$account){
                if(BalanceXmlOverview::unused($account))continue;
                $target=$mapping[$id]??null;$choices=BalanceTaxMapping::choices($year,$account,$targets);
                if(!is_string($target)||!isset($choices[$target]))throw new DomainException('Unterstützte XML-Zuordnung wählen: '.$account['name']);
                $checked[$id]=$target;
            }
            $payload=json_encode($checked,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$now=gmdate('c');$v=$reviewVersion+1;
            DocumentCatalog::query($this->db,'INSERT INTO balance_mapping_reviews(club_id,tax_year,version,source_sha256,mapping,actor_id,happened_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),source_sha256=VALUES(source_sha256),mapping=VALUES(mapping),actor_id=VALUES(actor_id),happened_at=VALUES(happened_at)',[$a['club_id'],$year,$v,$digest,$payload,$a['id'],$now]);
            DocumentCatalog::query($this->db,'INSERT INTO balance_mapping_review_history(club_id,tax_year,version,source_sha256,mapping,actor_id,happened_at) VALUES(?,?,?,?,?,?,?)',[$a['club_id'],$year,$v,$digest,$payload,$a['id'],$now]);
            DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'balance.xml-mapping-reviewed',(string)$year,$v,$now]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
