<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

require_once __DIR__.'/AssetPartialDisposalReview.php';

/** Immutable reviews and atomic, explicitly confirmed journal adoption. */
final class AssetPartialDisposalRecords
{
    public function __construct(private PDO $db,private string $actorId){}

    private function rows(string $sql,array $args=[]):array
    {
        return DocumentCatalog::query($this->db,$sql,$args)->fetchAll();
    }

    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if(!BalanceLedger::enabled()||!Database::tableExists($this->db,'asset_partial_reviews'))throw new DomainException('Teilabgangsprüfung nicht freigeschaltet.');
        return $a;
    }

    private function transaction(callable $work):mixed
    {
        if($this->db->inTransaction())throw new DomainException('Prüfstand benötigt einen eigenen Vorgang.');
        $initial=$this->actor();$this->db->beginTransaction();
        try{
            $this->rows('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']]);
            $a=$this->actor();if($a['club_id']!==$initial['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            $result=$work($a);$this->db->commit();return $result;
        }catch(\JsonException $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw new DomainException('Gespeicherten Teilabgangs-Prüfverlauf prüfen.',0,$e);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private static function damaged():never{throw new DomainException('Gespeicherter Teilabgangs-Prüfverlauf ist nicht vollständig oder stimmt nicht überein.');}

    /** Validate the entire retained chain, not just the current pointer. */
    private function history(string $club,string $item,int $year):array
    {
        $rows=$this->rows('SELECT * FROM asset_partial_reviews WHERE club_id=? AND item_id=? AND tax_year=? ORDER BY revision LIMIT 1001',[$club,$item,$year]);
        if(count($rows)>1000)self::damaged();$out=[];$previous=null;
        foreach($rows as $i=>$row){
            $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_keys($p)!==['schema','kind','id','club_id','item_id','year','revision','request_key','request_sha256','previous_sha256','created_by','created_at','review']
                ||$p['schema']!==1||$p['kind']!=='prepared_partial_review'||$p['id']!==$row['id']||$p['club_id']!==$club||$p['item_id']!==$item
                ||$p['year']!==$year||$p['revision']!==$i+1||(int)$row['revision']!==$i+1||$p['request_key']!==$row['request_key']
                ||$p['request_sha256']!==$row['request_sha256']||$p['previous_sha256']!==$previous||!is_string($p['created_by'])||!is_string($p['created_at'])
                ||!hash_equals($row['sha256'],AssetPartialDisposal::hash($p))||!is_array($p['review']))self::damaged();
            $r=$p['review'];$hash=$r['hash']??null;unset($r['hash']);
            if(array_keys($r)!==['schema','postable','exportable','club_id','item_id','plan_version','year','year_version','plan','proof','evidence','purchase','annual','calculation']
                ||$r['schema']!==1||!is_string($hash)||!hash_equals($hash,AssetPartialDisposal::hash($r))||($r['postable']??null)!==false||($r['exportable']??null)!==false
                ||($r['club_id']??null)!==$club||($r['item_id']??null)!==$item||($r['year']??null)!==$year
                ||!is_int($r['plan_version'])||$r['plan_version']<1||!is_int($r['year_version'])||$r['year_version']<1
                ||!is_array($r['evidence'])||array_keys($r['evidence'])!==['disposal','allocation']||!is_array($r['purchase'])||!is_array($r['annual'])
                ||!is_array($r['plan']??null)||!is_array($r['proof']??null)||($r['calculation']??null)!==AssetPartialDisposal::preview($r['plan'],$r['proof'],$year)
                ||!hash_equals($p['request_sha256'],AssetPartialDisposal::hash([$p['created_by'],$item,$r['plan_version'],$year,$r['proof'],$hash,$i])))self::damaged();
            // Historical text can contain protected document names. Recheck today's
            // access to every original before returning retained evidence.
            foreach($r['evidence'] as $type=>$ref){
                $proof=$type==='disposal'?$r['proof']:$r['proof']['allocation'];
                if(!is_array($ref)||($ref['document_id']??null)!==$proof['document_id']||($ref['revision']??null)!==$proof['revision']||($ref['sha256']??null)!==$proof['sha256'])self::damaged();
                (new Documents($this->db,$this->actorId))->document($ref['document_id']);
            }
            $p['sha256']=$row['sha256'];$out[]=$p;$previous=$row['sha256'];
        }
        return $out;
    }

    private function record(string $club,string $id,bool $latest=false):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Prüfstand auswählen.');
        $row=$this->rows('SELECT item_id,tax_year,revision FROM asset_partial_reviews WHERE club_id=? AND id=?',[$club,$id])[0]??null;
        if(!$row)throw new DomainException('Prüfstand nicht verfügbar.');
        $history=$this->history($club,$row['item_id'],(int)$row['tax_year']);$r=$history[(int)$row['revision']-1]??null;
        if(!$r||$r['id']!==$id)self::damaged();
        if($latest&&$r['revision']!==count($history))throw new DomainException('Ein neuerer Prüfstand liegt vor. Bitte neu öffnen.');
        return $r+['is_latest'=>$r['revision']===count($history),'state'=>'prepared','postable'=>false,'exportable'=>false];
    }

    public function get(string $id):array
    {
        return $this->transaction(fn($a)=>$this->record($a['club_id'],$id));
    }

    public function evidenceChoices(string $item):array
    {
        $a=$this->actor(true);(new AssetBooks($this->db,$this->actorId))->get($item);
        $invoices=array_column($this->rows('SELECT entry_id FROM invoice_position_items WHERE club_id=? AND item_id=?',[$a['club_id'],$item]),'entry_id');
        $out=['disposal_document'=>[],'component_document'=>[]];
        foreach((new Documents($this->db,$this->actorId))->listing() as $d){
            if($d['origin']!=='upload'||$d['state']!=='active'||!in_array('finance',$d['scopes'],true))continue;
            $onItem=false;$onInvoice=false;
            foreach($d['links'] as $link){
                $onItem=$onItem||($link['source_type']==='item'&&$link['source_id']===$item);
                $onInvoice=$onInvoice||($link['source_type']==='entry'&&in_array($link['source_id'],$invoices,true));
            }
            if($onItem)$out['disposal_document'][]=$d;
            if($onItem||$onInvoice)$out['component_document'][]=$d;
        }
        return $out;
    }

    /** Browser fields identify evidence; revisions, hashes and all amounts are resolved server-side. */
    public function prepare(string $item,int $version,int $year,array $input):array
    {
        $a=$this->actor(true);$plan=(new AssetBooks($this->db,$this->actorId))->get($item);
        foreach(['component_cost','component_residual','component_document','disposal_document','disposal_date','disposal_reason','partial_confirmed'] as $field)
            if(!is_string($input[$field]??null))throw new DomainException('Teilkosten, Datum und Nachweise vollständig angeben.');
        if(!$plan['data']||$plan['version']!==$version||$input['partial_confirmed']!=='1')throw new DomainException('Teilkosten und unveränderte Nutzung ausdrücklich bestätigen.');
        $ref=function(string $id):array{$d=(new Documents($this->db,$this->actorId))->document($id,true);return ['document_id'=>$id,'revision'=>(int)$d['revision'],'sha256'=>$d['sha256']];};
        $proof=['schema'=>1,'kind'=>'partial_scrap','date'=>$input['disposal_date'],'reason'=>trim($input['disposal_reason'])]+$ref($input['disposal_document'])+[
            'plan_sha256'=>AssetPartialDisposal::hash($plan['data']),
            'allocation'=>['basis'=>'documented_component_cost','cost_cents'=>Finance::cents($input['component_cost']),
                'residual_cents'=>Finance::cents($input['component_residual'])]+$ref($input['component_document'])+[
                'reason'=>trim($input['disposal_reason']),'same_use_and_life'=>true,'confirmed'=>true],'no_proceeds'=>true,'complete'=>false];
        $preview=(new AssetPartialDisposalReview($this->db,$this->actorId))->preview($item,$version,$year,$proof);
        $history=$this->history($a['club_id'],$item,$year);
        if($history&&end($history)['review']['hash']===$preview['hash'])return $this->get(end($history)['id']);
        return $this->save($item,$version,$year,$proof,$preview['hash'],count($history),bin2hex(random_bytes(16)),true);
    }

    /** Retained proof for an effective annual stand; does not accept an arbitrary forecast. */
    public function forAnnual(array $annual):array
    {
        $a=$this->actor();[$plan,$proof]=AssetDisposal::unpack(json_decode($annual['snapshot'],true,64,JSON_THROW_ON_ERROR));
        if(($annual['club_id']??null)!==$a['club_id']||($proof['kind']??null)!=='partial_scrap')self::damaged();
        $year=(int)substr($proof['date'],0,4);$history=$this->history($a['club_id'],$annual['item_id'],$year);
        if(!$history)self::damaged();$r=end($history);$review=$r['review'];
        if($review['plan']!==$plan||$review['proof']!==$proof||$review['plan_version']!==(int)$annual['plan_version']||(int)$annual['year']<$year)self::damaged();
        $schedule=AssetDisposal::schedule($plan,(int)$annual['year'],$proof);
        foreach(['opening_cents','depreciation_cents','closing_cents'] as $key)if((int)$annual[$key]!==$schedule[$key])self::damaged();
        return $r;
    }

    /** One explicit confirmation adopts the checked component cost, annual values and journal. */
    public function book(string $id,string $expectedSha,bool $confirmed):array
    {
        if(!$confirmed||!preg_match('/^[a-f0-9]{64}$/D',$expectedSha))throw new DomainException('Teilabgang und Jahresbuchung ausdrücklich bestätigen.');
        return $this->transaction(function($a)use($id,$expectedSha){
            $a=$this->actor(true);$r=$this->record($a['club_id'],$id,true);$review=$r['review'];
            if(!hash_equals($r['sha256'],$expectedSha))throw new DomainException('Prüfstand geändert. Bitte neu öffnen.');
            // A repeat is rejected before touching data, including after a network retry.
            (new AssetPartialDisposalReview($this->db,$this->actorId))->previewWithinTransaction($r['item_id'],$review['plan_version'],$r['year'],$review['proof'],$review['hash']);
            $s=AssetDisposal::schedule($review['plan'],$r['year'],$review['proof']);
            $this->rows('INSERT INTO asset_book_years VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$r['item_id'],$r['year'],$review['plan_version'],
                $s['opening_cents'],$s['depreciation_cents'],$s['closing_cents'],$a['id'],gmdate('c'),json_encode(['plan'=>$review['plan'],'disposal'=>$review['proof']],JSON_THROW_ON_ERROR)]);
            $sourceId=$r['item_id'].':'.$r['year'];$sources=new BalanceSources($this->db,$this->actorId);
            $p=$sources->preview($r['year'],'depreciation',$sourceId,[],true);
            if($p['issues'])throw new DomainException(implode(' ',$p['issues']));
            $ids=(new BalanceLedger($this->db,$this->actorId))->importSourceWithinTransaction($r['year'],$review['year_version'],'depreciation',$sourceId,[],$p['hash'],true);
            $this->rows('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',
                [$a['club_id'],$a['id'],'finance.asset.partial.booked',$id,$r['revision'],gmdate('c')]);
            return ['record_id'=>$id,'source_id'=>$sourceId,'entry_ids'=>$ids,'state'=>'booked'];
        });
    }

    /** A retry returns the same retained preparation, not a new confirmation of its freshness. */
    public function save(string $item,int $version,int $year,array $proof,string $reviewHash,int $baseRevision,string $key,bool $confirmed):array
    {
        if(!$confirmed||!preg_match('/^[a-f0-9]{32}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$reviewHash)||$baseRevision<0||$baseRevision>=1000)
            throw new DomainException('Geprüfte Teilkosten und Prüfstand ausdrücklich bestätigen.');
        return $this->transaction(function($a)use($item,$version,$year,$proof,$reviewHash,$baseRevision,$key){
            $a=$this->actor(true);$club=$a['club_id'];
            $request=AssetPartialDisposal::hash([$a['id'],$item,$version,$year,$proof,$reviewHash,$baseRevision]);
            $old=$this->rows('SELECT id,request_sha256 FROM asset_partial_reviews WHERE club_id=? AND request_key=?',[$club,$key])[0]??null;
            if($old){if(!hash_equals($old['request_sha256'],$request))throw new DomainException('Vorgangskennung bereits für andere Angaben verwendet.');return $this->record($club,$old['id']);}
            $history=$this->history($club,$item,$year);
            if(count($history)!==$baseRevision)throw new DomainException('Prüfstand inzwischen geändert. Bitte neu laden.');
            if($history&&end($history)['review']['hash']===$reviewHash)throw new DomainException('Dieser Prüfstand ist bereits gespeichert.');
            $review=(new AssetPartialDisposalReview($this->db,$this->actorId))->previewWithinTransaction($item,$version,$year,$proof,$reviewHash);
            $id=bin2hex(random_bytes(16));$previous=$history?end($history)['sha256']:null;
            $payload=['schema'=>1,'kind'=>'prepared_partial_review','id'=>$id,'club_id'=>$club,'item_id'=>$item,'year'=>$year,
                'revision'=>$baseRevision+1,'request_key'=>$key,'request_sha256'=>$request,'previous_sha256'=>$previous,
                'created_by'=>$a['id'],'created_at'=>gmdate('c'),'review'=>$review];
            $this->rows('INSERT INTO asset_partial_reviews(club_id,id,item_id,tax_year,revision,request_key,request_sha256,payload,sha256) VALUES(?,?,?,?,?,?,?,?,?)',
                [$club,$id,$item,$year,$baseRevision+1,$key,$request,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),AssetPartialDisposal::hash($payload)]);
            $this->rows('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',
                [$club,$a['id'],'finance.asset.partial.prepared',$id,$baseRevision+1,$payload['created_at']]);
            return $this->record($club,$id,true);
        });
    }

    /** Future-year BOOK values only, after checking the latest stored review against live sources. */
    public function forecast(string $id,int $year):array
    {
        return $this->transaction(function($a)use($id,$year){
            $this->actor(true);$r=$this->record($a['club_id'],$id,true);$review=$r['review'];
            BalanceCalculation::year($year);if($year<$r['year'])throw new DomainException('Folgejahr ab Teilabgang auswählen.');
            (new AssetPartialDisposalReview($this->db,$this->actorId))->previewWithinTransaction($r['item_id'],$review['plan_version'],$r['year'],$review['proof'],$review['hash']);
            return ['record_id'=>$id,'record_sha256'=>$r['sha256'],'state'=>'forecast','postable'=>false,'exportable'=>false,
                'calculation'=>AssetPartialDisposal::preview($review['plan'],$review['proof'],$year)];
        });
    }
}
