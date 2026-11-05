<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodTaxReconciliations.php';
require_once __DIR__.'/BalanceWholeClubReport.php';

/** Bind an official draft to the exact immutable dated tax/area/journal chain. */
final class BalancePeriodFilingSource
{
    public function __construct(private PDO $db,private string $actor){}
    public function current(string $id):array
    {
        $state=(new BalancePeriodTaxReconciliations($this->db,$this->actor))->get($id);
        if(!$state['current_approval']||$state['values']===null)throw new DomainException('Zuerst Abschluss, Bereichsabgleich und Steuerwerte dieses Zeitraums vom Vorstand prüfen lassen.');
        return self::report($id,end($state['history']));
    }
    private static function report(string $id,array $row):array
    {
        $r=BalanceWholeClubReport::fromReport($row['values']['income']['scope']['source']);
        if($row['state']!=='reviewed'||BalancePeriod::key($r['period'])!==$id||!in_array($r['year'],[2025,2026],true)||$r['period']['start']<'2025-01-01')throw new DomainException('Geprüften Abschlusszeitraum für die E-Bilanz auswählen.');
        return $r+['subsidiary_review'=>['binding'=>['year'=>$r['year'],'period_id'=>$id,'period'=>$r['period'],'revision'=>$row['revision'],'sha256'=>$row['sha256']],'values'=>$row['values']]];
    }
    public static function binding(array $b):string
    {
        $id=$b['period_id']??null;$period=$b['period']??null;
        if(!is_string($id)||!is_array($period)||BalancePeriod::key(BalancePeriod::check($period,$b['year']))!==$id)throw new DomainException('Abgabe und tatsächlicher Abschlusszeitraum stimmen nicht überein.');
        return $id;
    }
    public static function matches(array $b,array $r):void
    {
        self::binding($b);
        if($b['period']!==$r['period']||($b['source_binding']??null)!==$r['subsidiary_review']['binding']
            ||($b['journal_sha256']??null)!==$r['closed_sha256']||($b['closing_source_sha256']??null)!==$r['closed_sha256'])throw new DomainException('Zeitraum, Abschluss oder Steuerfreigabe geändert. Einen neuen Entwurf anlegen.');
    }
    /** Historical receipts use their original review, not today's closing approval. */
    public function historical(array $b,string $hash):array
    {
        $id=self::binding($b);$ref=$b['source_binding']??[];
        if(!is_int($ref['revision']??null)||$ref['revision']<1)throw new DomainException('Ursprünglichen Steuerprüfstand nachweisen.');
        // get() reconstructs every historical workpaper against immutable area and
        // journal proofs, including the exact prior-value chain. No live overwrite.
        $state=(new BalancePeriodTaxReconciliations($this->db,$this->actor))->get($id);
        $row=$state['history'][$ref['revision']-1]??null;
        if(!$row)throw new DomainException('Ursprüngliche Steuerprüfung fehlt.');
        $r=self::report($id,$row);self::matches($b,$r);
        if($r['closed_sha256']!==$hash)throw new DomainException('Übermittlungsnachweis gehört zu einem anderen Abschlussstand.');
        return $r;
    }
}
