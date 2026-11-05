<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/AssetValuation.php';

/** Retained revisions are checked against actual bytes, permissions and the owning club. */
final class AssetValuationEvidence
{
    public function __construct(private PDO $db,private string $actor){}
    public function check(array $reference,bool $lock=false):void
    {
        AssetValuation::document($reference);$actor=Permissions::actor($this->db,$this->actor);
        if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if($lock&&!$this->db->inTransaction())throw new DomainException('Originalprüfung benötigt einen Speichervorgang.');
        $row=DocumentCatalog::query($this->db,'SELECT f.sha256,SHA2(f.bytes,256) AS actual_sha256 FROM document_files f WHERE f.club_id=? AND f.document_id=? AND f.revision=?'.($lock?' FOR UPDATE':''),
            [$actor['club_id'],$reference['document_id'],$reference['revision']])->fetch();
        $document=(new Documents($this->db,$this->actor))->document($reference['document_id'],$lock);
        if(!$row||$document['state']!=='active'||!in_array('finance',$document['scopes'],true)
            ||(int)$document['revision']!==$reference['revision']||!hash_equals($reference['sha256'],$row['sha256'])||!hash_equals($reference['sha256'],$row['actual_sha256']))
            throw new DomainException('Originalbeleg der Anlagenbewertung fehlt oder wurde verändert. Zuerst die Belegübernahme berichtigen.');
    }
    public function reference(string $documentId,bool $lock=false):array
    {
        $document=(new Documents($this->db,$this->actor))->document($documentId,$lock);
        $reference=['document_id'=>$documentId,'revision'=>(int)$document['revision'],'sha256'=>$document['sha256']];
        $this->check($reference,$lock);return $reference;
    }
    public function valuation(array $value,bool $lock=false,int $depth=0):void
    {
        if($depth>100)throw new DomainException('Bewertungsvorträge sind zu umfangreich.');
        $source=$value['source']??null;if(!is_array($source))throw new DomainException('Ursprüngliche Bewertungsnachweise fehlen.');
        if(AssetValuation::calculate($source['plan'],$source['period'],$source['events'],$source['previous'])!==$value)throw new DomainException('Bewertungsnachweis wurde verändert.');
        $this->check($source['plan']['document'],$lock);
        foreach($source['events'] as $event){$this->check($event['document'],$lock);if(isset($event['allocation_document']))$this->check($event['allocation_document'],$lock);}
        if($source['previous']!==null)$this->valuation($source['previous'],$lock,$depth+1);
    }
    public function acquisition(array $reference,string $entry,bool $lock=false):void
    {
        $this->check($reference,$lock);$club=Permissions::actor($this->db,$this->actor)['club_id'];
        if(!DocumentCatalog::query($this->db,"SELECT 1 FROM document_links WHERE club_id=? AND document_id=? AND source_type='entry' AND source_id=?".($lock?' FOR UPDATE':''),
            [$club,$reference['document_id'],$entry])->fetchColumn())throw new DomainException('Ursprünglichen Anschaffungsbeleg mit genau dieser Rechnung verknüpfen.');
    }
    public function formEvents(array $form,bool $lock=false):array
    {
        if(array_key_exists('valuation',$form)){
            if(array_key_exists('valuation_events',$form))throw new DomainException('Bewertungsangaben wurden doppelt übergeben.');
            $rows=$form['valuation'];if(!is_array($rows)||count($rows)>20)throw new DomainException('Höchstens 20 belegte Bewertungsereignisse je Bestätigung erfassen.');
            $events=[];
            foreach($rows as $row){
                if(!is_array($row)||array_diff(array_keys($row),['id','kind','on','book','tax','cost','permanent','tax_election','reason','legal_basis','document_id','allocation_document_id','confirmed','no_proceeds','counter_code']))
                    throw new DomainException('Bewertungsangaben erneut prüfen.');
                if(($row['kind']??'')==='')continue;
                foreach(['id','kind','on','book','tax','reason','legal_basis','document_id'] as $key)if(!is_string($row[$key]??null))throw new DomainException('Bewertungsereignis vollständig eingeben.');
                $event=['id'=>$row['id'],'on'=>$row['on'],'kind'=>$row['kind'],'book_cents'=>Finance::cents($row['book']),'tax_cents'=>Finance::cents($row['tax']),
                    'permanent'=>($row['permanent']??'')==='1','tax_election'=>($row['tax_election']??'')==='1','reason'=>trim($row['reason']),
                    'legal_basis'=>trim($row['legal_basis']),'document'=>$this->reference($row['document_id'],$lock),'confirmed'=>($row['confirmed']??'')==='1'];
                if(($row['counter_code']??'')!==''){if(!is_string($row['counter_code']))throw new DomainException('Originales Bewertungsgegenkonto prüfen.');$event['counter_code']=$row['counter_code'];}
                if($row['kind']==='disposal'){
                    unset($event['permanent'],$event['tax_election']);
                    if(!is_string($row['cost']??null)||!is_string($row['allocation_document_id']??null))throw new DomainException('Kosten, Restwerte und Aufteilungsnachweis des Abgangs eingeben.');
                    $event['cost_cents']=Finance::cents($row['cost']);$event['allocation_document']=$this->reference($row['allocation_document_id'],$lock);
                    $event['no_proceeds']=($row['no_proceeds']??'')==='1';
                }
                $events[]=$event;
            }
            return $events;
        }
        $raw=$form['valuation_events']??'[]';if(!is_string($raw)||strlen($raw)>100000)throw new DomainException('Bewertungsangaben erneut prüfen.');
        try{$events=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Bewertungsangaben erneut prüfen.');}
        if(!is_array($events)||!array_is_list($events)||count($events)>200)throw new DomainException('Bewertungsereignisse vollständig und in zeitlicher Reihenfolge angeben.');
        return $events;
    }
}
