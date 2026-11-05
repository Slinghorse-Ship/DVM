<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once __DIR__.'/BalancePeriodSourceQuery.php';
require_once __DIR__.'/BalanceReadSnapshot.php';

/** Bounded annual presentation; financial proposals and completeness remain separate. */
final class BalanceAnnualSourceCatalogue
{
    public const PAGE_SIZE=40;
    public function __construct(private PDO $db,private string $actorId){}
    private function actor():array{$actor=Permissions::actor($this->db,$this->actorId);if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');return $actor;}
    private function rows(string $sql,array $args):array{return DocumentCatalog::query($this->db,$sql,$args)->fetchAll(PDO::FETCH_ASSOC);}
    private static function performance():string
    {
        $issued="COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(o.payload)THEN o.payload ELSE '{}'END,'$.delivery_date')),''),e.document_date)";
        $assessed="(SELECT MIN(d.year)FROM member_dues_people d WHERE d.club_id=e.club_id AND d.entry_id=e.id)";
        return "CASE WHEN e.kind='contribution'THEN COALESCE(CASE WHEN LEFT(e.document_date,4)=$assessed THEN e.document_date ELSE CONCAT($assessed,'-01-01')END,e.document_date)ELSE $issued END";
    }
    private function scope(int $year,string $type,string $club):array
    {
        $from=$year.'-01-01';$to=($year+1).'-01-01';
        if($type==='invoice'){
            $performance=self::performance();$tables="finance_entries e LEFT JOIN outgoing_invoices o ON o.club_id=e.club_id AND o.entry_id=e.id AND o.kind='invoice'AND o.state='issued' LEFT JOIN balance_sources b ON b.club_id=e.club_id AND b.source_type='invoice'AND b.source_id=e.id LEFT JOIN balance_period_sources p ON p.club_id=e.club_id AND p.source_type='invoice'AND p.source_id=e.id";
            $where="e.club_id=? AND((e.status<>'cancelled'AND e.kind<>'donation'AND((e.kind<>'contribution'AND e.document_date>=? AND e.document_date<?)OR($performance>=? AND $performance<?)))OR b.tax_year=?)";
            $select="e.id,e.kind,e.display_number,e.description,e.document_date date,e.document_date,$performance performance_date,e.amount_cents,b.tax_year owner_year,p.period_id owner_period";
            return [$tables,$where,[$club,$from,$to,$from,$to,$year],$select,'e.document_date','e.amount_cents'];
        }
        if($type!=='transaction')throw new DomainException('Rechnung oder Bankumsatz auswählen.');
        return ["finance_transactions e LEFT JOIN balance_sources b ON b.club_id=e.club_id AND b.source_type='transaction'AND b.source_id=e.id LEFT JOIN balance_period_sources p ON p.club_id=e.club_id AND p.source_type='transaction'AND p.source_id=e.id",'e.club_id=? AND((e.booked_on>=? AND e.booked_on<?)OR b.tax_year=?)',[$club,$from,$to,$year],"e.id,''kind,''display_number,e.description,e.booked_on date,e.booked_on document_date,e.booked_on performance_date,e.cents amount_cents,b.tax_year owner_year,p.period_id owner_period",'e.booked_on','e.cents'];
    }
    private function filtered(int $year,string $type,string $club,array $query):array
    {
        [$from,$where,$args,$select,$date,$amount]=$this->scope($year,$type,$club);
        if($query['source_q']!==''){$like=BalancePeriodSourceQuery::like($query['source_q']);$where.=$type==='invoice'?" AND(e.description LIKE ? ESCAPE '~'OR e.display_number LIKE ? ESCAPE '~')":" AND e.description LIKE ? ESCAPE '~'";$args[]=$like;if($type==='invoice')$args[]=$like;}
        if($query['source_state']==='open')$where.=' AND b.source_id IS NULL AND p.source_id IS NULL';elseif($query['source_state']==='current'){$where.=' AND b.tax_year=?';$args[]=$year;}elseif($query['source_state']==='other'){$where.=' AND((b.tax_year IS NOT NULL AND b.tax_year<>?)OR p.source_id IS NOT NULL)';$args[]=$year;}
        if($query['source_from']!==''){$where.=' AND '.$date.'>=?';$args[]=$query['source_from'];}if($query['source_to']!==''){$where.=' AND '.$date.'<=?';$args[]=$query['source_to'];}
        if($query['source_amount']!==''){$where.=' AND ABS('.$amount.')=?';$args[]=Finance::cents($query['source_amount']);}
        if($query['source_direction']!=='all')$where.=$type==='invoice'?($query['source_direction']==='out'?" AND e.kind='expense'":" AND e.kind<>'expense'"):($query['source_direction']==='out'?' AND e.cents<0':' AND e.cents>0');
        return [$from,$where,$args,$select,$date];
    }
    public function page(int $year,array $input,string $selectedKind='invoice'):array
    {
        BalanceCalculation::year($year);$query=BalancePeriodSourceQuery::read($input,$selectedKind);$actor=$this->actor();
        return BalanceReadSnapshot::run($this->db,function()use($year,$query,$actor):array{
            $counts=[];foreach(['invoice','transaction']as$type){[$from,$where,$args]=$this->filtered($year,$type,$actor['club_id'],$query);$counts[$type]=(int)$this->rows('SELECT COUNT(*) total FROM '.$from.' WHERE '.$where,$args)[0]['total'];}
            $assets=$this->assets($year,$actor['club_id'],$query);$counts['depreciation']=count($assets);$total=$counts[$query['source_kind']];$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=(int)$query['source_page'];if($page>$pages)throw new DomainException('Verlaufsseite der Belegsuche vollständig auswählen.',422);$offset=($page-1)*self::PAGE_SIZE;
            if($query['source_kind']==='depreciation')$rows=array_slice($assets,$offset,self::PAGE_SIZE);else{[$from,$where,$args,$select,$date]=$this->filtered($year,$query['source_kind'],$actor['club_id'],$query);$rows=$this->rows('SELECT '.$select.' FROM '.$from.' WHERE '.$where.' ORDER BY '.$date.',e.id LIMIT '.self::PAGE_SIZE.' OFFSET '.$offset,$args);foreach($rows as &$row){$row['type']=$query['source_kind'];$row['amount_cents']=(int)$row['amount_cents'];}unset($row);}
            return ['query'=>$query,'rows'=>$rows,'counts'=>$counts,'total'=>$total,'page'=>$page,'pages'=>$pages,'page_size'=>self::PAGE_SIZE];
        });
    }
    private function assets(int $year,string $club,array $query):array
    {
        if($query['source_amount']!==''||$query['source_direction']!=='all')return [];
        $rows=$this->rows("SELECT y.item_id,y.depreciation_cents,y.snapshot,i.name,i.code,b.tax_year owner_year FROM asset_book_years y JOIN inventory_items i ON i.club_id=y.club_id AND i.id=y.item_id LEFT JOIN balance_sources b ON b.club_id=y.club_id AND b.source_type='depreciation'AND b.source_id=CONCAT(y.item_id,':',y.year) WHERE y.club_id=? AND y.year=? ORDER BY y.item_id LIMIT 10001",[$club,$year]);if(count($rows)>10000)throw new DomainException('Zu viele Belege für eine gemeinsame Jahresprüfung.');$out=[];
        foreach($rows as $row){$snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);[, $disposal]=AssetDisposal::unpack($snapshot);$event=$disposal&&TaxCatalog::documentYear($disposal['date'])===$year;$cost=isset($snapshot['valuation']);$partnership=isset($snapshot['partnership_book']);$own=isset($snapshot['own_book']);if((int)$row['depreciation_cents']<=0&&!$event&&!$cost&&!$partnership&&!$own)continue;$date=$event&&$disposal['kind']!=='partial_scrap'?$disposal['date']:$year.'-12-31';
            if($query['source_q']!==''&&mb_stripos($row['code'].' '.$row['name'],$query['source_q'])===false)continue;if(($query['source_from']!==''&&$date<$query['source_from'])||($query['source_to']!==''&&$date>$query['source_to']))continue;
            $owner=$row['owner_year']===null?null:(int)$row['owner_year'];if($query['source_state']==='open'&&$owner!==null)continue;if($query['source_state']==='current'&&$owner!==$year)continue;if($query['source_state']==='other'&&($owner===null||$owner===$year))continue;
            $name=($own?'HGB-Entwicklungsregister · ':($partnership?'HGB-Beteiligungsbewertung · ':($cost?'Anlagenbewertung · ':($event?match($disposal['kind']){'sale'=>'Anlagenverkauf · ','partial_scrap'=>'Teilabgang · ','software_end'=>'Lizenz beendet · ',default=>'Ausmusterung · '}:'Abschreibung · ')))).$row['name'];$out[]=['type'=>'depreciation','id'=>$row['item_id'].':'.$year,'kind'=>'','display_number'=>'','description'=>$row['name'],'system_label'=>mb_substr($name,0,mb_strlen($name)-mb_strlen($row['name'])),'date'=>$date,'document_date'=>$date,'performance_date'=>$date,'amount_cents'=>null,'owner_year'=>$owner,'owner_period'=>null];
        }
        usort($out,static fn(array $a,array $b):int=>[$a['date'],$a['id']]<=>[$b['date'],$b['id']]);return $out;
    }
    public function selected(int $year,string $type,string $id):array
    {
        BalanceCalculation::year($year);BalanceSourceDecisionSchema::identity($type,$id);$actor=$this->actor();
        if($type==='depreciation'){$query=BalancePeriodSourceQuery::read(['source_kind'=>'depreciation']);foreach($this->assets($year,$actor['club_id'],$query)as$row)if($row['id']===$id)return $row;throw new DomainException('Beleg im gewählten Kalenderjahr nicht gefunden.',404);}
        [$from,$where,$args,$select]=$this->scope($year,$type,$actor['club_id']);$where.=' AND e.id=?';$args[]=$id;$row=$this->rows('SELECT '.$select.' FROM '.$from.' WHERE '.$where,$args)[0]??null;if($row===null)throw new DomainException('Beleg im gewählten Kalenderjahr nicht gefunden.',404);$row['type']=$type;$row['amount_cents']=(int)$row['amount_cents'];return $row;
    }
}
