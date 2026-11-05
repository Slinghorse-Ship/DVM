<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

require_once __DIR__.'/AssetPartialDisposal.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceLedger.php';

/** Read-only evidence review. Deliberately no route, persistence or posting API yet. */
final class AssetPartialDisposalReview
{
    public function __construct(private PDO $db,private string $actorId){}

    private function rows(string $sql,array $args=[]):array
    {
        return DocumentCatalog::query($this->db,$sql,$args)->fetchAll();
    }

    private function access():array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if($a['finance_access']!=='write')throw new DomainException('Finanz-Schreibberechtigung erforderlich.');
        $tax=(new Settings($this->db,$this->actorId))->section('tax')['data'];
        if(!BalanceLedger::enabled()||($tax['accounting_basis']??'')!=='balance')
            throw new DomainException('Teilabgang nur im freigeschalteten Bilanzjournal prüfen.');
        $fiscal=$tax['fiscal_start']??'';
        if(!is_string($fiscal)||($fiscal!==''&&(!TaxCatalog::documentYear($fiscal)||substr($fiscal,5)!=='01-01')))
            throw new DomainException('Abweichendes Wirtschaftsjahr gesondert abstimmen.');
        return $a;
    }

    /** A retained hash alone does not prove the source or its actual journal entries. */
    private function source(BalanceSources $sources,string $type,string $id,int $year):array
    {
        $b=$sources->binding($type,$id);
        if(!$b||(int)$b['tax_year']!==$year)throw new DomainException('Anschaffung und bisherige Abschreibungen zuerst in das Bilanzjournal übernehmen.');
        $p=$sources->preview($year,$type,$id,$b['options']);
        if($p['issues']||!hash_equals($b['source_hash'],$p['hash']))
            throw new DomainException('Ursprungsbeleg oder Buchwertplan geändert. Zuerst abstimmen.');
        if(!(new BalanceSourceHistory($this->db,$this->actorId))->checked($b))
            throw new DomainException('Vollständiger Buchungsnachweis fehlt. Altbestand gesondert abstimmen.');
        return $b;
    }

    /** No OCR guess: explicit, accessible, linked originals with their exact file versions. */
    private function evidence(string $club,string $item,string $invoice,array $ref,bool $allocation):array
    {
        $d=(new Documents($this->db,$this->actorId))->document($ref['document_id'],true);
        if($d['state']!=='active'||$d['origin']!=='upload'||!in_array('finance',$d['scopes'],true)
            ||(int)$d['revision']!==$ref['revision']||!hash_equals($d['sha256'],$ref['sha256']))
            throw new DomainException('Nachweis fehlt, wurde geändert oder ist kein aktives Finanz-Original.');
        $linked=false;
        foreach($d['links'] as $link){
            if(($link['source_type']==='item'&&$link['source_id']===$item)
                ||($allocation&&$link['source_type']==='entry'&&$link['source_id']===$invoice))$linked=true;
        }
        if(!$linked)throw new DomainException($allocation
            ?'Teilkosten-Nachweis dem Gegenstand oder seiner Anschaffungsrechnung zuordnen.'
            :'Ausmusterungsnachweis dem betroffenen Gegenstand zuordnen.');
        $this->fileHash($club,$ref);
        return ['document_id'=>$d['id'],'version'=>(int)$d['version'],'revision'=>(int)$d['revision'],
            'sha256'=>$d['sha256'],'title'=>$d['title']];
    }

    private function fileHash(string $club,array $ref):void
    {
        // Check bytes on the database server; originals never leave it for this review.
        $file=$this->rows('SELECT SHA2(bytes,256) actual_hash FROM document_files WHERE club_id=? AND document_id=? AND revision=?',[$club,$ref['document_id'],$ref['revision']])[0]??null;
        if(!$file||!is_string($file['actual_hash'])||!hash_equals($ref['sha256'],$file['actual_hash']))
            throw new DomainException('Originaldatei und Prüfsumme stimmen nicht überein.');
    }

    /**
     * Review ONE first partial scrapping in the next unconfirmed, completed calendar year.
     * Holds the same club lock as journal/asset writers, writes no rows, changes no plan.
     * The resulting digest must be checked again by a future posting implementation.
     */
    public function preview(string $item,int $version,int $year,array $proof,?string $expectedHash=null):array
    {
        return $this->execute($item,$version,$year,$proof,$expectedHash,false);
    }

    /** The caller owns commit/rollback; the same club lock and checks still apply. */
    public function previewWithinTransaction(string $item,int $version,int $year,array $proof,string $expectedHash):array
    {
        return $this->execute($item,$version,$year,$proof,$expectedHash,true);
    }

    private function execute(string $item,int $version,int $year,array $proof,?string $expectedHash,bool $within):array
    {
        if($this->db->inTransaction()!==$within)throw new DomainException('Teilabgangsprüfung benötigt den vorgesehenen eigenen oder gemeinsamen Vorgang.');
        try{
            $initial=$this->access();
            BalanceCalculation::year($year);
            if($year>=(int)date('Y'))throw new DomainException('Nur abgeschlossene Kalenderjahre prüfen.');
            if(!$within)$this->db->beginTransaction();
            $this->rows('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']]);
            $a=$this->access();$club=$a['club_id'];
            if($club!==$initial['club_id'])throw new DomainException('Vereinszuordnung geändert. Bitte neu laden.');
            $asset=(new AssetBooks($this->db,$this->actorId))->get($item);
            if(!$asset['data']||$version!==$asset['version'])throw new DomainException('Anlagenplan fehlt oder wurde geändert.');
            if($asset['item']['ownership']!=='club'||$asset['item']['cash_account']!==null)
                throw new DomainException('Nur eigene Sachanlagen prüfen.');
            $plan=$asset['data'];
            $calculation=AssetPartialDisposal::preview($plan,$proof,$year);
            if(TaxCatalog::documentYear($proof['date'])!==$year)
                throw new DomainException('Teilabgang muss im ausgewählten Jahr liegen.');
            $state=$this->rows('SELECT state,version FROM balance_years WHERE club_id=? AND tax_year=?',[$club,$year])[0]??null;
            if(!$state||$state['state']!=='open'||$this->rows('SELECT tax_year FROM balance_years WHERE club_id=? AND tax_year>? LIMIT 1',[$club,$year]))
                throw new DomainException('Das nächste offene Bilanzjahr verwenden. Festgeschriebene Folgejahre bleiben unverändert.');
            $links=$this->rows('SELECT entry_id FROM invoice_position_items WHERE club_id=? AND item_id=?',[$club,$item]);
            if(count($links)!==1)throw new DomainException('Gegenstand muss genau einer Anschaffungsposition zugeordnet sein.');
            $invoice=$links[0]['entry_id'];$start=TaxCatalog::documentYear($plan['acquired_on']);
            $sources=new BalanceSources($this->db,$this->actorId);
            $purchase=$this->source($sources,'invoice',$invoice,$start);
            foreach($purchase['snapshot']['documents'] as $document){
                if($document['state']==='active'&&$document['origin']==='upload')
                    $this->fileHash($club,['document_id'=>$document['id'],'revision'=>(int)$document['revision'],'sha256'=>$document['sha256']]);
            }
            $original=array_values(array_filter($purchase['snapshot']['assets']??[],static fn($v)=>$v['item_id']===$item));
            if(count($original)!==1||(int)$original[0]['version']!==$version
                ||json_decode($original[0]['payload'],true,64,JSON_THROW_ON_ERROR)!==$plan
                ||!is_string($purchase['snapshot']['asset_accounts'][$item]??null))
                throw new DomainException('Anschaffungsbuchung und ursprünglicher Anlagenplan stimmen nicht überein.');
            $evidence=['disposal'=>$this->evidence($club,$item,$invoice,$proof,false),
                'allocation'=>$this->evidence($club,$item,$invoice,$proof['allocation'],true)];
            $annual=[];$next=$start;
            foreach($asset['years'] as $row){
                $previous=(int)$row['year'];
                [$stored,$disposal]=AssetDisposal::unpack(json_decode($row['snapshot'],true,64,JSON_THROW_ON_ERROR));
                if($previous!==$next||$previous>=$year||$disposal!==null||$stored!==$plan||(int)$row['plan_version']!==$version)
                    throw new DomainException('Jahresstände fehlen, sind geändert oder enthalten bereits einen Abgang.');
                $schedule=AssetBooks::schedule($plan,$previous);
                foreach(['opening_cents','depreciation_cents','closing_cents'] as $key)
                    if((int)$row[$key]!==$schedule[$key])throw new DomainException('Bisherige Abschreibung stimmt nicht mit dem ursprünglichen Plan überein.');
                $b=$sources->binding('depreciation',$item.':'.$previous);
                if($b||$schedule['depreciation_cents']>0)$b=$this->source($sources,'depreciation',$item.':'.$previous,$previous);
                $closed=(new BalanceLedger($this->db,$this->actorId))->report($previous);
                if($closed['state']!=='closed')throw new DomainException('Vorherige Bilanzjahre zuerst abschließen.');
                // A fully depreciated year has no artificial zero-euro journal entry.
                $annual[]=['annual'=>$row,'source_hash'=>$b['source_hash']??null,'history_sha256'=>$b['history_sha256']??null,
                    'closed_sha256'=>$closed['closed_sha256']];
                ++$next;
            }
            if($next!==$year)throw new DomainException('Bisherige Jahresabschreibungen zuerst lückenlos feststellen.');
            if($this->rows('SELECT item_id FROM asset_sale_links WHERE club_id=? AND item_id=?',[$club,$item])
                ||$sources->binding('depreciation',$item.':'.$year))
                throw new DomainException('Für diesen Gegenstand besteht bereits ein Abgang oder Jahresbuchungsnachweis.');
            $out=['schema'=>1,'postable'=>false,'exportable'=>false,'club_id'=>$club,'item_id'=>$item,
                'plan_version'=>$version,'year'=>$year,'year_version'=>(int)$state['version'],
                'plan'=>$plan,'proof'=>$proof,'evidence'=>$evidence,
                'purchase'=>['entry_id'=>$invoice,'source_hash'=>$purchase['source_hash'],
                    'history_sha256'=>$purchase['history_sha256'],'asset_account'=>$purchase['snapshot']['asset_accounts'][$item]],
                'annual'=>$annual,'calculation'=>$calculation];
            $out['hash']=AssetPartialDisposal::hash($out);
            if($expectedHash!==null&&!hash_equals($out['hash'],$expectedHash))
                throw new DomainException('Prüfstand inzwischen geändert. Teilabgang erneut prüfen.');
            if(!$within)$this->db->commit();return $out;
        }catch(\JsonException $e){
            if(!$within&&$this->db->inTransaction())$this->db->rollBack();
            throw new DomainException('Gespeicherte Beleg- oder Anlagenangaben sind beschädigt. Betrieb prüfen.',0,$e);
        }catch(\Throwable $e){if(!$within&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
