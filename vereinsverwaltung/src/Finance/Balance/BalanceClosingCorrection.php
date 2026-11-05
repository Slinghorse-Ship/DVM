<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
require_once __DIR__.'/BalanceCalculation.php';
require_once __DIR__.'/BalanceSegmentCalculation.php';

/** Additive closing corrections. The original journal and every former closing survive. */
final class BalanceClosingCorrection
{
    public const KINDS=['error'=>'Fehler berichtigen','change'=>'Ansatz ändern','both'=>'Berichtigen und ändern','carry'=>'Berichtigtes Vorjahr übernehmen'];

    public static function note(mixed $value):string
    {
        if(!is_string($value)||trim($value)===''||mb_strlen($value)>1000||preg_match('/[\x00-\x1f]/',$value))throw new DomainException('Grund und Nachweis mit höchstens 1.000 Zeichen angeben.');
        return trim($value);
    }

    /** Neither chooses a tax option nor treats an internal allocation as an asset disposal. */
    public static function build(array $base,array $input,?array $prior=null):array
    {
        $period=isset($base['period'])?BalancePeriod::check($base['period'],$base['year']):null;
        if(($base['state']??null)!=='closed'||!preg_match('/^[a-f0-9]{64}$/D',$base['closed_sha256']??'')
            ||BalanceCalculation::build($base['year'],$base['accounts'],$base['opening'],$base['entries'],$period)!==$base['calculation'])throw new DomainException('Geprüften ursprünglichen Abschluss nachweisen.');
        if(array_keys($input)!==['kind','reason','legal_basis','confirmed','entries']||!is_string($input['kind'])||!isset(self::KINDS[$input['kind']])||$input['confirmed']!==true
            ||!is_array($input['entries'])||!array_is_list($input['entries'])||count($input['entries'])>100)throw new DomainException('Art, Zulässigkeit und Korrekturbuchungen ausdrücklich prüfen.');
        $input['reason']=self::note($input['reason']);$input['legal_basis']=self::note($input['legal_basis']);
        $year=$base['year'];$opening=$base['opening'];$previous=$base['previous_sha256'];
        if($prior!==null){
            $priorPeriod=isset($prior['period'])?BalancePeriod::check($prior['period'],$prior['year']):null;
            $follows=$period!==null?($priorPeriod!==null&&BalancePeriod::follows($priorPeriod,$period)):($priorPeriod===null&&$prior['year']===$year-1);
            if(($prior['state']??null)!=='closed'||!$follows||$prior['result_account']!==$base['result_account']
                ||!preg_match('/^[a-f0-9]{64}$/D',$prior['closed_sha256']??'')
                ||BalanceCalculation::build($prior['year'],$prior['accounts'],$prior['opening'],$prior['entries'],$priorPeriod)!==$prior['calculation'])throw new DomainException('Unveränderten geprüften Vorjahresabschluss nachweisen.');
            foreach($prior['accounts'] as $id=>$account)if(($base['accounts'][$id]??null)!==$account)throw new DomainException('Vorjahreskonten müssen unverändert im Folgejahr vorhanden sein.');
            $opening=BalanceCalculation::carryForward($prior['calculation'],$base['result_account']);$previous=$prior['closed_sha256'];
        }elseif($previous!==null)throw new DomainException('Den zugehörigen Vorjahresabschluss vollständig nachweisen.');
        $carryChanged=$opening!==$base['opening']||$previous!==$base['previous_sha256'];
        if($input['kind']==='carry'&&(!$carryChanged||$input['entries']))throw new DomainException('Vorjahresabgleich übernimmt ausschließlich die nachgewiesenen Anfangsbestände.');
        if(!$carryChanged&&!$input['entries'])throw new DomainException('Keine Änderung am Abschluss vorhanden.');
        $entries=$base['entries'];$seen=array_fill_keys(array_column($entries,'id'),true);$new=[];
        foreach($input['entries'] as $v){
            if(!is_array($v)||array_keys($v)!==['id','date','description','evidence','lines']||!is_string($v['id'])||!preg_match('/^[a-f0-9]{32}$/D',$v['id'])
                ||isset($seen[$v['id']])||!is_string($v['date'])||($period!==null?!BalancePeriod::contains($period,$v['date']):TaxCatalog::documentYear($v['date'])!==$year))throw new DomainException('Eindeutige Korrekturbuchung mit Datum aus diesem Abschlusszeitraum angeben.');
            $seen[$v['id']]=true;$v['description']=self::note($v['description']);$v['evidence']=self::note($v['evidence']);
            $v['lines']=BalanceCalculation::lines($v['lines'],$base['accounts']);
            // Corrections to depreciable assets must be made with an amended original
            // asset schedule, not a journal-only value which would disagree with it.
            foreach($v['lines'] as $l){$a=$base['accounts'][$l['account']];
                if($a['group']==='fixed_assets'||in_array($a['skr_code'],['62000','62200','62600','48450','48490','48550','68850','68890','68950','68960'],true))throw new DomainException('Anlagenkorrektur benötigt einen berichtigten Anlagen- und Belegnachweis; keine freie Gegenbuchung verwenden.');
            }
            $new[]=$v;$entries[]=$v+['reversal_of'=>null,'closing_correction'=>true];
        }
        $before=BalanceCalculation::build($year,$base['accounts'],$base['opening'],[],$period);
        $after=BalanceCalculation::build($year,$base['accounts'],$opening,[],$period);
        foreach($base['accounts'] as $id=>$a)if($a['group']==='fixed_assets'){
            if($before['accounts'][$id]['closing_cents']!==$after['accounts'][$id]['closing_cents'])throw new DomainException('Geänderte Anlagen-Anfangswerte zuerst mit dem Anlagenverzeichnis abstimmen.');
        }
        $calculation=BalanceCalculation::build($year,$base['accounts'],$opening,$entries,$period);
        if($calculation['issues'])throw new DomainException(implode(' ',$calculation['issues']));
        $input['entries']=$new;$deltas=[];
        foreach($calculation['accounts'] as $id=>$row){
            $before=$base['calculation']['accounts'][$id];
            if($row['opening_cents']!==$before['opening_cents']||$row['closing_cents']!==$before['closing_cents'])$deltas[$id]=[
                'opening_before_cents'=>$before['opening_cents'],'opening_after_cents'=>$row['opening_cents'],
                'closing_before_cents'=>$before['closing_cents'],'closing_after_cents'=>$row['closing_cents']];
        }
        $proof=['schema'=>1,'year'=>$year,'base_sha256'=>$base['closed_sha256'],'prior_sha256'=>$previous,'input'=>$input,
            'opening'=>$opening,'entries'=>$entries,'calculation'=>$calculation,'deltas'=>$deltas,'carry_changed'=>$carryChanged];
        if($period!==null)$proof['period']=$period;
        $proof['sha256']=BalanceSegmentCalculation::hash($proof);return $proof;
    }

    public static function report(array $base,array $proof):array
    {
        $r=$base;
        foreach(['opening','entries','calculation'] as $key)$r[$key]=$proof[$key];
        $r['previous_sha256']=$proof['prior_sha256'];$r['closed_sha256']=$proof['sha256'];$r['issues']=[];
        $r['correction']=['base_sha256'=>$proof['base_sha256'],'kind'=>$proof['input']['kind'],'reason'=>$proof['input']['reason'],'legal_basis'=>$proof['input']['legal_basis']];
        $r['ready_for_tax_export']=false;return $r;
    }
}
