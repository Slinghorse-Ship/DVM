<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once __DIR__.'/BalancePeriodSourceQuery.php';
require_once __DIR__.'/BalanceReadSnapshot.php';

/** Bounded presentation and complete source ownership checks share the exact period predicate. */
final class BalancePeriodSourceCatalogue
{
    public const PAGE_SIZE=40;
    public function __construct(private PDO$db,private string$actorId){}
    private function actor():array{$a=Permissions::actor($this->db,$this->actorId);if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');return$a;}
    private function rows(string$sql,array$args):array{return DocumentCatalog::query($this->db,$sql,$args)->fetchAll(PDO::FETCH_ASSOC);}
    private static function performance():string
    {
        $issued="COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(o.payload)THEN o.payload ELSE '{}'END,'$.delivery_date')),''),e.document_date)";
        $year="(SELECT MIN(d.year)FROM member_dues_people d WHERE d.club_id=e.club_id AND d.entry_id=e.id)";
        $contribution="CASE WHEN LEFT(e.document_date,4)=$year THEN e.document_date ELSE CONCAT($year,'-01-01')END";
        return"CASE WHEN e.kind='contribution'THEN COALESCE($contribution,e.document_date)ELSE $issued END";
    }
    private function scope(array$period,string$type,string$club):array
    {
        if($type==='invoice'){
            $performance=self::performance();$from="finance_entries e LEFT JOIN outgoing_invoices o ON o.club_id=e.club_id AND o.entry_id=e.id AND o.kind='invoice' AND o.state='issued' LEFT JOIN balance_period_sources b ON b.club_id=e.club_id AND b.source_type='invoice' AND b.source_id=e.id";
            $where="e.club_id=? AND e.status<>'cancelled' AND e.kind<>'donation' AND((e.kind<>'contribution'AND e.document_date BETWEEN ? AND ?)OR $performance BETWEEN ? AND ?)";
            $select="e.id,e.kind,e.display_number,e.description,e.document_date date,e.document_date,$performance performance_date,e.amount_cents,b.period_id owner_period,b.event_revision owner_revision";
            return[$from,$where,[$club,$period['start'],$period['end'],$period['start'],$period['end']],$select,'e.document_date','e.amount_cents'];
        }
        if($type!=='transaction')throw new DomainException('Rechnung oder Bankumsatz auswählen.');
        return["finance_transactions e LEFT JOIN balance_period_sources b ON b.club_id=e.club_id AND b.source_type='transaction'AND b.source_id=e.id",'e.club_id=? AND e.booked_on BETWEEN ? AND ?',[$club,$period['start'],$period['end']],"e.id,''kind,''display_number,e.description,e.booked_on date,e.booked_on document_date,e.booked_on performance_date,e.cents amount_cents,b.period_id owner_period,b.event_revision owner_revision",'e.booked_on','e.cents'];
    }
    private function filtered(array$period,string$type,string$club,array$q):array
    {
        [$from,$where,$args,$select,$date,$amount]=$this->scope($period,$type,$club);$id=BalancePeriod::key($period);
        if($q['source_q']!==''){$like=BalancePeriodSourceQuery::like($q['source_q']);$where.=$type==='invoice'?" AND(e.description LIKE ? ESCAPE '~'OR e.display_number LIKE ? ESCAPE '~')":" AND e.description LIKE ? ESCAPE '~'";$args[]=$like;if($type==='invoice')$args[]=$like;}
        if($q['source_state']==='open')$where.=' AND b.source_id IS NULL';elseif($q['source_state']!=='all'){$where.=$q['source_state']==='current'?' AND b.period_id=?':' AND b.period_id IS NOT NULL AND b.period_id<>?';$args[]=$id;}
        if($q['source_from']!==''){$where.=' AND '.$date.'>=?';$args[]=$q['source_from'];}if($q['source_to']!==''){$where.=' AND '.$date.'<=?';$args[]=$q['source_to'];}
        if($q['source_amount']!==''){$where.=' AND ABS('.$amount.')=?';$args[]=Finance::cents($q['source_amount']);}
        if($q['source_direction']!=='all')$where.=$type==='invoice'?($q['source_direction']==='out'?" AND e.kind='expense'":" AND e.kind<>'expense'"):($q['source_direction']==='out'?' AND e.cents<0':' AND e.cents>0');
        return[$from,$where,$args,$select,$date];
    }
    public function page(array$period,array$input,string$selectedKind='invoice'):array
    {
        $period=BalancePeriod::check($period);$q=BalancePeriodSourceQuery::read($input,$selectedKind);$actor=$this->actor();
        return BalanceReadSnapshot::run($this->db,function()use($period,$q,$actor){
            $counts=[];foreach(['invoice','transaction']as$type){[$from,$where,$args]=$this->filtered($period,$type,$actor['club_id'],$q);$counts[$type]=(int)$this->rows('SELECT COUNT(*) total FROM '.$from.' WHERE '.$where,$args)[0]['total'];}
            $assets=$this->assets($period,$actor['club_id'],$q);$counts['depreciation']=count($assets);$total=$counts[$q['source_kind']];$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=(int)$q['source_page'];if($page>$pages)throw new DomainException('Verlaufsseite der Belegsuche vollständig auswählen.',422);$offset=($page-1)*self::PAGE_SIZE;
            if($q['source_kind']==='depreciation')$rows=array_slice($assets,$offset,self::PAGE_SIZE);else{[$from,$where,$args,$select,$date]=$this->filtered($period,$q['source_kind'],$actor['club_id'],$q);$rows=$this->rows('SELECT '.$select.' FROM '.$from.' WHERE '.$where.' ORDER BY '.$date.',e.id LIMIT '.self::PAGE_SIZE.' OFFSET '.$offset,$args);foreach($rows as&$row){$row['type']=$q['source_kind'];$row['amount_cents']=(int)$row['amount_cents'];if(!TaxCatalog::documentYear($row['performance_date']))$row['performance_date']=$row['document_date'];}unset($row);}
            return['query'=>$q,'rows'=>$rows,'counts'=>$counts,'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>self::PAGE_SIZE];
        });
    }
    private function assets(array$period,string$club,array$q):array
    {
        if($q['source_amount']!==''||$q['source_direction']!=='all')return[];
        $matches=null;if($q['source_q']!==''){$like=BalancePeriodSourceQuery::like($q['source_q']);$matches=array_fill_keys(array_column($this->rows("SELECT id FROM inventory_items WHERE club_id=? AND(code LIKE ? ESCAPE '~'OR name LIKE ? ESCAPE '~')",[$club,$like,$like]),'id'),true);}
        $owners=[];foreach($this->rows("SELECT source_id,period_id,event_revision FROM balance_period_sources WHERE club_id=? AND source_type='depreciation'AND source_id LIKE ?",[$club,'%:'.BalancePeriod::key($period)])as$row)$owners[$row['source_id']]=$row;
        $out=[];foreach((new BalancePeriodDepreciation($this->db,$this->actorId))->candidates($period)as$row){$item=explode(':',$row['id'],2)[0];if($matches!==null&&!isset($matches[$item]))continue;if(($q['source_from']!==''&&$row['date']<$q['source_from'])||($q['source_to']!==''&&$row['date']>$q['source_to']))continue;$owner=$owners[$row['id']]??null;if($q['source_state']==='open'&&$owner!==null)continue;if($q['source_state']==='current'&&($owner['period_id']??null)!==BalancePeriod::key($period))continue;if($q['source_state']==='other'&&($owner===null||$owner['period_id']===BalancePeriod::key($period)))continue;$out[]=['type'=>'depreciation','id'=>$row['id'],'kind'=>'','display_number'=>'','description'=>$row['name'],'date'=>$row['date'],'document_date'=>$row['date'],'performance_date'=>$row['date'],'amount_cents'=>null,'owner_period'=>$owner['period_id']??null,'owner_revision'=>$owner['event_revision']??null];}
        return$out;
    }
    /** Selected originals remain reachable outside the current page/filter. */
    public function selected(array$period,string$type,string$id):array
    {
        $period=BalancePeriod::check($period);BalancePeriodSources::identity($type,$id);$actor=$this->actor();$key=BalancePeriod::key($period);
        if($type==='depreciation'){$q=BalancePeriodSourceQuery::read(['source_kind'=>'depreciation']);foreach($this->assets($period,$actor['club_id'],$q)as$row)if($row['id']===$id)return$row;throw new DomainException('Beleg im gewählten Abschlusszeitraum nicht gefunden.',404);}
        [$from,$where,$args,$select]=$this->scope($period,$type,$actor['club_id']);
        // The first club predicate and the selected identity are outside the OR.
        // A current original binding preserves a later moved/cancelled own invoice.
        $base=$where;$first=strpos($base,' AND ');if($first===false)throw new DomainException('Vollständigen Belegbezug prüfen.');$rest=substr($base,$first+5);
        $args=array_merge([$actor['club_id'],$id],array_slice($args,1),[$key]);$rows=$this->rows('SELECT '.$select.' FROM '.$from.' WHERE e.club_id=? AND e.id=? AND(('.$rest.')OR b.period_id=?)',$args);$row=$rows[0]??null;
        if($row===null)throw new DomainException('Beleg im gewählten Abschlusszeitraum nicht gefunden.',404);
        if(($row['owner_period']??null)===$key){$binding=(new BalancePeriodSources($this->db,$this->actorId))->binding($type,$id);if(!$binding||$binding['period_id']!==$key)throw new DomainException('Belegzuordnung und Buchungsverlauf stimmen nicht überein.');}
        $row['type']=$type;$row['amount_cents']=(int)$row['amount_cents'];if(!TaxCatalog::documentYear($row['performance_date']))$row['performance_date']=$row['document_date'];return$row;
    }
    /** Complete check independent of page/search; only the detailed presentation is bounded. */
    public function missing(array$period):array
    {
        $period=BalancePeriod::check($period);$actor=$this->actor();$issues=[];
        foreach(['invoice','transaction']as$type){[$from,$where,$args,$select,$date]=$this->scope($period,$type,$actor['club_id']);$where.=$type==='invoice'?' AND b.source_id IS NULL':' AND(b.source_id IS NULL OR b.period_id<>?)';if($type==='transaction')$args[]=BalancePeriod::key($period);$count=(int)$this->rows('SELECT COUNT(*) total FROM '.$from.' WHERE '.$where,$args)[0]['total'];if($count===0)continue;
            $rows=$this->rows('SELECT '.$select.' FROM '.$from.' WHERE '.$where.' ORDER BY '.$date.',e.id LIMIT 20',$args);
            foreach($rows as$row){if($type==='invoice')$issues[]='Rechnung noch nicht übernommen: '.$row['display_number'].'.';elseif($row['owner_period']===null)$issues[]='Bankumsatz vom '.$row['date'].' noch nicht übernommen.';else$issues[]='Bankumsatz gehört inzwischen in einen anderen Zeitraum. Ursprüngliche Buchung abstimmen.';}
            if($count>20)$issues[]=($type==='invoice'?'Noch abzustimmende Rechnungen insgesamt: ':'Noch abzustimmende Bankumsätze insgesamt: ').$count.'.';
        }
        return$issues;
    }
}
