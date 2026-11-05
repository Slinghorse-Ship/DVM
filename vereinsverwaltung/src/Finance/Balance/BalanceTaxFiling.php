<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;
require_once __DIR__.'/BalanceLedger.php';
require_once __DIR__.'/BalanceTaxValues.php';
require_once __DIR__.'/BalanceTaxMapping.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/TaxFilings.php';
require_once __DIR__.'/BalanceFilingEnvironment.php';
require_once dirname(__DIR__).'/Tax/TaxBalanceRelease.php';
require_once __DIR__.'/BalanceSegments.php';
require_once __DIR__.'/BalanceClubTaxReport.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
require_once __DIR__.'/BalanceCombinedClubValues.php';
require_once __DIR__.'/BalanceClearings.php';
require_once __DIR__.'/BalanceTaxReconciliations.php';
require_once __DIR__.'/BalanceSubsidiaryClubValues.php';
require_once __DIR__.'/BalanceFilingRevision.php';
require_once __DIR__.'/BalancePeriodFilingSource.php';
require_once __DIR__.'/BalancePeriodWholeFiling.php';
require_once __DIR__.'/BalancePeriodBusinessFiling.php';
require_once __DIR__.'/BalancePeriodClubFiling.php';
require_once __DIR__.'/BalanceReadSnapshot.php';
require_once __DIR__.'/BalancePartnershipStoredSources.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/PartnershipHoldingReview.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/OwnIntangibleReview.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/PoolOriginalReview.php';
require_once __DIR__.'/BalanceReinsuranceStoredSources.php';
require_once __DIR__.'/BalanceOriginalLandStoredSources.php';
require_once __DIR__.'/BalanceOriginalLeasingStoredSources.php';
require_once __DIR__.'/BalanceExceptionalSources.php';
require_once __DIR__.'/BalanceSubstanceSources.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/PensionOffsetReview.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/SpecialTax7gReview.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/SpecialTax7bReview.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/BasisReduction7gReview.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/BasisReduction6bReview.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/TaxEqualizationReview.php';
require_once dirname(__DIR__, 2).'/Finance/Accounting/FinancialReserveIntake.php';

/** Own generated filings. Never imports arbitrary XML or changes the closed journal. */
final class BalanceTaxFiling
{
    public const VERSION='Bilanz_6_9';
    public const COMBINED='tax_privileged_club_with_sgeb';
    public const COMBINED_SOURCE='complete_club_with_approved_allocation';
    public const SUBSIDIARY=BalanceSubsidiaryClubValues::KIND;
    public const SUBSIDIARY_SOURCE='complete_club_with_reviewed_subsidiary';
    public function __construct(private PDO $db,private string $actor,private ?BalanceFilingRuntime $runtime=null){}
    public static function enabled(string $environment='test'):bool{return BalanceFilingEnvironment::enabled($environment);}
    public static function environments():array
    {return array_values(array_filter(['test','production'],static fn(string $environment):bool=>self::enabled($environment)));}
    private function access(bool $write=true,?string $environment=null,bool $historical=false):array
    {
        $a=Permissions::actor($this->db,$this->actor);
        if((!$historical&&!($environment===null?(self::enabled('test')||self::enabled('production')):self::enabled($environment)))||!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('E-Bilanz ist für diese Umgebung oder Finanzberechtigung nicht verfügbar.');
        if(((new Settings($this->db,$this->actor))->section('tax')['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Für diesen Verein ist keine Bilanz eingestellt.');
        return $a;
    }
    private static function json(mixed $v):string{return json_encode(OwnIntangibleStorage::normal($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private function q(string $sql,array $args):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function filing(string $id):array{return (new TaxFilings($this->db,$this->actor,$this->runtime))->get($id);}
    private function calendarSourceOnly(int $year):void
    {
        $a=Permissions::actor($this->db,$this->actor);
        if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if($this->q('SELECT 1 FROM balance_periods WHERE club_id=? AND starts_on<=? AND ends_on>=? LIMIT 1',[$a['club_id'],$year.'-12-31',$year.'-01-01'])->fetchColumn())throw new DomainException('Für diesen Abschluss ist ein eigener Zeitraum hinterlegt. Er darf nicht als Kalenderjahr übermittelt werden.');
    }
    private static function editable(array $r,int $version):void
    {
        if((int)$r['version']!==$version||!in_array($r['state'],['draft','validated','failed'],true))throw new DomainException('Dieser Stand wurde bereits bearbeitet. Bitte neu laden; bei unklarem Versand nicht erneut senden.');
    }
    private function report(int $year,bool $wholeTaxable=false):array
    {
        if(!in_array($year,[2025,2026],true))throw new DomainException('Für dieses Jahr fehlt der geprüfte E-Bilanz-Stand.');
        // A taxable WHOLE corporation exports the unchanged complete journal.
        // Analytical inter-area advances cancel there; no partial tax projection
        // is used. Other profiles must retain their area/clearing approval gate.
        if(!$wholeTaxable)(new BalanceClearings($this->db,$this->actor))->assertExportable($year);
        $r=(new BalanceLedger($this->db,$this->actor))->report($year);
        if($r['state']!=='closed'||$r['issues'])throw new DomainException('Zuerst den vollständigen Jahresabschluss prüfen und festschreiben.');
        return $r;
    }
    /** This distinct profile is backed by the complete reviewed clearing/tax chain. */
    private function subsidiaryReport(int $year):array
    {
        if(!in_array($year,[2025,2026],true))throw new DomainException('Für dieses Jahr fehlt der geprüfte E-Bilanz-Stand.');
        $s=(new BalanceTaxReconciliations($this->db,$this->actor))->get($year);
        if(!$s['current_approval']||$s['values']===null)throw new DomainException('Zuerst Bereichsabgleich und steuerliche Nebenrechnung vom Vorstand prüfen lassen.');
        $r=BalanceWholeClubReport::fromReport($s['values']['income']['scope']['source']);
        $last=end($s['history']);
        return $r+['subsidiary_review'=>['binding'=>['year'=>$year,'revision'=>$s['version'],'sha256'=>$last['sha256']],'values'=>$s['values']]];
    }
    public static function targets():array
    {
        $registry=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);$out=[];
        foreach($registry['rules'] as $rule){
            $out[$rule['group']][$rule['target']]=AssetTypes::mappingLabel($rule);
            foreach($rule['target_labels']??[] as $target=>$label)$out[$rule['group']][$target]=$label;
        }
        $out['receivables']+=['bs.ass.currAss.receiv.trade.shareholders'=>'Gegen Gesellschafter','bs.ass.currAss.receiv.trade.misc'=>'Nicht aufschlüsselbar'];
        $out['liabilities']+=['bs.eqLiab.liab.trade.shareholders'=>'Gegenüber Gesellschaftern','bs.eqLiab.liab.trade.misc'=>'Nicht aufschlüsselbar'];
        $out['equity']['bs.eqLiab.equity.retainedEarnings']='Gewinn-/Verlustvortrag (Kapitalgesellschaft)';
        $out['member_payables']['bs.eqLiab.liab.other.other']='Erstattungen an Mitglieder';
        $out['revenue']['is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.taxExemptUStG19_1']='Umsatz · Kleinunternehmer (§ 19 UStG)';
        foreach(BalanceCostAssetMapping::targets() as $group=>$targets)$out[$group]=($out[$group]??[])+$targets;
        foreach(BalanceOwnIntangibleMapping::targets() as $group=>$targets)$out[$group]=($out[$group]??[])+$targets;
        $out['fixed_assets'][BalancePoolOriginalValues::TARGET]='Sammelposten · ursprüngliche Jahreswahl';
        $out['expense'][BalancePoolOriginalValues::EXPENSE]='Auflösung Sammelposten';
        return $out;
    }
    private function clubReport(int $year,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Vorjahreskette der Vereinsaufteilung prüfen.');
        (new BalanceClearings($this->db,$this->actor))->assertExportable($year);
        $state=(new BalanceSegments($this->db,$this->actor))->get($year);
        $previous=($state['journal']['previous_sha256']??null)!==null?$this->clubReport($year-1,$depth+1):null;
        return BalanceClubTaxReport::fromState($state,$previous);
    }
    private static function scopeBinding(array $report):array
    {
        $out=[];$scope=$report['club_scope'];
        do{$out[]=['year'=>$scope['source']['year'],'journal_sha256'=>$scope['source']['closed_sha256'],'review_revision'=>$scope['review']['revision'],'review_sha256'=>$scope['review']['sha256']];$scope=$scope['previous'];}while($scope!==null);
        return $out;
    }
    /** Used by both editing and rendering: never fall back to the whole club ledger. */
    public function sourceReport(array $r,bool $readOnly=false):array
    {
        // Keep the repeated source models of one verification in one consistent
        // read. The scope ends before the caller persists an edited draft.
        return BalanceReadSnapshot::run($this->db,fn()=>$this->sourceReportSnapshot($r,$readOnly));
    }
    private function sourceReportSnapshot(array $r,bool $readOnly):array
    {
        $b=$this->binding($r,!$readOnly);$kind=$b['profile']['kind']??null;
        if(array_key_exists('period_id',$b)||array_key_exists('period',$b)){
            if(!$readOnly||!BalanceFilingEnvironment::submitted($r))$this->requireQualifiedPeriodPolicy((string)($b['period_id']??''));
            if(in_array($kind,['club_taxable_businesses',self::COMBINED],true)){
                $combined=$kind===self::COMBINED;
                if(($b['profile']['source_scope']??null)!==($combined?self::COMBINED_SOURCE:'approved_club_allocation')||($b['profile']['tax_privileged']??null)!==true||($b['profile']['asset_scope']??null)!==($combined?'whole_and_taxable':'single_reported_business'))throw new DomainException('Steuerprofil der Betriebsaufteilung prüfen.');
                $source=new BalancePeriodClubFiling($this->db,$this->actor);
                if($readOnly&&BalanceFilingEnvironment::submitted($r)){self::assertHistoricalApproval($r);return $source->historical($b,$b['closing_source_sha256']);}
                $report=$source->current(BalancePeriodFilingSource::binding($b));BalancePeriodClubFiling::matches($b,$report);return $report;
            }
            if($kind==='single_taxable_business'){
                if(($b['profile']['source_scope']??null)!=='separate_business_ledger'||($b['profile']['asset_scope']??null)!=='single_reported_business')throw new DomainException('Gesondertes Betriebsjournal und Anlagenumfang prüfen.');
                $source=new BalancePeriodBusinessFiling($this->db,$this->actor);
                if($readOnly&&BalanceFilingEnvironment::submitted($r)){self::assertHistoricalApproval($r);return $source->historical($b,$b['closing_source_sha256']);}
                $report=$source->current(BalancePeriodFilingSource::binding($b));BalancePeriodBusinessFiling::matches($b,$report);return $report;
            }
            if($kind==='whole_taxable_club'){
                if(($b['profile']['source_scope']??null)!=='complete_club_ledger'||($b['profile']['tax_privileged']??null)!==false||($b['profile']['asset_scope']??null)!=='whole_club')throw new DomainException('Gesamtvereinsbilanz nur für den vollständigen, nicht steuerbegünstigten Verein.');
                $source=new BalancePeriodWholeFiling($this->db,$this->actor);
                if($readOnly&&BalanceFilingEnvironment::submitted($r)){self::assertHistoricalApproval($r);return $source->historical($b,$b['closing_source_sha256']);}
                $report=$source->current(BalancePeriodFilingSource::binding($b));BalancePeriodWholeFiling::matches($b,$report);return $report;
            }
            if($kind!==self::SUBSIDIARY||($b['profile']['source_scope']??null)!==self::SUBSIDIARY_SOURCE||($b['profile']['tax_privileged']??null)!==true||($b['profile']['asset_scope']??null)!=='whole_and_subsidiary')throw new DomainException('Zeitraumabgabe benötigt den geprüften Gesamtverein mit steuerlicher Nebenrechnung.');
            $source=new BalancePeriodFilingSource($this->db,$this->actor);
            if($readOnly&&BalanceFilingEnvironment::submitted($r)){
                self::assertHistoricalApproval($r);
                return $source->historical($b,$b['closing_source_sha256']);
            }
            $report=$source->current(BalancePeriodFilingSource::binding($b));
            BalancePeriodFilingSource::matches($b,$report);return $report;
        }
        $this->calendarSourceOnly($b['year']);
        if($kind===self::SUBSIDIARY){
            if(($b['profile']['source_scope']??null)!==self::SUBSIDIARY_SOURCE||($b['profile']['tax_privileged']??null)!==true||($b['profile']['asset_scope']??null)!=='whole_and_subsidiary')throw new DomainException('Gesamtverein und steuerliche Nebenrechnung stimmen nicht überein.');
            $report=$this->subsidiaryReport($b['year']);
            if(($b['source_binding']??null)!==$report['subsidiary_review']['binding'])throw new DomainException('Steuerliche Nebenrechnung oder zugehörige Freigabe geändert. Einen neuen Entwurf anlegen.');
        }elseif(in_array($kind,['club_taxable_businesses',self::COMBINED],true)){
            if(($b['profile']['source_scope']??null)!==($kind===self::COMBINED?self::COMBINED_SOURCE:'approved_club_allocation')||($b['profile']['tax_privileged']??null)!==true)throw new DomainException('Steuerprofil der Vereinsaufteilung stimmt nicht überein.');
            $report=$this->clubReport($b['year']);
            if(($b['source_binding']??null)!==self::scopeBinding($report))throw new DomainException('Freigabestand der Vereinsaufteilung wurde geändert. Einen neuen Entwurf anlegen.');
            if($kind===self::COMBINED){
                $whole=BalanceWholeClubReport::fromReport($report['club_scope']['source']);
                if(($b['whole_journal_sha256']??null)!==$whole['closed_sha256']||($b['profile']['asset_scope']??null)!=='whole_and_taxable')throw new DomainException('Gesamtabschluss und steuerlicher Teil gehören nicht zusammen.');
            }
        }elseif($kind==='whole_taxable_club'){
            if(($b['profile']['source_scope']??null)!=='complete_club_ledger'||($b['profile']['tax_privileged']??null)!==false||($b['profile']['asset_scope']??null)!=='whole_club')throw new DomainException('Gesamtvereinsbilanz nur für den vollständigen, nicht steuerbegünstigten Verein.');
            $report=BalanceWholeClubReport::fromReport($this->report($b['year'],true));
        }elseif($kind==='single_taxable_business'&&($b['profile']['source_scope']??null)==='separate_business_ledger')$report=$this->report($b['year']);
        else throw new DomainException('Unbekannter Geltungsbereich der E-Bilanz.');
        if($b['journal_sha256']!==$report['closed_sha256'])throw new DomainException('Abschluss oder Betriebsaufteilung wurde geändert. Einen neuen Entwurf anlegen.');
        if(isset($b['closing_source_sha256'])&&$b['closing_source_sha256']!==(new BalanceLedger($this->db,$this->actor))->report($b['year'])['closed_sha256'])throw new DomainException('Ursprünglicher Gesamtabschluss wurde geändert. Einen neuen Entwurf anlegen.');
        return $report;
    }
    private function buildValues(array $b,array $report,bool $preview=false):array
    {
        (new BalanceReinsuranceStoredSources($this->db,$this->actor))->check($report);
        (new BalanceOriginalLandStoredSources($this->db,$this->actor))->check($report);
        (new BalanceOriginalLeasingStoredSources($this->db,$this->actor))->check($report);
        (new BalanceSubstanceSources($this->db,$this->actor))->check($report);
        (new BalanceExceptionalSources($this->db,$this->actor))->check($report);
        if(isset($b['review']['tax_equalization']))(new TaxEqualizationReview($this->db,$this->actor))->evidence($b['review']['tax_equalization']);
        if(isset($b['review']['basis_reduction_6b']))(new BasisReduction6bReview($this->db,$this->actor))->evidence($b['review']['basis_reduction_6b']);
        if(isset($b['review']['basis_reduction_7g']))(new BasisReduction7gReview($this->db,$this->actor))->evidence($b['review']['basis_reduction_7g']);
        if(isset($b['review']['special_tax_7b']))(new SpecialTax7bReview($this->db,$this->actor))->evidence($b['review']['special_tax_7b']);
        if(isset($b['review']['special_tax_7g']))(new SpecialTax7gReview($this->db,$this->actor))->evidence($b['review']['special_tax_7g']);
        $pensionReview=new PensionOffsetReview($this->db,$this->actor);
        foreach([$b['review']['pension_offsets']??null,$b['whole_pension_offsets']??null] as $pension)if($pension!==null)$pensionReview->evidence($pension);
        if(!empty($b['review']['pool_original_sources']))(new BalancePoolStoredSources($this->db,$this->actor))->check($report,$b['review'],$b['carry_forward']??null);
        if(!empty($b['review']['partnership_holdings']))(new BalancePartnershipStoredSources($this->db,$this->actor))->check($report,$b['review'],$b['carry_forward']??null);
        if(!empty($b['review']['own_intangible_sources']))(new BalanceOwnIntangibleStoredSources($this->db,$this->actor))->check($report,$b['review'],$b['carry_forward']??null);
        if(isset($b['period'])&&in_array($b['profile']['kind'],['club_taxable_businesses',self::COMBINED],true))return BalancePeriodClubFiling::values($b,$report,$preview);
        if(isset($b['period'])&&$b['profile']['kind']==='single_taxable_business')return BalancePeriodBusinessFiling::values($b,$report,$preview);
        if(isset($b['period'])&&$b['profile']['kind']==='whole_taxable_club')return BalancePeriodWholeFiling::values($b,$report,$preview);
        $review=$preview?array_replace($b['review'],['complete'=>true]):$b['review'];
        if($b['profile']['kind']===self::SUBSIDIARY){
            if(array_keys($review)!==['year','journal_sha256','complete']||$review['complete']!==true||$review['year']!==$report['year']||$review['journal_sha256']!==$report['closed_sha256'])throw new DomainException('Geprüfte Nebenrechnung vollständig übernehmen und bestätigen. Änderungen werden im steuerlichen Ergebnis erfasst.');
            return BalanceSubsidiaryClubValues::build($report['subsidiary_review']['values']);
        }
        if($b['profile']['kind']!==self::COMBINED)return BalanceTaxValues::build($report,$review);
        $ownHgb=null;
        if(array_filter($report['club_scope']['source']['asset_schedule']['items']??[],static fn($i)=>($i['method']??null)===OwnIntangiblePlans::METHOD))$ownHgb=(new BalanceOwnIntangibleBooks($this->db,$this->actor))->read(BalanceWholeClubReport::fromReport($report['club_scope']['source']));
        return BalanceCombinedClubValues::fromReport($report,['year'=>$b['year'],'journal_sha256'=>$b['whole_journal_sha256'],'complete'=>$review['complete'],'taxable_review'=>$review],$ownHgb,$b['whole_pension_offsets']??null);
    }
    public function previewValues(array $r):array
    {
        $b=$this->binding($r,false);return $this->buildValues($b,$this->sourceReport($r,true),true);
    }
    public function create(int $year,string $scope='separate_business_ledger',string $environment='test'):string
    {
        $this->calendarSourceOnly($year);
        $a=$this->access(true,BalanceFilingEnvironment::check($environment));
        if(!in_array($scope,['separate_business_ledger','approved_club_allocation','complete_club_ledger',self::COMBINED_SOURCE,self::SUBSIDIARY_SOURCE],true))throw new DomainException('Geltungsbereich auswählen.');
        $club=in_array($scope,['approved_club_allocation',self::COMBINED_SOURCE],true);$report=$scope===self::SUBSIDIARY_SOURCE?$this->subsidiaryReport($year):($club?$this->clubReport($year):$this->report($year,$scope==='complete_club_ledger'));
        return $this->insertDraft($a,$year,$scope,$report,(new BalanceLedger($this->db,$this->actor))->report($year)['closed_sha256'],null,$environment);
    }
    public function createPeriod(string $periodId,string $scope=self::SUBSIDIARY_SOURCE,string $environment='test'):string
    {
        $a=$this->access(true,BalanceFilingEnvironment::check($environment));
        $this->requireQualifiedPeriodPolicy($periodId);
        if(in_array($scope,['approved_club_allocation',self::COMBINED_SOURCE],true)){
            $report=(new BalancePeriodClubFiling($this->db,$this->actor))->current($periodId);
            return $this->insertDraft($a,$report['year'],$scope,$report,$report['period_club_scope']['income']['scope']['source']['closed_sha256'],$periodId,$environment);
        }
        if($scope==='separate_business_ledger'){
            $report=(new BalancePeriodBusinessFiling($this->db,$this->actor))->current($periodId);
            return $this->insertDraft($a,$report['year'],$scope,$report,$report['closed_sha256'],$periodId,$environment);
        }
        if($scope==='complete_club_ledger'){
            $report=(new BalancePeriodWholeFiling($this->db,$this->actor))->current($periodId);
            return $this->insertDraft($a,$report['year'],$scope,$report,$report['closed_sha256'],$periodId,$environment);
        }
        if($scope!==self::SUBSIDIARY_SOURCE)throw new DomainException('Geltungsbereich der Zeitraumabgabe prüfen.');
        $report=(new BalancePeriodFilingSource($this->db,$this->actor))->current($periodId);
        // Reconstruct the complete generator packet BEFORE saving: unsupported
        // prior/profile combinations may not masquerade as a prepared return.
        BalanceSubsidiaryClubValues::build($report['subsidiary_review']['values']);
        return $this->insertDraft($a,$report['year'],self::SUBSIDIARY_SOURCE,$report,$report['closed_sha256'],$periodId,$environment);
    }
    private function requireQualifiedPeriodPolicy(string $id):void
    {
        $actor=Permissions::finance($this->db,$this->actor);$row=$this->q('SELECT definition FROM balance_periods WHERE club_id=? AND id=?',[$actor['club_id'],$id])->fetch();
        if(!$row)throw new DomainException('Abschlusszeitraum nicht vorhanden.');$definition=json_decode($row['definition'],true,512,JSON_THROW_ON_ERROR);
        BalancePeriodDayAllocation::assertTaxQualified(['period'=>$definition['period'],'definition'=>$definition]);
    }
    private function insertDraft(array $a,int $year,string $scope,array $report,string $closingHash,?string $periodId=null,string $environment='test'):string
    {
        $club=in_array($scope,['approved_club_allocation',self::COMBINED_SOURCE],true);$id=bin2hex(random_bytes(16));$now=gmdate('c');
        $b=['year'=>$year,'journal_sha256'=>$report['closed_sha256'],
            'closing_source_sha256'=>$closingHash,
            'review'=>['year'=>$year,'journal_sha256'=>$report['closed_sha256'],'complete'=>false,'opening_adjustments'=>[],'current_adjustments'=>[]],
            'mapping'=>[], 'profile'=>['kind'=>'single_taxable_business','source_scope'=>'separate_business_ledger','scope_confirmed'=>false,
                'tax_privileged'=>null,'asset_scope'=>'single_reported_business','asset_values_unchanged'=>false,'asset_tax_values_reviewed'=>false,
                'business_name'=>'','supplier'=>'','company'=>array_fill_keys(['tax_number','name','street','house_number','postal_code','city','country'],'')]];
        if($club){$b['profile']=array_replace($b['profile'],['kind'=>'club_taxable_businesses','source_scope'=>$scope,'tax_privileged'=>true,'business_name'=>'Steuerpflichtige Geschäftsbetriebe des Vereins']);$b['source_binding']=$periodId!==null?BalancePeriodClubFiling::binding($report):self::scopeBinding($report);}
        if($scope===self::COMBINED_SOURCE){
            $whole=BalanceWholeClubReport::fromReport($report['period_club_scope']['income']['scope']['source']??$report['club_scope']['source']);$b['whole_journal_sha256']=$whole['closed_sha256'];$b['whole_mapping']=[];
            $b['profile']=array_replace($b['profile'],['kind'=>self::COMBINED,'asset_scope'=>'whole_and_taxable','whole_business_name'=>'','whole_book_standard'=>'']);
        }
        if($scope==='complete_club_ledger'){
            BalanceWholeClubReport::fromReport($report);
            $b['profile']=array_replace($b['profile'],['kind'=>'whole_taxable_club','source_scope'=>$scope,'tax_privileged'=>false,'asset_scope'=>'whole_club','business_name'=>'Gesamter Verein']);
        }
        if($scope===self::SUBSIDIARY_SOURCE){
            $b['review']=['year'=>$year,'journal_sha256'=>$report['closed_sha256'],'complete'=>false];
            $b['source_binding']=$report['subsidiary_review']['binding'];
            $b['profile']=array_replace($b['profile'],['kind'=>self::SUBSIDIARY,'source_scope'=>$scope,'tax_privileged'=>true,'asset_scope'=>'whole_and_subsidiary','whole_business_name'=>'','whole_book_standard'=>'']);
        }
        if($periodId!==null){
            $b['period_id']=$periodId;$b['period']=$report['period'];
            if($club){
                $b['period_club_source']=$report['period_club_scope']['income'];$b['review']['period']=$report['period'];$b['review']['asset_tax_plans']=[];
                if(isset($report['period_club_scope']['calendar_scope']))$b['period_club_calendar']=$report['period_club_scope']['calendar_scope'];
            }
            if($scope==='separate_business_ledger'){
                $b['business_source']=$report;$b['review']['period']=$report['period'];$b['review']['asset_tax_plans']=[];
            }
            if($scope==='complete_club_ledger'){
                $b['whole_source']=$report['whole_club_scope'];$b['review']['period']=$report['period'];
                $b['review']['asset_tax_plans']=[];$b['review']['capital_movements']=[];
            }
        }
        // Test drafts deliberately do not copy a production address or tax number.
        if($scope==='complete_club_ledger'&&$periodId===null){
            require_once __DIR__.'/BalanceMappingReview.php';
            $prepared=(new BalanceMappingReview($this->db,$this->actor))->read($report);
            if($prepared['current'])$b['mapping']=$prepared['mapping'];
        }
        $scopeSnapshot='{}';
        if($environment==='production'){
            $b['binding_version']=2;$b['environment']=$environment;$b['release_binding']=TaxBalanceRelease::software($year,self::VERSION);
            $stored=TaxClubData::stored($this->db,$this->actor);$clubData=$stored['club'];$taxData=$stored['tax'];
            $b['profile']['supplier']=implode(', ',array_filter([(string)($clubData['name']??''),(string)($clubData['street']??''),trim(($clubData['postcode']??'').' '.($clubData['city']??''))]));
            $b['profile']['company']=array_replace($b['profile']['company'],['tax_number'=>TaxClubData::number($taxData),'name'=>(string)($clubData['name']??''),'street'=>(string)($clubData['street']??''),'postal_code'=>(string)($clubData['postcode']??''),'city'=>(string)($clubData['city']??''),'country'=>'DE']);
            $scopeSnapshot=self::json((new TaxFilings($this->db,$this->actor,$this->runtime))->profile($year)['scope']);
        }
        $data=['balance_preparation'=>$b,'attachments'=>[]];
        $this->q('INSERT INTO tax_filings(club_id,id,family,data_version,tax_year,environment,form_data,scope_snapshot,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,'balance',self::VERSION,$year,$environment,self::json($data),$scopeSnapshot,$this->actor,$this->actor,$now,$now]);
        return $id;
    }
    private function binding(array $r,bool $write=true):array
    {
        $historical=!$write&&BalanceFilingEnvironment::submitted($r);$a=$this->access($write,(string)($r['environment']??''),$historical);$b=$r['form_data']['balance_preparation']??null;
        if($r['club_id']!==$a['club_id']||$r['data_version']!==self::VERSION||$r['family']!=='balance'||!in_array($r['environment'],['test','production'],true)||!is_array($b)||($b['year']??null)!==(int)$r['tax_year'])throw new DomainException('E-Bilanz-Auftrag und Testumgebung stimmen nicht überein.');
        BalanceFilingEnvironment::binding($r,$b);return $b;
    }
    public function save(string $id,int $version,array $profile,array $mapping,array $review,?array $wholeMapping=null):void
    {
        $this->access();$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
        if($b['journal_sha256']!==$report['closed_sha256'])throw new DomainException('Der zugrunde liegende Abschluss wurde verändert.');
        $combined=$b['profile']['kind']===self::COMBINED;$subsidiary=$b['profile']['kind']===self::SUBSIDIARY;
        $fields=['business_name','supplier','company','tax_privileged','scope_confirmed','asset_values_unchanged','asset_tax_values_reviewed'];
        if($combined||$subsidiary)$fields=array_merge($fields,['whole_business_name','whole_book_standard']);
        if(array_diff(array_keys($profile),$fields)||(!$combined&&$wholeMapping!==null))throw new DomainException('Unbekannte Angaben zur E-Bilanz.');
        if($combined||$subsidiary){
            $name=$profile['whole_business_name']??'';$standard=$profile['whole_book_standard']??'';
            if(!is_string($name)||mb_strlen($name)>200||preg_match('/[\x00-\x1f]/',$name)||!in_array($standard,['','HGBM'],true))throw new DomainException('Vereinstätigkeit und Rechnungslegungsstandard der Gesamtbilanz prüfen.');
            $b['profile']['whole_business_name']=trim($name);$b['profile']['whole_book_standard']=$standard;
        }
        foreach(['business_name','supplier'] as $key){$v=$profile[$key]??'';if(!is_string($v)||mb_strlen($v)>500||preg_match('/[\x00-\x1f]/',$v))throw new DomainException('Absender und Geschäftsbetrieb prüfen.');$b['profile'][$key]=trim($v);}
        $company=$profile['company']??[];
        $b['profile']['company']+=['legal_status'=>''];
        if(!is_array($company)||array_diff(array_keys($company),array_keys($b['profile']['company'])))throw new DomainException('Vereinsangaben prüfen.');
        foreach($b['profile']['company'] as $key=>$_){$v=$company[$key]??'';if(!is_string($v)||mb_strlen($v)>200||preg_match('/[\x00-\x1f]/',$v))throw new DomainException('Anschrift und Steuernummer prüfen.');$b['profile']['company'][$key]=trim($v);}
        if(!in_array($b['profile']['company']['legal_status'],['','Other.KOER','EV','ST','GMBH','gGMBH','AG','UG','EG'],true))throw new DomainException('Bestätigte Rechtsform der Körperschaft auswählen.');
        foreach(['scope_confirmed','asset_values_unchanged'] as $key){if(!is_bool($profile[$key]??null))throw new DomainException('Geltungsbereich und Anlagenwerte ausdrücklich prüfen.');$b['profile'][$key]=$profile[$key];}
        if(isset($profile['asset_tax_values_reviewed'])&&!is_bool($profile['asset_tax_values_reviewed']))throw new DomainException('Steuerliche Anlagenwerte ausdrücklich prüfen.');
        $b['profile']['asset_tax_values_reviewed']=$profile['asset_tax_values_reviewed']??false;
        if(!is_bool($profile['tax_privileged']??null))throw new DomainException('Steuerbegünstigung ausdrücklich angeben.');
        if(in_array($b['profile']['kind'],['club_taxable_businesses',self::COMBINED,self::SUBSIDIARY],true)&&$profile['tax_privileged']!==true)throw new DomainException('Die Bereichsabgrenzung gilt nur für einen steuerbegünstigten Verein. Vollständigen steuerpflichtigen Verein gesondert bilanzieren.');
        if($b['profile']['kind']==='whole_taxable_club'&&$profile['tax_privileged']!==false)throw new DomainException('Dieser Entwurf umfasst den nicht steuerbegünstigten Gesamtverein. Für einen steuerbegünstigten Verein einen passenden Entwurf anlegen.');
        $b['profile']['tax_privileged']=$profile['tax_privileged'];
        if(array_diff(array_keys($review),['complete','opening_adjustments','current_adjustments','asset_tax_plans','capital_movements','partnership_holdings','own_intangible_sources','pool_original_sources','pension_offsets','special_tax_7g','basis_reduction_7g','special_tax_7b','basis_reduction_6b','tax_equalization'])||!is_bool($review['complete']??null))throw new DomainException('Steuerliche Wertänderungen prüfen.');
        if($subsidiary&&array_keys($review)!==['complete'])throw new DomainException('Wertänderungen und Vortrag gehören zur geprüften steuerlichen Nebenrechnung.');
        if(isset($review['capital_movements'])&&$b['profile']['kind']!=='whole_taxable_club')throw new DomainException('Kapitalvergleich gehört zur vollständigen Vereinsbilanz.');
        $b['review']=['year'=>$b['year'],'journal_sha256'=>$b['journal_sha256']]+$review;
        if(isset($b['period'])&&!$subsidiary)$b['review']['period']=$b['period'];
        // Validate all adjustment rows even while the overall review remains open.
        $built=$this->buildValues($b,$report,true);$values=$subsidiary?$built['subsidiary']['valuation']:($combined?$built['taxable']:$built);
        $changedAssets=isset($built['period'])?(!empty($subsidiary?$built['subsidiary']['review']['asset_tax_plans']:$b['review']['asset_tax_plans'])||BalanceCostAssetTax::periodChanges($values)):isset($values['asset_tax']);
        if($subsidiary&&isset($built['subsidiary']['sphere_reconciliation']))$changedAssets=true;
        $changedAssets=$changedAssets||!empty($review['partnership_holdings'])||!empty($review['own_intangible_sources'])||!empty($review['special_tax_7b'])||!empty($review['special_tax_7g'])||!empty($review['basis_reduction_7g'])||!empty($review['basis_reduction_6b']);
        if($changedAssets&&($b['profile']['asset_values_unchanged']||($review['complete']&&!$b['profile']['asset_tax_values_reviewed'])))throw new DomainException('Abweichende steuerliche Anlagenwerte prüfen und bestätigen.');
        if(isset($b['carry_forward']))$this->checkCarryForward($b,$report);
        // Signed choices must use the same reviewed balances as generation,
        // not the amount-free chart or, after tax corrections, the original book.
        $mappingAccounts=$subsidiary?$built['whole']['book']['accounts']:$values['tax']['accounts'];
        $b['mapping']=self::checkedMapping($mapping,$report,$mappingAccounts,$subsidiary?null:$values);
        if($combined&&$wholeMapping!==null)$b['whole_mapping']=self::checkedMapping($wholeMapping,$report,$built['whole']['book']['accounts'],$built['whole']);
        $this->persistDraft($r,$version,$b);
    }
    private static function checkedMapping(array $mapping,array $report,array $valuedAccounts,?array $sourceValues=null):array
    {
        if(array_diff(array_keys($mapping),array_keys($report['accounts'])))throw new DomainException('Unbekanntes Konto in der Zuordnung.');
        $targets=self::targets();$out=[];$holdings=$sourceValues===null?[]:BalancePartnershipHoldings::mapping($sourceValues)+BalanceOwnIntangibleMapping::mapping($sourceValues)+BalancePoolOriginalValues::mapping($sourceValues);
        foreach($mapping as $key=>$target){if($target==='')continue;$account=$valuedAccounts[$key]??null;if(!is_array($account)||!isset($account['amount_cents'])||!is_int($account['amount_cents']))throw new DomainException('Geprüfter Kontensaldo fehlt: '.$key);$choices=isset($holdings[$key])?[$holdings[$key]=>true]:BalanceTaxMapping::choices($report['year'],$account,$targets);if(!is_string($target)||!isset($choices[$target]))throw new DomainException('Nicht unterstützte Kontenzuordnung: '.$key);$out[$key]=$target;}
        return $out;
    }
    private function persistDraft(array $r,int $version,array $b):void
    {
        $id=$r['id'];unset($b['approval'],$b['values_sha256'],$b['generated'],$b['native_runtime'],$b['generator_sha256']);
        $data=$r['form_data'];$data['balance_preparation']=$b;$scopeSql='';$params=[self::json($data)];
        if($r['environment']==='production'){$scopeSql=',scope_snapshot=?';$params[]=self::json((new TaxFilings($this->db,$this->actor,$this->runtime))->profile((int)$r['tax_year'])['scope']);}
        $q=$this->q("UPDATE tax_filings SET form_data=?".$scopeSql.",state='draft',version=version+1,xml_data=NULL,xml_sha256=NULL,content_sha256=NULL,runtime_sha256=NULL,validation=NULL,pdf=NULL,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state IN ('draft','validated','failed')",array_merge($params,[$this->actor,gmdate('c'),$r['club_id'],$id,$version]));
        if($q->rowCount()!==1)throw new DomainException('Die Angaben wurden inzwischen geändert. Bitte neu laden.');
    }
    public function capitalMovements(string $id,int $version,array $decisions):void
    {
        $r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
        if($b['profile']['kind']!=='whole_taxable_club')throw new DomainException('Kapitalvergleich gehört zur vollständigen Vereinsbilanz.');
        $b['review']['capital_movements']=$decisions;$b['review']['complete']=false;
        $this->buildValues($b,$report,true);
        $this->persistDraft($r,$version,$b);
    }
    public function assertCurrent(array $r,bool $readOnly=false):array
    {
        return BalanceReadSnapshot::run($this->db,fn()=>$this->assertCurrentSnapshot($r,$readOnly));
    }
    private function assertCurrentSnapshot(array $r,bool $readOnly):array
    {
        $b=$this->binding($r,!$readOnly);FinancialReserveIntake::assertExportable($b);$report=$this->sourceReport($r,$readOnly);
        (new BalanceFilingRevision($this->db,$this->actor))->current($r);
        if($b['journal_sha256']!==$report['closed_sha256'])throw new DomainException('Der Abschluss stimmt nicht mehr mit dem Entwurf überein.');
        // The subsidiary profile binds the immutable reviewed prior-value chain
        // directly, without a second editable copy of the same carry.
        if($b['profile']['kind']!==self::SUBSIDIARY&&($report['previous_sha256']??null)&&(isset($b['period'])||in_array($report['year']-1,[2025,2026],true))){
            if(!isset($b['carry_forward']))throw new DomainException('Zuerst die bestätigten Steuerwerte des Vorjahres übernehmen, auch wenn kein Unterschied besteht.');
            $this->checkCarryForward($b,$report,$readOnly);
        }
        $values=$this->buildValues($b,$report);
        FinancialReserveXml::assertExportable($values['tax']['accounts']??$values['taxable']['tax']['accounts']??[]);
        if($b['profile']['kind']===self::COMBINED){
            OriginalPensionOffsets::fiscal($values['taxable']);
            if(OriginalPensionOffsets::active($values['whole']['book']['accounts'])&&!isset($values['whole']['pension_offsets']))throw new DomainException('Originalnachweise der handelsrechtlichen Pensionsverrechnung fehlen.');
        }elseif(isset($values['book'])){OriginalPensionOffsets::fiscal($values);OriginalSpecialTax7g::check($values);OriginalSpecialTax7b::check($values);OriginalBasisReduction7g::check($values);OriginalBasisReduction6b::check($values);OriginalTaxEqualization::check($values);}
        elseif(OriginalPensionOffsets::active($values['whole']['book']['accounts']??[]))throw new DomainException('Pensionsverrechnung in der steuerlichen Nebenrechnung gesondert qualifizieren.');
        if(isset($values['capital_comparison'])&&!$values['capital_comparison']['complete'])throw new DomainException('Zuerst die Kapitalbewegungen vollständig zuordnen und prüfen.');
        if(isset($b['values_sha256'])&&$b['values_sha256']!==$values['sha256'])throw new DomainException('Steuerwerte wurden nach der Prüfung verändert.');
        return $values;
    }
    public function carryForwardChoices(int $year,?array $period=null,string $kind='whole_taxable_club',string $environment='test'):array
    {
        $environment=BalanceFilingEnvironment::check($environment);$a=$this->access(true,$environment);
        if($period===null)return $this->q("SELECT id,tax_year,updated_at FROM tax_filings WHERE club_id=? AND family='balance' AND environment=? AND state=? AND tax_year=? ORDER BY updated_at DESC",[$a['club_id'],$environment,'sent_'.$environment,$year-1])->fetchAll();
        $out=[];foreach($this->q("SELECT id,tax_year,updated_at,".TaxFilingListMetadata::projection()." FROM tax_filings WHERE club_id=? AND family='balance' AND environment=? AND state=? AND tax_year IN (?,?) ORDER BY updated_at DESC",[$a['club_id'],$environment,'sent_'.$environment,$year,$year-1])->fetchAll() as $row){
            $metadata=TaxFilingListMetadata::read($row['period_metadata'],(int)$row['tax_year']);$priorPeriod=$metadata['period'];
            if(in_array($kind,['whole_taxable_club','single_taxable_business','club_taxable_businesses',self::COMBINED],true)&&$metadata['kind']===$kind&&BalancePeriod::follows($priorPeriod,$period)){
                try{$candidate=$this->filing($row['id']);(new BalanceFilingRevision($this->db,$this->actor))->assertLatestReceipt($candidate);$this->sourceReport($candidate);}catch(DomainException){unset($candidate);continue;}
                unset($row['period_metadata'],$candidate);$row['period']=$priorPeriod;$out[]=$row;
            }
        }return $out;
    }
    private function carrySource(string $id,array $b,array $report,bool $readOnly=false):array
    {
        return BalanceReadSnapshot::run($this->db,fn()=>$this->carrySourceSnapshot($id,$b,$report,$readOnly));
    }
    private function carrySourceSnapshot(string $id,array $b,array $report,bool $readOnly=false):array
    {
        $source=$this->filing($id);$prior=$this->binding($source,!$readOnly);
        $dated=isset($b['period']);
        if(!BalanceFilingEnvironment::submitted($source)||$source['environment']!==($b['environment']??'test')||($dated?!BalancePeriod::follows($prior['period']??BalancePeriod::calendar($prior['year']),$b['period']):$prior['year']!==$b['year']-1))throw new DomainException('Bestätigte E-Bilanz des unmittelbaren Vorzeitraums auswählen.');
        foreach(['business_name','tax_privileged','kind','source_scope'] as $key)if($prior['profile'][$key]!==$b['profile'][$key])throw new DomainException('Vorjahr gehört zu einem anderen Geschäftsbetrieb oder Steuerprofil.');
        if($b['profile']['kind']===self::COMBINED&&($prior['profile']['whole_book_standard']??null)!==($b['profile']['whole_book_standard']??null))throw new DomainException('Rechnungslegungsstandard des Gesamtvereins stimmt nicht mit dem Vorjahr überein.');
        if($prior['profile']['company']['tax_number']!==$b['profile']['company']['tax_number'])throw new DomainException('Steuernummer stimmt nicht mit dem Vorjahr überein.');
        // A board change does not revoke a historical, already sent declaration.
        // Fresh send approvals still use assertApproved() and current permissions.
        $priorValues=$this->assertCurrent($source,$readOnly);
        if($dated)(new BalanceFilingRevision($this->db,$this->actor))->assertLatestReceipt($source);
        $approval=$prior['approval']??[];
        if(($approval['sha256']??'')!==self::approvalHash($source)||!is_string($approval['actor']??null))throw new DomainException('Unveränderte Vorjahresfreigabe fehlt.');
        $attempts=(new TaxFilings($this->db,$this->actor))->attempts($id);
        if(!array_filter($attempts,static fn($a)=>$a['receipt_bound']??false))throw new DomainException('Unveränderter Übermittlungsnachweis des Vorjahres fehlt.');
        if($dated&&in_array($b['profile']['kind'],['club_taxable_businesses',self::COMBINED],true))return BalancePeriodClubFiling::carry($this->sourceReport($source,$readOnly),$priorValues,$report,$prior['review']);
        if($dated)return $b['profile']['kind']==='single_taxable_business'
            ?BalancePeriodBusinessFiling::carry($this->sourceReport($source,$readOnly),$priorValues,$report,$prior['review'])
            :BalancePeriodWholeFiling::carry($this->sourceReport($source,$readOnly),$priorValues,$report,$prior['review']);
        return BalanceTaxValues::carryForward($this->sourceReport($source,$readOnly),$prior['review'],$report);
    }
    private function checkCarryForward(array $b,array $report,bool $readOnly=false):void
    {
        $binding=$b['carry_forward'];
        if(!is_array($binding)||!is_string($binding['filing_id']??null))throw new DomainException('Vorjahresnachweis fehlt.');
        $expected=$this->carrySource($binding['filing_id'],$b,$report,$readOnly);
        if($binding!==['filing_id'=>$binding['filing_id']]+$expected)throw new DomainException('Steuerlicher Vorjahresstand wurde verändert.');
        $entries=array_values(array_filter($b['review']['opening_adjustments'],static fn($e)=>str_starts_with($e['id'],'carry-')));
        if($entries!==($expected['entry']?[$expected['entry']]:[]))throw new DomainException('Vorjahresunterschied wurde entfernt, verändert oder doppelt übernommen.');
        foreach($expected['asset_tax_plans']??[] as $item=>$plan)if(($b['review']['asset_tax_plans'][$item]??null)!==$plan)throw new DomainException('Steuerliche Anlagenpläne des bestätigten Vorjahres unverändert fortführen. Änderungen gesondert klären.');
    }
    public function carryForward(string $id,int $version,string $sourceId,bool $confirmed):void
    {
        $this->access();$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
        if($b['profile']['kind']===self::SUBSIDIARY)throw new DomainException('Der Vorjahresvortrag ist bereits mit der geprüften Nebenrechnung verbunden.');
        if(!$confirmed||isset($b['carry_forward'])||$b['review']['opening_adjustments'])throw new DomainException('Vorjahr bestätigen und vor weiteren Anfangswertkorrekturen genau einmal übernehmen.');
        if(isset($b['period'])&&$b['profile']['company']['tax_number']===''){
            $p=$this->binding($this->filing($sourceId))['profile'];
            // Reject a different report type before reading its type-specific
            // fields or copying any identity into the still empty draft.
            foreach(['kind','source_scope','asset_scope'] as $key)if(($p[$key]??null)!==($b['profile'][$key]??null))throw new DomainException('Vorabschluss desselben Geschäftsbetriebs und Steuerprofils auswählen.');
            foreach(['business_name','supplier','company'] as $key)$b['profile'][$key]=$p[$key];
            if($b['profile']['kind']===self::COMBINED)foreach(['whole_business_name','whole_book_standard'] as $key)$b['profile'][$key]=$p[$key];
            if($b['profile']['kind']==='single_taxable_business'&&$b['profile']['tax_privileged']===null)$b['profile']['tax_privileged']=$p['tax_privileged'];
        }
        $carry=$this->carrySource($sourceId,$b,$report);
        $b['carry_forward']=['filing_id'=>$sourceId]+$carry;
        if($carry['entry'])$b['review']['opening_adjustments'][]=$carry['entry'];
        foreach($carry['asset_tax_plans']??[] as $item=>$plan){
            if(isset($b['review']['asset_tax_plans'][$item])&&$b['review']['asset_tax_plans'][$item]!==$plan)throw new DomainException('Vorjahresplan und bereits erfasste steuerliche Nutzungsdauer widersprechen sich.');
            $b['review']['asset_tax_plans'][$item]=$plan;
        }
        if(!empty($carry['asset_tax_plans'])){$b['profile']['asset_values_unchanged']=false;$b['profile']['asset_tax_values_reviewed']=false;}
        $b['review']['complete']=false;
        $this->buildValues($b,$report,true);
        $this->persistDraft($r,$version,$b);
    }
    public function appendAdjustment(string $id,int $version,string $period,string $reason,string $debit,string $credit,int $amount):void
    {
        $r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
        if($b['profile']['kind']===self::SUBSIDIARY)throw new DomainException('Wertänderung im steuerlichen Ergebnis erfassen und erneut prüfen lassen.');
        if(!in_array($period,['opening_adjustments','current_adjustments'],true)||$amount<=0||$debit===$credit||!isset($report['accounts'][$debit],$report['accounts'][$credit]))throw new DomainException('Zeitraum, unterschiedliche Konten und einen positiven Betrag angeben.');
        $lines=[];foreach([[$debit,$amount,0],[$credit,0,$amount]] as [$account,$d,$c])$lines[]=['account'=>$account,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>in_array($report['accounts'][$account]['group'],['expense','revenue'],true)?'4':''];
        $review=$b['review'];unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=false;
        $review[$period][]=['id'=>bin2hex(random_bytes(16)),'reason'=>$reason,'lines'=>$lines];
        $profile=self::editableProfile($b);
        $this->save($id,$version,$profile,$b['mapping'],$review);
    }
    private static function editableProfile(array $b):array{return array_intersect_key($b['profile'],array_flip(['business_name','supplier','company','tax_privileged','scope_confirmed','asset_values_unchanged','asset_tax_values_reviewed','whole_business_name','whole_book_standard']));}
    /** Remove only an explicitly selected draft plan; inherited plans are immutable. */
    public function assetTaxPlan(string $id,int $version,string $item,?array $plan):void
    {
        $r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
        if($b['profile']['kind']===self::SUBSIDIARY)throw new DomainException('Anlagenplan im steuerlichen Ergebnis ändern und erneut prüfen lassen.');
        if(!isset($report['asset_schedule']['items'][$item]))throw new DomainException('Gegenstand im festgeschriebenen Anlagenverzeichnis auswählen.');
        if(isset($b['carry_forward']['asset_tax_plans'][$item]))throw new DomainException('Bestätigten Vorjahresplan unverändert fortführen.');
        $review=$b['review'];unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=false;
        if($plan===null){
            if(!isset($review['asset_tax_plans'][$item]))throw new DomainException('Kein abweichender Steuerplan vorhanden.');
            unset($review['asset_tax_plans'][$item]);
        }else{$review['asset_tax_plans'][$item]=$plan;}
        // Saving an asset plan does not require completing unrelated address/profile fields.
        if($b['journal_sha256']!==$report['closed_sha256'])throw new DomainException('Der zugrunde liegende Abschluss wurde verändert.');
        $b['review']=['year'=>$b['year'],'journal_sha256'=>$b['journal_sha256']]+$review;
        if(isset($b['period']))$b['review']['period']=$b['period'];
        $this->buildValues($b,$report,true);
        $b['profile']['asset_values_unchanged']=false;$b['profile']['asset_tax_values_reviewed']=false;
        if(isset($b['carry_forward']))$this->checkCarryForward($b,$report);
        $this->persistDraft($r,$version,$b);
    }
    /** Bind a reviewed original fiscal form to the closed HGB books; never post journal changes. */
    public function partnershipReview(string $id,int $version,string $holding,array $input):void
    {
        $this->access(true);$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
        if($b['profile']['kind']!=='whole_taxable_club'||isset($b['period'])||$b['journal_sha256']!==$report['closed_sha256'])throw new DomainException('Beteiligungsprüfung benötigt den aktuellen vollständigen Kalenderabschluss.');
        $previous=null;
        if($report['previous_sha256']!==null){
            if(!is_string($b['carry_forward']['filing_id']??null))throw new DomainException('Bestätigte ursprüngliche Vorjahresabgabe zuerst unverändert übernehmen.');
            $prior=$this->filing($b['carry_forward']['filing_id']);$this->assertCurrent($prior);
            $previous=$prior['form_data']['balance_preparation']['review']['partnership_holdings'][$holding]??null;
            if(!is_array($previous))throw new DomainException('Für diese Beteiligung fehlt der ursprüngliche steuerliche Vorjahresnachweis.');
        }
        $matrix=(new PartnershipHoldingReview($this->db,$this->actor))->build($report,$b['review'],$holding,$input,$previous);
        $b['review']['partnership_holdings'][$holding]=$matrix;$b['review']['complete']=false;
        $this->buildValues($b,$report,true);if(isset($b['carry_forward']))$this->checkCarryForward($b,$report);
        $b['profile']['asset_values_unchanged']=false;$b['profile']['asset_tax_values_reviewed']=false;
        $this->persistDraft($r,$version,$b);
    }
    /** Serialize fiscal proof changes with original plan/journal writers and lock original bytes. */
    public function ownIntangibleReview(string $id,int $version,string $item,array $input):void
    {
        $a=$this->access(true);

        Transactions::run($this->db,function()use($a,$id,$version,$item,$input):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();
            if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert. Bitte neu laden.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();
            $r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if(!in_array($b['profile']['kind'],['whole_taxable_club','club_taxable_businesses',self::COMBINED],true)||isset($b['period'])||$b['journal_sha256']!==$report['closed_sha256'])throw new DomainException('Entwicklungsprüfung benötigt den aktuellen vollständigen Kalenderabschluss.');
            $previous=null;
            if($report['previous_sha256']!==null){
                if(!is_string($b['carry_forward']['filing_id']??null))throw new DomainException('Bestätigte ursprüngliche Vorjahresabgabe zuerst übernehmen.');
                $prior=$this->filing($b['carry_forward']['filing_id']);$this->assertCurrent($prior);$previous=$prior['form_data']['balance_preparation']['review']['own_intangible_sources'][$item]??null;
                if(!is_array($previous))throw new DomainException('Ursprünglicher steuerlicher Entwicklungsnachweis des Vorjahres fehlt.');
            }
            $p=(new OwnIntangibleReview($this->db,$this->actor))->build($report,$b['review'],$item,$input,$previous);
            (new OwnIntangibleEvidence($this->db,$this->actor))->checkJournals($p,true);
            $b['review']['own_intangible_sources']=[$item=>$p];$b['review']['complete']=false;
            $this->buildValues($b,$report,true);if(isset($b['carry_forward']))$this->checkCarryForward($b,$report);
            $b['profile']['asset_values_unchanged']=false;$b['profile']['asset_tax_values_reviewed']=false;
            $this->persistDraft($r,$version,$b);
        });
    }
    /** Serialize the annual election with original invoice and register writers. */
    public function poolOriginalReview(string $id,int $version,array $input):void
    {
        $a=$this->access(true);

        Transactions::run($this->db,function()use($a,$id,$version,$input):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();
            if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert. Bitte neu laden.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();
            $r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if($b['profile']['kind']!=='whole_taxable_club'||isset($b['period'])||$b['journal_sha256']!==$report['closed_sha256'])throw new DomainException('Sammelposten benötigt den aktuellen vollständigen Kalenderabschluss.');
            $previous=null;
            if($report['previous_sha256']!==null){
                if(!is_string($b['carry_forward']['filing_id']??null))throw new DomainException('Bestätigte ursprüngliche Vorjahresabgabe zuerst übernehmen.');
                $prior=$this->filing($b['carry_forward']['filing_id']);$this->assertCurrent($prior);$sources=$prior['form_data']['balance_preparation']['review']['pool_original_sources']??null;
                if(!is_array($sources)||count($sources)!==1)throw new DomainException('Ursprünglicher Sammelpostennachweis des Vorjahres fehlt.');$previous=reset($sources);
            }
            $p=(new PoolOriginalReview($this->db,$this->actor))->build($report,$b['review'],$input,$previous);
            (new BalancePoolStoredSources($this->db,$this->actor))->evidence($p,true);
            $poolId=$p['fiscal']['pool_proof']['source']['pool_id'];$b['review']['pool_original_sources']=[$poolId=>$p];$b['review']['complete']=false;
            $this->buildValues($b,$report,true);if(isset($b['carry_forward']))$this->checkCarryForward($b,$report);
            $b['profile']['asset_values_unchanged']=true;$b['profile']['asset_tax_values_reviewed']=false;
            $this->persistDraft($r,$version,$b);
        });
    }
    public function taxEqualization(string $id,int $version,array $input):void{
        $a=$this->access(true);
        Transactions::run($this->db,function()use($a,$id,$version,$input):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if($b['profile']['kind']!=='whole_taxable_club'||isset($b['period']))throw new DomainException('77690 benötigt die vollständige anfängliche Kalenderbilanz.');
            $s=(new TaxEqualizationReview($this->db,$this->actor))->build($report,$b['review'],$input);$p=OriginalTaxEqualization::bind($report,$s);
            $b['review']['tax_equalization']=$s;$b['review']['current_adjustments']=[...$s['original_current_adjustments'],$p['presentation_entry']];$b['review']['complete']=false;
            foreach([0,1] as $ordinal)$b['review']['capital_movements']['tax:'.OriginalTaxEqualization::ENTRY.':'.$ordinal]=['kind'=>'reclassification','reason'=>'Durch Originalbelege bestätigte interne Ergebnisumbuchung 77690.'];
            $this->buildValues($b,$report,true);$this->persistDraft($r,$version,$b);
        });
    }

    /** Store or explicitly withdraw an unqualified source; never amend journal/tax values. */
    public function financialReserveIntake(string $id,int $version,?array $input,string $withdrawalReason=''):void
    {
        $a=$this->access(true);
        Transactions::run($this->db,function()use($a,$id,$version,$input,$withdrawalReason):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if($b['journal_sha256']!==$report['closed_sha256']||isset($b['period'])||$b['profile']['kind']!=='whole_taxable_club')throw new DomainException('Quellenaufnahme benötigt den unveränderten vollständigen Kalenderabschluss.');
            $history=$b['financial_reserve_intake_history']??[];
            if(!is_array($history)||count($history)>=100)throw new DomainException('Quellenhistorie muss vor weiterer Bearbeitung geprüft werden.');
            if($input===null){
                $reason=trim($withdrawalReason);
                if(!isset($b['financial_reserve_intake'])||$reason===''||mb_strlen($reason)>500||preg_match('/[\x00-\x1f]/',$reason))throw new DomainException('Rücknahme des Quellenentwurfs begründen.');
            }else $next=(new FinancialReserveIntake($this->db,$this->actor))->build($report,$b['profile'],$input);
            if(isset($b['financial_reserve_intake']))$history[]=['source'=>$b['financial_reserve_intake'],'action'=>$input===null?'withdrawn':'replaced','reason'=>$input===null?$reason:'Quellenentwurf ersetzt.','actor'=>$this->actor,'at'=>gmdate('c')];
            $b['financial_reserve_intake_history']=$history;
            if($input===null)unset($b['financial_reserve_intake']);else $b['financial_reserve_intake']=$next;
            $b['review']['complete']=false;$this->persistDraft($r,$version,$b);
        });
    }

    public function basisReduction6b(string $id,int $version,array $input):void{
        $a=$this->access(true);
        Transactions::run($this->db,function()use($a,$id,$version,$input):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if($b['profile']['kind']!=='whole_taxable_club'||isset($b['period'])||!empty($b['review']['asset_tax_plans'])||isset($b['review']['basis_reduction_7g'])||isset($b['review']['special_tax_7g'])||isset($b['review']['special_tax_7b']))throw new DomainException('§6b-Übertragung benötigt den vollständigen anfänglichen Kalenderabschluss, die ursprüngliche Vorjahresrücklage und den belegten einzelnen Ersatzgebäudeplan.');
            $b['review']['basis_reduction_6b']=(new BasisReduction6bReview($this->db,$this->actor))->build($report,$input);$b['review']['complete']=false;$b['profile']['asset_values_unchanged']=false;$b['profile']['asset_tax_values_reviewed']=false;
            $this->buildValues($b,$report,true);$this->persistDraft($r,$version,$b);
        });
    }

    public function basisReduction7g(string $id,int $version,array $input):void{
        $a=$this->access(true);
        Transactions::run($this->db,function()use($a,$id,$version,$input):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if($b['profile']['kind']!=='whole_taxable_club'||isset($b['period'])||!empty($b['review']['asset_tax_plans'])||isset($b['review']['basis_reduction_6b'])||isset($b['review']['special_tax_7g'])||isset($b['review']['special_tax_7b']))throw new DomainException('Anschaffungskostenkürzung benötigt den vollständigen anfänglichen Kalenderabschluss und den ursprünglichen linearen Einzelplan.');
            $b['review']['basis_reduction_7g']=(new BasisReduction7gReview($this->db,$this->actor))->build($report,$input);$b['review']['complete']=false;$b['profile']['asset_values_unchanged']=false;$b['profile']['asset_tax_values_reviewed']=false;
            $this->buildValues($b,$report,true);$this->persistDraft($r,$version,$b);
        });
    }

    public function specialTax7g(string $id,int $version,array $input):void{
        $a=$this->access(true);
        Transactions::run($this->db,function()use($a,$id,$version,$input):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if($b['profile']['kind']!=='whole_taxable_club'||isset($b['period'])||!empty($b['review']['asset_tax_plans'])||isset($b['review']['basis_reduction_6b'])||isset($b['review']['basis_reduction_7g'])||isset($b['review']['special_tax_7b']))throw new DomainException('Sonderabschreibung benötigt den vollständigen anfänglichen Kalenderabschluss und den ursprünglichen linearen Einzelplan.');
            $b['review']['special_tax_7g']=(new SpecialTax7gReview($this->db,$this->actor))->build($report,$input);$b['review']['complete']=false;$b['profile']['asset_values_unchanged']=false;$b['profile']['asset_tax_values_reviewed']=false;
            $this->buildValues($b,$report,true);$this->persistDraft($r,$version,$b);
        });
    }

    public function specialTax7b(string $id,int $version,array $input):void{
        $a=$this->access(true);
        Transactions::run($this->db,function()use($a,$id,$version,$input):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();$r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if($b['profile']['kind']!=='whole_taxable_club'||isset($b['period'])||!empty($b['review']['asset_tax_plans'])||isset($b['review']['basis_reduction_6b'])||isset($b['review']['basis_reduction_7g'])||isset($b['review']['special_tax_7g']))throw new DomainException('Sonderabschreibung benötigt den vollständigen anfänglichen Kalenderabschluss und den ursprünglichen linearen Einzelplan.');
            $b['review']['special_tax_7b']=(new SpecialTax7bReview($this->db,$this->actor))->build($report,$input);$b['review']['complete']=false;$b['profile']['asset_values_unchanged']=false;$b['profile']['asset_tax_values_reviewed']=false;
            $this->buildValues($b,$report,true);$this->persistDraft($r,$version,$b);
        });
    }

    public function pensionOffsets(string $id,int $version,array $input,string $scope='tax'):void
    {
        $a=$this->access(true);

        Transactions::run($this->db,function()use($a,$id,$version,$input,$scope):void{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();
            if($this->access(true)['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            $this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();
            $r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$report=$this->sourceReport($r);
            if(isset($b['period']))throw new DomainException('Pensionsverrechnung in abweichenden Zeiträumen gesondert qualifizieren.');
            $fiscal=$scope==='tax';
            if($fiscal){if($b['profile']['kind']!=='whole_taxable_club')throw new DomainException('Steuerliche Pensionsüberleitung benötigt den vollständigen steuerpflichtigen Kalenderabschluss.');}
            elseif($scope==='whole'&&$b['profile']['kind']===self::COMBINED)$report=BalanceWholeClubReport::fromReport($report['club_scope']['source']);
            else throw new DomainException('Geltungsbereich der Pensionsprüfung auswählen.');
            $p=(new PensionOffsetReview($this->db,$this->actor))->build($report,$input,$fiscal);
            if($fiscal)$b['review']['pension_offsets']=$p;else $b['whole_pension_offsets']=$p;
            $b['review']['complete']=false;$this->buildValues($b,$this->sourceReport($r),true);
            $this->persistDraft($r,$version,$b);
        });
    }
    private function generated(array $r,array $values,array $b):array
    {
        if($r['environment']==='production'&&($b['profile']['company']['legal_status']??'')==='')throw new DomainException('Bestätigte Rechtsform der Körperschaft auswählen.');
        $profile=$b['profile']+['tax_values_sha256'=>$values['sha256'],'filing_revision'=>(new BalanceFilingRevision($this->db,$this->actor))->current($r)];
        $mapping=$b['profile']['kind']===self::COMBINED?['whole'=>(object)$b['whole_mapping'],'taxable'=>(object)$b['mapping']]:$b['mapping'];
        $generated=TaxEricAdapter::balanceGenerate($values,$mapping,$profile,$r['environment']);
        $xml=$generated['xml']??'';$hash=hash('sha256',$xml);
        if(($generated['code']??-1)!==0||($generated['environment']??'')!==$r['environment']||($generated['sent']??true)!==false||($generated['transport_attempted']??true)!==false||($generated['xml_sha256']??'')!==$hash||($generated['tax_values_sha256']??'')!==$values['sha256'])throw new DomainException('E-Bilanz-Entwurf konnte nicht sicher erzeugt werden.');
        if($r['environment']==='production'&&(($generated['release_sha256']??null)!==$b['release_binding']['sha256']||($generated['release_id']??null)!==$b['release_binding']['id']))throw new DomainException('Erzeugte XML und E-Bilanz-Jahrespaket stimmen nicht überein.');
        (new BalanceFilingRevision($this->db,$this->actor))->checkGenerated($r,$xml);
        if(($generated['revision_status']??null)!==$profile['filing_revision']['status'])throw new DomainException('Erzeugte E-Bilanz hat nicht die bestätigte Abgabeart.');
        return [$generated,$xml,$hash];
    }
    private static function generationData(array $r,array $b,array $values,array $generated):array
    {
        unset($b['approval'],$b['native_runtime']);$b['values_sha256']=$values['sha256'];
        $keys=['taxonomy','taxonomy_sha256','fact_count','asset_fact_count','asset_items','asset_schedule_sha256','taxable_asset_items','taxable_asset_schedule_sha256','taxable_asset_presentation','sections','subsidiary_result_cents','revision_status','period'];
        if($r['environment']==='production')$keys=array_merge($keys,['environment','release_id','release_sha256']);
        $b['generated']=array_intersect_key($generated,array_flip($keys));
        $data=$r['form_data'];$data['balance_preparation']=$b;return $data;
    }
    /** Local generation only. An XML draft is neither a native validation nor a transport approval. */
    public function generate(string $id,int $version):array
    {
        $r=$this->filing($id);self::editable($r,$version);$values=$this->assertCurrent($r);$b=$this->binding($r);$runtime=TaxFilings::runtimeHash(false);
        [$generated,$xml,$hash]=$this->generated($r,$values,$b);
        if(TaxFilings::runtimeHash(false)!==$runtime)throw new DomainException('Das E-Bilanz-Programm wurde während der Erzeugung geändert. Erneut vorbereiten.');
        $this->assertCurrent($r);$data=self::generationData($r,$b,$values,$generated);$data['balance_preparation']['generator_sha256']=$runtime;
        $q=$this->q("UPDATE tax_filings SET form_data=?,state='draft',version=version+1,xml_data=?,xml_sha256=?,content_sha256=?,runtime_sha256=NULL,validation=NULL,pdf=NULL,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state IN ('draft','validated','failed')",[self::json($data),$xml,$hash,TaxFilings::contentHash($xml),$this->actor,gmdate('c'),$r['club_id'],$id,$version]);
        if($q->rowCount()!==1)throw new DomainException('Entwurf während der Erzeugung verändert. Ergebnis verworfen.');
        return ['generated'=>true,'validated'=>false,'environment'=>$r['environment'],'xml_sha256'=>$hash,'sent'=>false,'transport_attempted'=>false];
    }
    public function validate(string $id,int $version):array
    {
        $r=$this->filing($id);self::editable($r,$version);$values=$this->assertCurrent($r);$b=$this->binding($r);$policy=$this->runtime??new TaxBalanceRelease();
        $proof=$r['environment']==='production'?$policy->proof($r):null;$runtime=TaxFilings::runtimeHash();
        [$generated,$xml,$hash]=$this->generated($r,$values,$b);
        $result=$policy->validate(['data_version'=>self::VERSION,'environment'=>$r['environment'],'xml'=>$xml,'xml_sha256'=>$hash]);
        unset($result['pdf_b64']);$valid=($result['code']??-1)===0&&($result['xml_sha256']??'')===$hash&&($result['environment']??'')===$r['environment']&&($result['sent']??true)===false&&($result['transport_attempted']??true)===false;
        if(TaxFilings::runtimeHash()!==$runtime||($proof!==null&&$proof!==$policy->proof($r)))throw new DomainException('ELSTER wurde während der Prüfung geändert. Erneut prüfen.');
        $this->assertCurrent($r);$data=self::generationData($r,$b,$values,$generated);if($proof!==null)$data['balance_preparation']['native_runtime']=$proof;
        $q=$this->q("UPDATE tax_filings SET form_data=?,state=?,version=version+1,xml_data=?,xml_sha256=?,content_sha256=?,runtime_sha256=?,validation=?,pdf=NULL,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state IN ('draft','validated','failed')",[self::json($data),$valid?'validated':'draft',$xml,$hash,TaxFilings::contentHash($xml),$runtime,self::json($result),$this->actor,gmdate('c'),$r['club_id'],$id,$version]);
        if($q->rowCount()!==1)throw new DomainException('Entwurf während der Prüfung verändert. Ergebnis verworfen.');
        return ['valid'=>$valid,'code'=>$result['code']];
    }
    private static function approvalHash(array $r):string
    {
        $b=$r['form_data']['balance_preparation'];unset($b['approval']);
        if(($b['binding_version']??null)===2)return hash('sha256',self::json(['balance-env-v2',$r['club_id'],$r['id'],$r['environment'],$r['tax_year'],$r['data_version'],$r['xml_sha256'],$r['runtime_sha256'],$b]));
        return hash('sha256',self::json([$r['club_id'],$r['id'],$r['xml_sha256'],$r['runtime_sha256'],$b]));
    }
    public static function assertHistoricalApproval(array $r):void
    {
        $approval=$r['form_data']['balance_preparation']['approval']??[];
        if(($approval['sha256']??'')!==self::approvalHash($r)||!is_string($approval['actor']??null))throw new DomainException('Unveränderte Freigabe der früheren E-Bilanz fehlt.');
    }
    public function revision(string $id,int $version,array $input):void
    {
        $r=$this->filing($id);self::editable($r,$version);$b=$this->binding($r);$this->sourceReport($r);
        $b['filing_revision']=(new BalanceFilingRevision($this->db,$this->actor))->prepare($r,$input);
        $this->persistDraft($r,$version,$b);
    }
    public function approve(string $id,int $version,bool $reviewed):void
    {
        $this->access();BoardAccess::require($this->db,$this->actor);$r=$this->filing($id);self::editable($r,$version);$this->assertCurrent($r);
        if(!$reviewed||$r['state']!=='validated'||$r['xml_sha256']!==hash('sha256',$r['xml_data']??'')||$r['runtime_sha256']!==TaxFilings::runtimeHash())throw new DomainException('Aktuellen Entwurf vollständig prüfen und ausdrücklich freigeben.');
        if($r['environment']==='production'){
            $proof=$r['form_data']['balance_preparation']['native_runtime']??null;
            if(!is_array($proof)||($this->runtime===null&&($proof['kind']??null)!=='original-sdk')||$proof!==($this->runtime??new TaxBalanceRelease())->proof($r))throw new DomainException('Der native E-Bilanz-Prüfnachweis fehlt oder wurde geändert. Erneut prüfen.');
        }
        $data=$r['form_data'];$data['balance_preparation']['approval']=['actor'=>$this->actor,'at'=>gmdate('c'),'sha256'=>self::approvalHash($r)];
        $q=$this->q("UPDATE tax_filings SET form_data=?,version=version+1,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state='validated'",[self::json($data),$this->actor,gmdate('c'),$r['club_id'],$id,$version]);
        if($q->rowCount()!==1)throw new DomainException('Entwurf inzwischen geändert. Erneut prüfen.');
    }
    public function assertApproved(array $r,bool $sending=false):void
    {
        $this->assertCurrent($r);$approval=$r['form_data']['balance_preparation']['approval']??[];
        if($r['environment']==='production'){
            $proof=$r['form_data']['balance_preparation']['native_runtime']??null;
            if(!is_array($proof)||($this->runtime===null&&($proof['kind']??null)!=='original-sdk'))throw new DomainException('Der native E-Bilanz-Prüfnachweis fehlt.');
            if($sending&&$proof!==($this->runtime??new TaxBalanceRelease())->proof($r,true))throw new DomainException('Der native E-Bilanz-Prüfnachweis wurde geändert. Erneut prüfen und freigeben.');
        }
        if(($approval['sha256']??'')!==self::approvalHash($r)||!is_string($approval['actor']??null))throw new DomainException('Vorstand muss genau diesen geprüften Entwurf freigeben.');
        $a=Permissions::actor($this->db,$approval['actor']);
        if($a['club_id']!==$r['club_id']||$a['finance_access']!=='write'||!BoardAccess::allowed($this->db,$approval['actor']))throw new DomainException('Vorstandsfreigabe ist nicht mehr gültig. Erneut freigeben lassen.');
    }
}
