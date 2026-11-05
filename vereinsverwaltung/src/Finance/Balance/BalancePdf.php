<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once __DIR__.'/BalanceReadSnapshot.php';
require_once __DIR__.'/BalanceLedger.php';
require_once dirname(__DIR__, 2).'/Documents/DocumentPreview.php';

/** A read-only book working paper. It never approves a journal or a tax filing. */
final class BalancePdf
{
    public function __construct(private PDO $db,private string $actor){}
    public static function digest(array $report):string
    {
        return BalanceSegmentCalculation::hash(array_intersect_key($report,array_flip([
            'year','state','version','closed_sha256','correction','calculation','asset_schedule','issues'
        ])));
    }
    public function document(int $year,string $expected):array
    {
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new DomainException('Bilanz-PDF benötigt die MySQL-Buchhaltung.');
        BalanceCalculation::year($year);
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new DomainException('Aktuellen Bilanzstand zuerst anzeigen.');
        return BalanceReadSnapshot::run($this->db,function()use($year,$expected){
            $a=Permissions::actor($this->db,$this->actor);
            if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
            if(!BalanceLedger::enabled()||((new Settings($this->db,$this->actor))->section('tax')['data']['accounting_basis']??'euer')!=='balance')
                throw new DomainException('Bilanzbuchhaltung für diesen Verein nicht verfügbar.');
            $report=(new BalanceLedger($this->db,$this->actor))->report($year);
            if(in_array($report['state'],['open','closed'],true))$report['asset_schedule']=(new BalanceAssetSchedule($this->db,$this->actor))->build($year);
            if(!hash_equals(self::digest($report),$expected))throw new DomainException('Bilanzstand inzwischen geändert. Bitte neu anzeigen.');
            if(!is_array($report['calculation'])||!in_array($report['state'],['open','closed'],true))throw new DomainException('Zuerst die Anfangsbestände prüfen.');
            $check=(new BalanceSources($this->db,$this->actor))->reviewYear($year,false,$report['calculation']);
            $schedule=$report['asset_schedule'];
            $issues=array_values(array_unique(array_merge($report['issues'],$check['issues'],$schedule['issues'])));
            $name=DocumentCatalog::query($this->db,'SELECT name FROM clubs WHERE id=?',[$a['club_id']])->fetchColumn();
            if(!is_string($name)||trim($name)==='')throw new DomainException('Vereinsbezeichnung prüfen.');
            return ['balance_report'=>[
                'schema'=>1,'club'=>$name,'year'=>$year,'state'=>$report['state'],'version'=>$report['version'],
                'synthetic'=>getenv('VEREIN_MODE')==='synthetic','snapshot_sha256'=>$expected,
                'closed_sha256'=>$report['closed_sha256']??null,'correction'=>isset($report['correction']),
                'calculation'=>$report['calculation'],'assets'=>$schedule['items'],'asset_totals'=>$schedule['totals'],'issues'=>$issues,
                'tax_filing_approved'=>false
            ]];
        });
    }
    public function file(int $year,string $expected):array
    {
        $document=$this->document($year,$expected);
        return ['mime'=>'application/pdf','name'=>'vereinsbilanz-'.$year.'.pdf','bytes'=>DocumentPreview::pdf($document)];
    }
}
