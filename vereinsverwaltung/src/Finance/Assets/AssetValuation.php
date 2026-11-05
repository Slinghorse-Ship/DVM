<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Balance/BalancePeriod.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceCalculation.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceSegmentCalculation.php';

/** Evidenced cost-based assets. Decisions are explicit; no useful life or value inferred. */
final class AssetValuation
{
    public const FIELDS=['cost_opening_cents','additions_cents','disposals_cents','cost_closing_cents',
        'accumulated_opening_cents','impairment_cents','writeup_cents','accumulated_disposals_cents','accumulated_closing_cents',
                'book_opening_cents','book_disposals_cents','book_closing_cents'];
    private static function require(bool $ok,string $message):void
    {if(!$ok)throw new DomainException($message);}
    private static function cents(mixed $value,int $minimum=0):int
    {
        self::require(is_int($value)&&$value>=$minimum&&$value<=BalanceCalculation::LIMIT,'Bewertungsbeträge als vollständige Centbeträge prüfen.');
        return $value;
    }
    private static function note(mixed $value):void
    {self::require(is_string($value)&&trim($value)!==''&&mb_strlen($value)<=2000&&!preg_match('/[\x00-\x1f]/',$value),'Bewertung mit fachlicher Begründung und Rechtsgrundlage bestätigen.');}
    public static function document(mixed $value):void
    {
        self::require(is_array($value)&&!array_diff(array_keys($value),['document_id','revision','sha256'])
            &&is_string($value['document_id']??null)&&preg_match('/^[a-f0-9]{32}$/D',$value['document_id'])
            &&is_int($value['revision']??null)&&$value['revision']>=1
            &&is_string($value['sha256']??null)&&preg_match('/^[a-f0-9]{64}$/D',$value['sha256']),
            'Unveränderten Originalbeleg der Bewertung nachweisen.');
    }
    public static function plan(array $plan):void
    {
        self::require(!array_diff(array_keys($plan),['kind','basis_cents','acquired_on','sphere','account','reason','document','target','financial_profile'])
            &&in_array($plan['kind']??null,['land','financial','construction','intangible_advance'],true)
            &&is_string($plan['acquired_on']??null)&&TaxCatalog::documentYear($plan['acquired_on'])>=1900&&TaxCatalog::documentYear($plan['acquired_on'])<=2199
            &&in_array($plan['sphere']??null,['1','2','3','4'],true)
            &&is_string($plan['account']??null)&&preg_match('/^[0-9]{5}$/D',$plan['account']),
            'Nicht planmäßig abzuschreibende Anlage mit ursprünglichen Anschaffungskosten prüfen.');
        self::cents($plan['basis_cents']??null,1);self::note($plan['reason']??null);self::document($plan['document']??null);
        FinancialInvestment::check($plan);
        if(array_key_exists('target',$plan)||$plan['account']==='07000')AssetTypes::costTarget($plan);
    }
    public static function fromBookPlan(array $plan):array
    {
        self::require(($plan['method']??null)==='cost'&&($plan['months']??null)===0&&($plan['residual_cents']??null)===0
            &&!array_diff(array_keys($plan),['basis_cents','residual_cents','acquired_on','months','sphere','account','reason','method','valuation_kind','valuation_document','target','financial_profile']),
            'Anlage zu Anschaffungskosten benötigt ihren unveränderten Originalplan.');
        $out=['kind'=>$plan['valuation_kind']??null,'basis_cents'=>$plan['basis_cents']??null,'acquired_on'=>$plan['acquired_on']??null,
            'sphere'=>$plan['sphere']??null,'account'=>$plan['account']??null,'reason'=>$plan['reason']??null,'document'=>$plan['valuation_document']??null];
        if(array_key_exists('target',$plan))$out['target']=$plan['target'];
        if(array_key_exists('financial_profile',$plan))$out['financial_profile']=$plan['financial_profile'];
        self::plan($out);return $out;
    }
    public static function hash(array $value):string
    {return BalanceSegmentCalculation::hash($value);}
    /** A predecessor is replayed from original costs and its entire evidence, never trusted by saldo. */
    public static function calculate(array $plan,array $period,array $events=[],?array $previous=null,int $depth=0):array
    {
        self::require($depth<=100,'Bewertungsvorträge sind zu umfangreich.');self::plan($plan);$period=BalancePeriod::check($period);
        self::require($plan['acquired_on']<=$period['end']&&array_is_list($events)&&count($events)<=200,'Bewertungsereignisse im Abschlusszeitraum prüfen.');
        $cost=self::cents($plan['basis_cents'],1);$values=['book'=>$cost,'tax'=>$cost];$remaining=$cost;$seen=[];
        if($previous!==null){
            self::require(is_array($previous['source']??null)&&array_keys($previous['source'])===['plan','period','events','previous'],
                'Ursprünglichen Bewertungsvortrag nachweisen.');
            $source=$previous['source'];
            self::require($source['plan']===$plan&&BalancePeriod::follows($source['period'],$period)
                &&self::calculate($source['plan'],$source['period'],$source['events'],$source['previous'],$depth+1)===$previous,
                'Bewertungsvortrag wurde verändert oder gehört nicht zum unmittelbaren Vorzeitraum.');
            foreach(['book','tax'] as $side)$values[$side]=$previous[$side]['book_closing_cents'];
            $remaining=$previous['book']['cost_closing_cents'];$seen=array_fill_keys($previous['event_ids'],true);
        }else self::require($plan['acquired_on']>=$period['start'],'Vorgetragene Anlage benötigt ihren bestätigten Bewertungsstand.');
        $before=$plan['acquired_on']<$period['start'];$added=$before?0:$cost;$openingCost=$before?$remaining:0;$rows=[];
        foreach(['book','tax'] as $side){
            $rows[$side]=array_fill_keys(self::FIELDS,0);
            $rows[$side]['cost_opening_cents']=$openingCost;$rows[$side]['additions_cents']=$added;
            $rows[$side]['book_opening_cents']=$before?$values[$side]:0;
            $rows[$side]['accumulated_opening_cents']=$openingCost-$rows[$side]['book_opening_cents'];
        }
        $last=$period['start'];$movements=[];
        foreach($events as $event){
            self::require(is_array($event)&&!array_diff(array_keys($event),['id','on','kind','book_cents','tax_cents','cost_cents','permanent','tax_election','reason','legal_basis','document','allocation_document','confirmed','no_proceeds','counter_code'])
                &&is_string($event['id']??null)&&preg_match('/^[a-f0-9]{32}$/D',$event['id'])&&!isset($seen[$event['id']])
                &&is_string($event['on']??null)&&BalancePeriod::contains($period,$event['on'])&&$event['on']>=$plan['acquired_on']&&$event['on']>=$last
                &&($event['confirmed']??null)===true&&in_array($event['kind']??null,['impairment','writeup','disposal'],true),
                'Eindeutige bestätigte Bewertungsereignisse in zeitlicher Reihenfolge angeben.');
            if(array_key_exists('counter_code',$event)){self::require(is_string($event['counter_code'])&&$plan['kind']==='financial'&&isset($plan['financial_profile']),'Originales Bewertungsgegenkonto gehört zur bestätigten Finanzanlage.');FinancialInvestment::counterCode($event['kind'],$event['permanent']??true,$plan['financial_profile'],$event['counter_code'],$plan['account']);}
            self::note($event['reason']??null);self::note($event['legal_basis']??null);self::document($event['document']??null);
            self::require($remaining>0,'Eine vollständig abgegangene Anlage kann nicht erneut bewertet werden.');
            $seen[$event['id']]=true;$last=$event['on'];$changes=[];
            if($event['kind']==='disposal'){
                self::require(($event['no_proceeds']??null)===true&&!array_intersect(array_keys($event),['permanent','tax_election']),'Abgang ohne Erlös vollständig bestätigen; Verkauf mit Erlös gesondert belegen.');
                $release=self::cents($event['cost_cents']??null,1);self::require($release<=$remaining,'Abgangskosten überschreiten die ursprünglichen Restkosten.');
                self::document($event['allocation_document']??null);
                foreach(['book','tax'] as $side){
                    $carrying=self::cents($event[$side.'_cents']??null);
                    self::require($carrying<=$release&&$carrying<=$values[$side]&&$values[$side]-$carrying<=$remaining-$release
                        &&($release!==$remaining||$carrying===$values[$side]),'Teilabgang benötigt die belegten Kosten und Buch-/Steuerwerte des tatsächlich abgegangenen Teils.');
                    $rows[$side]['disposals_cents']+=$release;$rows[$side]['book_disposals_cents']+=$carrying;
                    $rows[$side]['accumulated_disposals_cents']+=$release-$carrying;$values[$side]-=$carrying;
                    $changes[$side]=['impairment_cents'=>0,'writeup_cents'=>0,'book_disposals_cents'=>$carrying];
                }
                $remaining-=$release;
            }else{
                self::require(!array_intersect(array_keys($event),['cost_cents','allocation_document','no_proceeds']),'Kostenabgang nicht als reine Wertänderung buchen.');
                self::require(is_bool($event['permanent']??null)&&is_bool($event['tax_election']??null),'Dauer der Wertminderung und steuerliche Bewertung ausdrücklich feststellen.');
                $book=self::cents($event['book_cents']??null);$tax=self::cents($event['tax_cents']??null);
                self::require($book<=$remaining&&$tax<=$remaining,'Wertaufholung höchstens bis zu den ursprünglichen verbliebenen Anschaffungskosten.');
                if($event['kind']==='impairment'){
                    self::require($book<=$values['book']&&($book<$values['book']||$tax<$values['tax'])&&($event['permanent']||$plan['kind']==='financial')
                        &&$tax<=$values['tax']&&($event['tax_election']?$event['permanent']&&$tax<$values['tax']:$tax===$values['tax']),
                        'Außerplanmäßige Buchabschreibung und optionalen dauerhaften steuerlichen Teilwert getrennt prüfen.');
                }else self::require($book>=$values['book']&&$tax>=$values['tax']&&($book>$values['book']||$tax>$values['tax'])
                    &&$event['permanent']===false&&$event['tax_election']===false,'Wertaufholung nur für weggefallene Minderungsgründe ohne neues steuerliches Wahlrecht bestätigen.');
                foreach(['book'=>$book,'tax'=>$tax] as $side=>$value){
                    $down=max(0,$values[$side]-$value);$up=max(0,$value-$values[$side]);
                    $rows[$side]['impairment_cents']+=$down;$rows[$side]['writeup_cents']+=$up;$values[$side]=$value;
                    $changes[$side]=['impairment_cents'=>$down,'writeup_cents'=>$up,'book_disposals_cents'=>0];
                }
            }
            foreach($rows as $row)foreach($row as $amount)self::cents($amount);
            $movements[]=['event_id'=>$event['id'],'on'=>$event['on'],'kind'=>$event['kind'],'book'=>$changes['book'],'tax'=>$changes['tax']];
        }
        foreach(['book','tax'] as $side){
            $rows[$side]['cost_closing_cents']=$remaining;$rows[$side]['book_closing_cents']=$values[$side];
            $rows[$side]['accumulated_closing_cents']=$remaining-$values[$side];self::reconcile($rows[$side]);
        }
        $source=['plan'=>$plan,'period'=>$period,'events'=>$events,'previous'=>$previous];
        $out=['schema'=>1,'source'=>$source,'event_ids'=>array_keys($seen),'book'=>$rows['book'],'tax'=>$rows['tax'],'movements'=>$movements];
        return $out+['sha256'=>self::hash($out)];
    }
    public static function reconcile(array $s):void
    {
        self::require(!array_diff(array_keys($s),self::FIELDS),'Unbekannte Bewertungsbewegung.');
        foreach(self::FIELDS as $field)self::cents($s[$field]??null);
        self::require($s['cost_opening_cents']+$s['additions_cents']-$s['disposals_cents']===$s['cost_closing_cents']
            &&$s['accumulated_opening_cents']+$s['impairment_cents']-$s['writeup_cents']-$s['accumulated_disposals_cents']===$s['accumulated_closing_cents']
            &&$s['cost_opening_cents']-$s['accumulated_opening_cents']===$s['book_opening_cents']
            &&$s['cost_closing_cents']-$s['accumulated_closing_cents']===$s['book_closing_cents']
            &&$s['disposals_cents']-$s['accumulated_disposals_cents']===$s['book_disposals_cents']
            &&$s['book_opening_cents']+$s['additions_cents']-$s['impairment_cents']+$s['writeup_cents']-$s['book_disposals_cents']===$s['book_closing_cents'],
            'Ursprüngliche Kosten, Wertminderung, Wertaufholung und Abgänge stimmen nicht überein.');
    }
}
