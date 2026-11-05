<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/OwnIntangibleStorage.php';

use DomainException;
require_once __DIR__.'/BalanceCalculation.php';
require_once __DIR__.'/BalanceAssetAllocation.php';

/** Exact, evidence-backed subdivisions of one retained club ledger. No new postings. */
final class BalanceSegmentCalculation
{
    public static function hash(array $value):string
    {
        return hash('sha256',json_encode(OwnIntangibleStorage::normal($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public static function units(array $units):array
    {
        if(!$units||count($units)>32)throw new DomainException('Zwischen 1 und 32 Vereinsbereiche oder Betriebe angeben.');
        $out=[];$names=[];
        foreach($units as $id=>$unit){
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$id)||!is_array($unit)||array_diff(array_keys($unit),['name','sphere']))throw new DomainException('Betriebskennung prüfen.');
            $name=$unit['name']??null;$sphere=$unit['sphere']??null;
            if(!is_string($name)||trim($name)===''||mb_strlen($name)>120||preg_match('/[\x00-\x1f]/',$name)||!in_array($sphere,['1','2','3','4'],true))throw new DomainException('Name und Vereinsbereich des Betriebs prüfen.');
            $key=mb_strtolower(trim($name));if(isset($names[$key]))throw new DomainException('Jeden Betrieb eindeutig benennen.');$names[$key]=true;
            $out[$id]=['name'=>trim($name),'sphere'=>$sphere];
        }
        ksort($out,SORT_STRING);return $out;
    }

    /** These rows retain both sides, all reversals, and their original line ordinals. */
    public static function sources(array $report):array
    {
        if(($report['state']??null)!=='closed'||!empty($report['issues'])||!preg_match('/^[a-f0-9]{64}$/D',$report['closed_sha256']??''))throw new DomainException('Zuerst den vollständigen Vereinsabschluss festschreiben.');
        $calculation=BalanceCalculation::build($report['year'],$report['accounts'],$report['opening'],$report['entries'],$report['period']??null);
        if($calculation!==$report['calculation']||$calculation['issues'])throw new DomainException('Gesamtabschluss und Buchungsnachweise stimmen nicht überein.');
        $out=[];
        foreach(['opening'=>['description'=>'Anfangsbestände','date'=>$report['period']['start']??$report['year'].'-01-01','lines'=>$report['opening']],...array_column($report['entries'],null,'id')] as $id=>$entry){
            if($id!=='opening'&&(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)))throw new DomainException('Eindeutige Originalbuchung erforderlich.');
            foreach($entry['lines'] as $ordinal=>$line){
                $key=$id.':'.$ordinal;
                $out[$key]=['voucher'=>(string)$id,'ordinal'=>$ordinal,'date'=>$entry['date'],'description'=>$entry['description']??(string)$id,'line'=>$line,'cents'=>$line['debit_cents']+$line['credit_cents']];
            }
        }
        return $out;
    }

    /** Missing allocations are visible blockers, never a zero-value or inferred business. */
    public static function build(array $report,array $units,array $allocations,array $capitalCarry=[],array $transfers=[],array $assetShares=[]):array
    {
        return BalanceSourceRead::run(static fn()=>self::buildFromSources($report,$units,$allocations,$capitalCarry,$transfers,$assetShares));
    }
    private static function buildFromSources(array $report,array $units,array $allocations,array $capitalCarry=[],array $transfers=[],array $assetShares=[]):array
    {
        $units=self::units($units);$sources=self::sources($report);
        $capitalCarry=self::capitalCarry($report,$units,$capitalCarry);
        if(array_diff(array_keys($allocations),array_keys($sources)))throw new DomainException('Zuordnung enthält eine fremde Buchungszeile.');
        $normalized=[];$missing=[];$opening=[];$vouchers=[];$issues=[];
        foreach($units as $id=>$_){$opening[$id]=[];$vouchers[$id]=[];}
        foreach($sources as $key=>$source){
            if($source['voucher']==='opening'&&isset($capitalCarry[$source['line']['account']])){
                if(isset($allocations[$key]))throw new DomainException('Kapitalvortrag ist bereits aus dem Vorjahr übernommen. Nicht nochmals zuordnen.');
                continue;
            }
            if(!isset($allocations[$key])){$missing[]=$key;continue;}
            $row=$allocations[$key];
            if(!is_array($row)||array_diff(array_keys($row),['parts','reason'])||!is_array($row['parts']??null)||!$row['parts']||array_diff(array_keys($row['parts']),array_keys($units))||!is_string($row['reason']??null)||trim($row['reason'])===''||mb_strlen($row['reason'])>1000||preg_match('/[\x00-\x1f]/',$row['reason']))throw new DomainException('Aufteilung mit Betrag, Betrieb und Nachweis angeben.');
            $sum=0;$parts=$row['parts'];ksort($parts,SORT_STRING);
            foreach($parts as $unit=>$amount){
                if(!is_int($amount)||$amount<=0||$amount>$source['cents'])throw new DomainException('Aufteilungsbeträge müssen positive, ganze Centbeträge sein.');
                $sum+=$amount;
                $line=$source['line'];
                if($line['sphere']!==''&&$line['sphere']!==$units[$unit]['sphere'])throw new DomainException('Betrieb und Vereinsbereich der Originalbuchung widersprechen sich. Zuerst die Buchführung klären.');
                $line['debit_cents']=$line['debit_cents']>0?$amount:0;$line['credit_cents']=$line['credit_cents']>0?$amount:0;
                if($source['voucher']==='opening')$opening[$unit][]=$line;
                else{
                    $voucher=$source['voucher'];
                    $vouchers[$unit][$voucher]??=['id'=>$voucher,'date'=>$source['date'],'description'=>$source['description'],'lines'=>[]];
                    $vouchers[$unit][$voucher]['lines'][]=$line;
                }
            }
            if($sum!==$source['cents'])throw new DomainException('Die Aufteilung muss exakt dem Originalbetrag entsprechen.');
            $normalized[$key]=['parts'=>$parts,'reason'=>trim($row['reason'])];
        }
        foreach($capitalCarry as $account=>$parts)foreach($parts as $unit=>$net)$opening[$unit][]=self::capitalLine($account,$net);
        $transfers=self::transfers($report,$units,$normalized,$transfers);
        $eliminations=[];
        foreach($transfers as $voucher=>$transfer)foreach($transfer['parts'] as $unit=>$net){
            $vouchers[$unit][$voucher]['lines'][]=self::capitalLine($transfer['account'],$net);
            if($net>0)$eliminations[$transfer['account']]=($eliminations[$transfer['account']]??0)+$net;
        }
        if($missing)$issues[]='Noch '.count($missing).' Buchungszeile(n) zuordnen.';
        $segments=[];
        foreach($units as $id=>$unit){
            $local=[];
            foreach(['opening'=>['lines'=>$opening[$id]],...$vouchers[$id]] as $voucher=>$value){
                $difference=array_sum(array_column($value['lines'],'debit_cents'))-array_sum(array_column($value['lines'],'credit_cents'));
                if($difference!==0)$local[]=['voucher'=>(string)$voucher,'difference_cents'=>$difference];
            }
            $calculation=null;
            if(!$local&&!$missing){
                $calculation=BalanceCalculation::build($report['year'],$report['accounts'],$opening[$id],array_values($vouchers[$id]),$report['period']??null);
                foreach($calculation['issues'] as $message)$issues[]=$unit['name'].': '.$message;
            }
            if($local)$issues[]=$unit['name'].': '.count($local).' Aufteilung(en) sind nicht ausgeglichen. Keine automatische Ausgleichsbuchung.';
            $segments[$id]=$unit+['opening'=>$opening[$id],'entries'=>array_values($vouchers[$id]),'unbalanced'=>$local,'calculation'=>$calculation];
        }
        // With every source line assigned exactly once, the full debit/credit trial
        // balance must also reconcile account by account, not just as one net total.
        if(!$issues){
            foreach($report['calculation']['accounts'] as $account=>$row)foreach(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'] as $field){
                $sum=0;foreach($segments as $segment)$sum+=$segment['calculation']['accounts'][$account][$field];
                if(in_array($field,['debit_cents','credit_cents'],true))$sum-=$eliminations[$account]??0;
                if($sum!==$row[$field])throw new DomainException('Teilbilanzen ergeben nicht den vollständigen Vereinsabschluss.');
            }
            foreach($report['calculation']['spheres'] as $sphere=>$row)foreach(['income_cents','expense_cents','result_cents'] as $field){
                $sum=0;foreach($segments as $segment)$sum+=$segment['calculation']['spheres'][$sphere][$field];
                if($sum!==$row[$field])throw new DomainException('Vereinsbereiche und Betriebsergebnisse stimmen nicht überein.');
            }
        }
        $assetData=$assetShares?BalanceAssetAllocation::build($report,$units,$segments,$assetShares):null;
        if($assetData)$issues=array_merge($issues,$assetData['issues']);
        ksort($normalized,SORT_STRING);
        $result=['schema'=>$assetShares?4:($transfers?3:($capitalCarry?2:1)),'year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'units'=>$units,'allocations'=>$normalized];
        if($capitalCarry||$transfers||$assetShares)$result['capital_carry']=$capitalCarry;
        if($transfers||$assetShares)$result['transfers']=$transfers;
        if($assetData)$result['asset_shares']=$assetData['shares'];
        $result['segments']=$segments;
        if($assetData)$result['asset_schedules']=$assetData['schedules'];
        $result+=['missing'=>$missing,'issues'=>$issues,'complete'=>!$issues,'tax_export_ready'=>false];
        if(isset($report['period']))$result['period']=BalancePeriod::check($report['period'],$report['year']);
        return $result+['sha256'=>self::hash($result)];
    }

    /** Schema 1 stays byte-identical. Schema 2 retains opposing capital, not new debt. */
    public static function rebuild(array $report,array $allocation):array
    {
        return self::build($report,$allocation['units']??[],$allocation['allocations']??[],$allocation['capital_carry']??[],$allocation['transfers']??[],$allocation['asset_shares']??[]);
    }

    /** Explicit permanent internal funding only, never an inferred loan or taxable sale. */
    public static function transferParts(array $report,array $units,array $allocations,string $voucher):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$voucher))throw new DomainException('Eine laufende Originalbuchung auswählen. Anfangsbestände nicht ausgleichen.');
        $parts=[];$found=false;
        foreach(self::sources($report) as $key=>$source){
            if($source['voucher']!==$voucher)continue;$found=true;
            if(!isset($allocations[$key]))throw new DomainException('Zuerst alle Zeilen dieser Buchung zuordnen.');
            foreach($allocations[$key]['parts'] as $unit=>$amount){
                if(!isset($units[$unit])||!is_int($amount)||$amount<=0)throw new DomainException('Buchungszuordnung prüfen.');
                $parts[$unit]=($parts[$unit]??0)+($source['line']['debit_cents']>0?-$amount:$amount);
                if(abs($parts[$unit])>BalanceCalculation::LIMIT)throw new DomainException('Betragsgrenze der internen Mittelübertragung überschritten.');
            }
        }
        $parts=array_filter($parts,static fn($v)=>$v!==0);ksort($parts,SORT_STRING);
        if(!$found||count($parts)<2||array_sum($parts)!==0)throw new DomainException('Keine vollständig zugeordnete bereichsübergreifende Zahlung vorhanden.');
        return $parts;
    }

    private static function transfers(array $report,array $units,array $allocations,array $transfers):array
    {
        if(count($transfers)>10000)throw new DomainException('Zu viele interne Mittelübertragungen.');
        $out=[];
        foreach($transfers as $voucher=>$t){
            if(!is_string($voucher)||!is_array($t)||array_diff(array_keys($t),['account','parts','reason'])
                ||!is_string($t['account']??null)||($report['accounts'][$t['account']]['group']??null)!=='equity'
                ||!is_array($t['parts']??null)||!is_string($t['reason']??null)||trim($t['reason'])===''||mb_strlen($t['reason'])>1000||preg_match('/[\x00-\x1f]/',$t['reason']))throw new DomainException('Interne Mittelübertragung mit Kapitalkonto und Begründung prüfen.');
            $parts=$t['parts'];ksort($parts,SORT_STRING);
            if($parts!==self::transferParts($report,$units,$allocations,$voucher))throw new DomainException('Die Buchungsaufteilung wurde geändert. Interne Mittelübertragung erst aufheben und erneut prüfen.');
            $out[$voucher]=['account'=>$t['account'],'parts'=>$parts,'reason'=>trim($t['reason'])];
        }
        ksort($out,SORT_STRING);return $out;
    }

    public static function capitalLine(string $account,int $net):array
    {
        return ['account'=>$account,'debit_cents'=>max(0,$net),'credit_cents'=>max(0,-$net),'sphere'=>''];
    }

    /** Signed cents: debit positive, credit negative. Only the carry workflow supplies this. */
    private static function capitalCarry(array $report,array $units,array $carry):array
    {
        if(!$carry)return [];
        if(!is_string($report['previous_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$report['previous_sha256']))throw new DomainException('Gegenläufige Kapitalbestände benötigen den nachgewiesenen Vorjahresabschluss.');
        $net=[];foreach($report['opening'] as $line)$net[$line['account']]=$line['debit_cents']-$line['credit_cents'];
        foreach($carry as $account=>&$parts){
            if(!is_string($account)||($report['accounts'][$account]['group']??null)!=='equity'||!is_array($parts)||!$parts||array_diff(array_keys($parts),array_keys($units)))throw new DomainException('Kapitalvortrag und fortgeführte Bereiche prüfen.');
            $sum=0;$positive=false;$negative=false;
            foreach($parts as $amount){
                if(!is_int($amount)||$amount===0||abs($amount)>BalanceCalculation::LIMIT)throw new DomainException('Kapitalvortrag in ganzen Cent prüfen.');
                $sum+=$amount;$positive=$positive||$amount>0;$negative=$negative||$amount<0;
            }
            if(!$positive||!$negative||$sum!==($net[$account]??0))throw new DomainException('Bereichskapital muss genau dem Anfangsbestand des Vereins entsprechen.');
            ksort($parts,SORT_STRING);
        }unset($parts);
        ksort($carry,SORT_STRING);return $carry;
    }

    /** A suggestion only: explicit review/save is still required. No account-name guesses. */
    public static function suggest(array $report,array $units):array
    {
        $units=self::units($units);$sources=self::sources($report);$byVoucher=[];$out=[];
        foreach($sources as $key=>$row)$byVoucher[$row['voucher']][$key]=$row;
        foreach($byVoucher as $voucher=>$rows){
            if($voucher==='opening')continue;
            $spheres=array_unique(array_filter(array_column(array_column($rows,'line'),'sphere'),static fn($v)=>$v!==''));
            if(count($spheres)!==1)continue;
            $eligible=array_keys(array_filter($units,static fn($u)=>$u['sphere']===reset($spheres)));
            if(count($eligible)!==1)continue;
            foreach($rows as $key=>$row)$out[$key]=['parts'=>[$eligible[0]=>$row['cents']],'reason'=>'Vorschlag aus eindeutigem Vereinsbereich der Originalbuchung; fachlich bestätigen.'];
        }
        return $out;
    }

    /** Carry retained unit balances without turning old income into current income. */
    public static function carry(array $priorReport,array $priorAllocation,array $report):array
    {
        $prior=self::rebuild($priorReport,$priorAllocation);
        if(!$prior['complete']||$prior!==$priorAllocation||!self::follows($priorReport,$report)||($report['result_account']??null)!==($priorReport['result_account']??null))throw new DomainException('Bestätigte Aufteilung des direkten Vorzeitraums erforderlich.');
        foreach($priorReport['accounts'] as $id=>$account)if(!isset($report['accounts'][$id])||$account['group']!==$report['accounts'][$id]['group']||$account['skr_code']!==$report['accounts'][$id]['skr_code'])throw new DomainException('Konten des Vorjahres wurden geändert. Vortrag gesondert prüfen.');
        $sources=self::sources($report);$carried=[];
        foreach($prior['segments'] as $id=>$segment)foreach(BalanceCalculation::carryForward($segment['calculation'],$priorReport['result_account']) as $line)$carried[$line['account']][$id]=$line['debit_cents']-$line['credit_cents'];
        $allocations=[];$used=[];$capitalCarry=[];
        foreach($carried as $account=>$parts)if(($report['accounts'][$account]['group']??null)==='equity'&&min($parts)<0&&max($parts)>0){
            $capitalCarry[$account]=$parts;$used[$account]=true;
        }
        foreach($sources as $key=>$source){
            if($source['voucher']!=='opening')continue;
            $account=$source['line']['account'];$parts=[];$debit=$source['line']['debit_cents']>0;
            if(isset($capitalCarry[$account]))continue;
            foreach($carried[$account]??[] as $id=>$amount){
                if(($amount>0)!==$debit)throw new DomainException('Gegensätzliche Bereichssalden vor dem Vortrag getrennt ausweisen.');
                $parts[$id]=abs($amount);
            }
            if(!$parts||array_sum($parts)!==$source['cents'])throw new DomainException('Anfangsbestände stimmen nicht mit den Teilbilanzen des Vorjahres überein.');
            $allocations[$key]=['parts'=>$parts,'reason'=>'Unveränderter Vortrag aus der geprüften Betriebsaufteilung '.$priorReport['year'].'.'];$used[$account]=true;
        }
        if(array_diff_key($carried,$used))throw new DomainException('Bereichssaldo fehlt in den Anfangsbeständen.');
        $shares=self::carryAssetShares($priorReport,$prior,$report);
        return self::build($report,$prior['units'],$allocations,$capitalCarry,[],$shares);
    }

    public static function carryAssetShares(array $priorReport,array $prior,array $report):array
    {
        $shares=[];
        foreach($prior['asset_shares']??[] as $id=>$share){
            $old=$priorReport['asset_schedule']['items'][$id];$new=$report['asset_schedule']['items'][$id]??null;
            if(!is_array($new)||($new['acquisition_hash']??null)!==$old['acquisition_hash']||($new['account']??null)!==$old['account']||($new['sphere']??null)!==$old['sphere'])throw new DomainException('Gemeinsam genutzte Anlage im Folgejahr unverändert nachweisen.');
            foreach(['skr_code','acquired_on','months','residual_cents','gwg','degressive'] as $f)if(($new[$f]??null)!==($old[$f]??null))throw new DomainException('Ursprünglichen Abschreibungsplan im Folgejahr unverändert beibehalten.');
            if(($new['method']??'linear_monthly')!==($old['method']??'linear_monthly'))throw new DomainException('Abschreibungsart nicht durch die Nutzungsaufteilung ändern.');
            if(($old['method']??null)==='cost'&&(($new['cost_plan']??null)!==($old['cost_plan']??null)||($new['valuation']['source']['previous']??null)!==($old['valuation']??null)))throw new DomainException('Ursprüngliche Kostenbewertung unverändert vortragen.');
            if(isset($old['disposal']))foreach(['basis_cents','disposal','disposal_hash','disposal_accounts','partial_plan'] as $f)if(($new[$f]??null)!==($old[$f]??null))throw new DomainException('Bereits festgeschriebenen Anlagenabgang im Folgejahr unverändert beibehalten.');
            foreach(['cost','accumulated','book'] as $f)if(($new[$f.'_opening_cents']??null)!==$old[$f.'_closing_cents'])throw new DomainException('Anfangswerte der gemeinsam genutzten Anlage stimmen nicht mit dem Vorjahr überein.');
            $shares[$id]=array_replace($share,['item_sha256'=>self::hash($new)]);
        }
        return $shares;
    }

    /** Calendar evidence retains its original hash; dated successors use exact boundaries. */
    public static function follows(array $prior,array $report):bool
    {
        return ($report['previous_sha256']??null)===($prior['closed_sha256']??null)
            &&is_string($prior['closed_sha256']??null)
            &&BalancePeriod::follows($prior['period']??BalancePeriod::calendar($prior['year']),$report['period']??BalancePeriod::calendar($report['year']));
    }
}
