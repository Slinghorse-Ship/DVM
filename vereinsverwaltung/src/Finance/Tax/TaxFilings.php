<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/TaxCertificateVault.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/OwnIntangibleStorage.php';
use DomainException;
use PDO;

require_once __DIR__.'/TaxFilingScope.php';
require_once __DIR__.'/TaxEricAdapter.php';
require_once dirname(__DIR__).'/Balance/BalanceFilingEnvironment.php';
require_once __DIR__.'/TaxFormSchema.php';
require_once __DIR__.'/TaxFormPresentation.php';
require_once __DIR__.'/TaxClubData.php';
require_once __DIR__.'/TaxAttachments.php';
require_once __DIR__.'/TaxFilingEvidence.php';
require_once __DIR__.'/TaxAttachmentFeedback.php';
require_once __DIR__.'/TaxFilingListMetadata.php';

/** Club-isolated, immutable approval/transport boundary. Never retries a send. */
final class TaxFilings
{
    public function __construct(private PDO $db,private string $actor,private ?BalanceFilingRuntime $balanceRuntime=null){}
    private function access(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actor);
        if(!in_array($a['finance_access'], $write?['write']:['read','write'],true))throw new DomainException('Keine Berechtigung für Steuererklärungen.');
        return $a;
    }
    private static function json(array $v):string{return json_encode(OwnIntangibleStorage::normal($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function decode(string $v):array{return json_decode($v,true,64,JSON_THROW_ON_ERROR);}
    private function query(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    public function canWrite():bool{return $this->access()['finance_access']==='write';}
    public function profile(int $year):array
    {
        $a=$this->access();$r=$this->query('SELECT * FROM tax_filing_profiles WHERE club_id=? AND tax_year=?',[$a['club_id'],$year])->fetch();
        $defaults=TaxClubData::profile(TaxClubData::stored($this->db,$this->actor)['tax'],$year);
        return ['version'=>$r?(int)$r['version']:0,'scope'=>TaxFilingScope::build($year,$r?self::decode($r['answers']):$defaults),'settings_defaults'=>$defaults];
    }
    public function saveProfile(int $year,array $answers,int $version):void
    {
        $this->access(true);$scope=TaxFilingScope::build($year,$answers);$now=gmdate('c');
        try{\Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($year,$scope,$version,$now):void{
            $a=$this->access(true);$old=$this->query('SELECT version FROM tax_filing_profiles WHERE club_id=? AND tax_year=? FOR UPDATE NOWAIT',[$a['club_id'],$year])->fetchColumn();
            if(($old===false?0:(int)$old)!==$version)throw new DomainException('Das Steuerprofil wurde inzwischen geändert. Bitte neu laden.',409);
            if($version===0)$this->query('INSERT INTO tax_filing_profiles(club_id,tax_year,version,answers,updated_by,updated_at) VALUES(?,?,1,?,?,?)',[$a['club_id'],$year,self::json($scope['answers']),$this->actor,$now]);
            else{$update=$this->query('UPDATE tax_filing_profiles SET answers=?,version=version+1,updated_by=?,updated_at=? WHERE club_id=? AND tax_year=? AND version=?',[self::json($scope['answers']),$this->actor,$now,$a['club_id'],$year,$version]);if($update->rowCount()!==1)throw new DomainException('Das Steuerprofil wurde inzwischen geändert. Bitte neu laden.',409);}
        });}catch(\PDOException$failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Steuerprofil wird gerade bearbeitet. Bekannte Angaben bleiben erhalten.',503,$failure);throw$failure;}
    }
    public function recent(int $year):array
    {
        $a=$this->access();$rows=$this->query('SELECT id,family,data_version,environment,state,version,created_at,updated_at,'.TaxFilingListMetadata::projection().' FROM tax_filings WHERE club_id=? AND tax_year=? ORDER BY created_at DESC,id LIMIT 100',[$a['club_id'],$year])->fetchAll();
        foreach($rows as &$row){
            $row['period_label']=(string)$year;
            if($row['family']==='balance'){
                try{
                    $metadata=TaxFilingListMetadata::read($row['period_metadata'],$year);
                    if($metadata['dated']){
                        $p=$metadata['period'];
                        $row['period_label']=implode('.',array_reverse(explode('-',$p['start']))).' – '.implode('.',array_reverse(explode('-',$p['end'])));
                    }
                }catch(\JsonException|DomainException){$row['period_label']='Zeitraum prüfen';}
            }
            unset($row['period_metadata']);
        }unset($row);return $rows;
    }
    public function get(string $id):array
    {
        $a=$this->access();$r=$this->query('SELECT * FROM tax_filings WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();
        if(!$r)throw new DomainException('Erklärung nicht gefunden.');
        foreach(['form_data','scope_snapshot','validation'] as $key)$r[$key]=$r[$key]===null?[]:self::decode($r[$key]);
        return $r;
    }
    public function attempts(string $id):array
    {
        // Receipt binding needs the actual XML and tenant/state, not another
        // decoded copy of the full journal, asset and predecessor evidence.
        $a=$this->access();$r=$this->query('SELECT club_id,environment,state,xml_sha256,xml_data FROM tax_filings WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();
        if(!$r)throw new DomainException('Erklärung nicht gefunden.');
        $rows=$this->query('SELECT id,state,started_at,completed_at,transfer_ticket,response,xml_sha256,environment FROM tax_filing_attempts WHERE club_id=? AND filing_id=? ORDER BY started_at',[$r['club_id'],$id])->fetchAll();
        foreach($rows as &$row){
            $response=$row['response']?self::decode($row['response']):[];$row['message']=(string)($response['message']??'');
            $row['receipt_bound']=in_array($row['state'],['sent_test','sent_production'],true)&&($response['code']??null)===0&&($response['sent']??null)===true
                &&is_string($row['transfer_ticket'])&&$row['transfer_ticket']!==''&&($response['transfer_ticket']??null)===$row['transfer_ticket']
                &&is_string($r['xml_sha256'])&&$r['xml_sha256']!==''&&($response['xml_sha256']??null)===$r['xml_sha256']&&$row['xml_sha256']===$r['xml_sha256']
                &&is_string($r['xml_data'])&&hash_equals(hash('sha256',$r['xml_data']),$r['xml_sha256'])
                &&($response['environment']??null)===$r['environment']&&$row['environment']===$r['environment']&&$row['state']==='sent_'.$r['environment'];
            unset($row['response']);
        }unset($row);return $rows;
    }
    public function create(string $dataVersion,string $environment,int $year):string
    {
        if($dataVersion==='Bilanz_6_9')throw new DomainException('Die E-Bilanz aus dem geprüften Bilanzjournal vorbereiten.');
        $a=$this->access(true);$s=TaxFormSchema::load($dataVersion);$p=$s->profile();
        if(($p['year']??null)!==null&&$p['year']!==$year)throw new DomainException('Formularstand und Erklärungsjahr stimmen nicht überein.');
        if(!in_array($environment,['test','production'],true))throw new DomainException('Abgabeumgebung ungültig.');
        if($environment==='production'&&($p['test_only']??false))throw new DomainException('Dieser amtliche Formularstand ist ausschließlich für Tests zugelassen.');
        $scope=$this->profile($year)['scope'];$id=bin2hex(random_bytes(16));$now=gmdate('c');
        // Production drafts reuse stored master data; synthetic test drafts always stay empty.
        $data=['fields'=>[],'counts'=>[],'header'=>['office'=>'','supplier'=>''],'attachments'=>[]];
        if($environment==='production')$data=TaxClubData::form($s,TaxClubData::stored($this->db,$this->actor),$year);
        $this->query('INSERT INTO tax_filings(club_id,id,family,data_version,tax_year,environment,form_data,scope_snapshot,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$p['family'],$dataVersion,$year,$environment,self::json($data),self::json($scope),$this->actor,$this->actor,$now,$now]);
        return $id;
    }
    private function editable(array $r,int $version):void
    {
        if(!in_array($r['state'],['draft','validated','failed'],true))throw new DomainException('Dieser Abgabeauftrag ist unveränderlich. Bei unklarem Status zuerst die Übermittlung klären.');
        if((int)$r['version']!==$version)throw new DomainException('Die Erklärung wurde inzwischen geändert. Bitte neu laden.');
    }
    public function save(string $id,int $version,array $fields,array $header,?string $addGroup=null,?array $documentIds=null):void
    {
        $this->access(true);$r=$this->get($id);$this->editable($r,$version);$data=$r['form_data'];
        if($r['data_version']==='Bilanz_6_9'||isset($data['balance_preparation']))throw new DomainException('E-Bilanz-Angaben im zugehörigen Bilanzentwurf ändern.');
        $s=TaxFormSchema::load($r['data_version']);
        if(isset($data['auto_preparation']))throw new DomainException('Diese Angaben im Steuerassistenten ändern und erneut übernehmen.');
        if(isset($data['parent_binding'])&&($fields||$addGroup!==null))throw new DomainException('Absender und Steuerangaben werden aus der zugehörigen Erklärung übernommen.');
        $counts=$data['counts'];
        if($addGroup!==null){$groups=$s->repeatGroups($counts);if(!isset($groups[$addGroup])||$groups[$addGroup]['count']>=$groups[$addGroup]['max'])throw new DomainException('Weitere Zeile hier nicht möglich.');$counts[$addGroup]=$groups[$addGroup]['count']+1;}
        $data['fields']=$s->normalize(array_replace($data['fields'],$fields),$counts);$data['counts']=$counts;
        if($documentIds!==null){
            if(!isset($s->profile()['attachment_version'])||count($documentIds)>20||count(array_unique($documentIds,SORT_REGULAR))!==count($documentIds))throw new DomainException('Auswahl der Anlagen prüfen.');
            $data['attachments']=(new TaxAttachments($this->db,$this->actor))->select($documentIds,(int)$r['tax_year']);
        }
        foreach(['office','supplier'] as $key){$v=$header[$key]??$data['header'][$key];if(!is_string($v)||mb_strlen($v)>1000||preg_match('/[\x00-\x1F]/',$v))throw new DomainException('Absenderangaben prüfen.');$data['header'][$key]=trim($v);}
        if(isset($header['feedback_email'])){
            if(!is_string($header['feedback_email'])||strlen($header['feedback_email'])>128||($header['feedback_email']!==''&&!filter_var($header['feedback_email'],FILTER_VALIDATE_EMAIL)))throw new DomainException('E-Mail für Anlagen-Rückmeldungen prüfen.');
            $data['header']['feedback_email']=$header['feedback_email'];
        }
        $scope=$this->profile((int)$r['tax_year'])['scope'];
        $q=$this->query("UPDATE tax_filings SET form_data=?,scope_snapshot=?,state='draft',version=version+1,xml_data=NULL,xml_sha256=NULL,content_sha256=NULL,runtime_sha256=NULL,validation=NULL,pdf=NULL,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state IN ('draft','validated','failed')",[self::json($data),self::json($scope),$this->actor,gmdate('c'),$r['club_id'],$id,$version]);
        if($q->rowCount()!==1)throw new DomainException('Die Erklärung wurde inzwischen geändert. Bitte neu laden.');
    }
    public static function runtimeHash(bool $native=true):string
    {
        $c=$native?TaxEricAdapter::configuration():TaxEricAdapter::generationConfiguration();
        $parts=hash_file('sha256',$c['_path']).($native?hash_file('sha256',$c['catalog']).hash_file('sha256',$c['library']):'balance-generation-only');
        foreach(['Finance/Balance/BalanceFilingEnvironment.php','Finance/Tax/TaxBalanceRelease.php','../tools/eric-process.py','Finance/Tax/WindowsEricProcess.php','../tools/eric-bridge.py','../tools/eric_feedback.py','../tools/ebilanz_builder.py','../tools/ebilanz_commercial.py','../tools/ebilanz_reserve_closing.py','../tools/ebilanz_own_shares.py','../tools/ebilanz_income_use.py','../resources/ebilanz/income-use-network.json','../tools/ebilanz_assets.py','../tools/ebilanz_mapping.py','../tools/datev_years.py','../tools/datev_bank_corrections.py','../tools/datev_issuer_corrections.py','../tools/datev_loan_corrections.py','../tools/ebilanz_degressive.py','../tools/ebilanz_tax_assets.py','../tools/ebilanz_partial_assets.py','../tools/ebilanz_sales.py','../tools/ebilanz_club_scope.py','../tools/ebilanz_whole_club.py','../tools/ebilanz_combined_club.py','../tools/ebilanz_subsidiary.py','Finance/Balance/BalanceSubsidiaryClubValues.php',
            'Finance/Balance/BalanceMappingReview.php','Finance/Balance/BalanceInvoiceClassification.php','../tools/ebilanz_operating.py','../tools/ebilanz_working_capital.py','../tools/ebilanz_release.py','../resources/ebilanz/releases.json','../resources/ebilanz/taxonomy-6.9.json','../resources/ebilanz/skr42-6.9.json','../data/skr42-2025.json','../data/skr42-2026.json',
            '../tools/taxonomy_purposes.py','../resources/ebilanz/taxonomy-6.10.json','../resources/accounting/purposes.json','../resources/accounting/reviews/taxonomy-6.10.json',
            '../tools/taxonomy_sources.py','../resources/ebilanz/taxonomies.json','../resources/ebilanz/taxonomy-6.7.json','../resources/ebilanz/taxonomy-6.8.json',
            'Finance/Tax/DatevStatementCatalog.php','../resources/accounting/statement-groups.json','../resources/accounting/statements/2025.json','../resources/accounting/statements/2026.json',
            'Finance/Accounting/FinancialReserveXml.php','../tools/ebilanz_financial_reserve_xml.py','Finance/Assets/AssetTypes.php','../tools/ebilanz_asset_types.py','../resources/ebilanz/asset-types.json',
            'Finance/Assets/AssetOriginalDepreciation.php','../tools/ebilanz_original_depreciation.py','../tools/ebilanz_original_leasing.py','Finance/Balance/BalanceOriginalOffsets.php','../tools/ebilanz_original_offsets.py','Finance/Balance/BalanceOriginalReclassification.php','../tools/ebilanz_original_reclassification.py','Finance/Balance/BalanceTechnicalClosing.php','../tools/ebilanz_technical_closing.py',
            'Finance/Assets/FinancialInvestment.php','../tools/ebilanz_financial_profile.py','Finance/Assets/AssetValuation.php','Finance/Assets/AssetValuationEvidence.php','Finance/Balance/BalanceReinsuranceStoredSources.php','Finance/Balance/BalanceOriginalLandStoredSources.php','Finance/Assets/OriginalLeasing.php','Finance/Balance/BalanceOriginalLeasingStoredSources.php','Finance/Assets/OriginalPensionOffsets.php','Finance/Tax/PensionOffsetReview.php','Finance/Tax/DatevComparisonCatalog.php','../tools/datev_comparison.py','../resources/accounting/reviews/comparison-only-2025-r82.json','Finance/Assets/OriginalSubstance.php','Finance/Balance/BalanceSubstanceSources.php','../tools/ebilanz_substance_original.py','../views/Finance/Assets/asset-substance-plan.php','../views/Finance/Assets/asset-substance-values.php','../views/Finance/Balance/balance-tax-equalization.php','Finance/Accounting/FinancialReserveCalculation.php','Finance/Accounting/FinancialReserveIntake.php','../views/Finance/Balance/balance-tax-financial-reserve.php','Finance/Assets/OriginalTaxEqualization.php','Finance/Tax/TaxEqualizationReview.php','../tools/ebilanz_tax_equalization.py','Finance/Assets/OriginalBasisReduction6b.php','Finance/Tax/BasisReduction6bReview.php','../tools/ebilanz_basis_reduction_6b.py','../views/Finance/Balance/balance-tax-basis-6b.php','Finance/Assets/OriginalBasisReduction7g.php','Finance/Tax/BasisReduction7gReview.php','../tools/ebilanz_basis_reduction_7g.py','../views/Finance/Balance/balance-tax-basis-7g.php','Finance/Assets/OriginalSpecialTax7b.php','Finance/Tax/SpecialTax7bReview.php','../tools/ebilanz_special_tax_7b.py','../views/Finance/Balance/balance-tax-special-7b.php','Finance/Assets/OriginalSpecialTax7g.php','Finance/Tax/SpecialTax7gReview.php','../tools/ebilanz_special_tax_7g.py','../views/Finance/Balance/balance-tax-special-7g.php','../tools/ebilanz_pension_offsets.py','../views/Finance/Balance/balance-tax-pension-offsets.php','Finance/Assets/OriginalExceptionalDepreciation.php','Finance/Balance/BalanceExceptionalSources.php','../tools/ebilanz_exceptional_original.py','../views/Finance/Assets/asset-exceptional-year.php','../views/Finance/Assets/asset-original-depreciation.php','../views/Finance/Assets/asset-leasing-evidence.php','Finance/Balance/BalanceCostAssetSources.php','../tools/ebilanz_asset_valuation.py','../resources/accounting/reviews/cost-assets.json',
            'Finance/Balance/BalanceCostAssetTax.php','Finance/Balance/BalanceCostAssetMapping.php','../tools/ebilanz_cost_asset_tax.py','../tools/ebilanz_cost_asset_grid.py','../tools/ebilanz_cost_assets.py',
            'Finance/Balance/BalanceCostAssetAllocation.php','../tools/ebilanz_cost_asset_allocation.py',
            'Finance/Assets/PartnershipCapitalBasis.php','Finance/Assets/PartnershipBookBasis.php','Finance/Assets/PartnershipHoldingSources.php','Finance/Assets/PartnershipHoldingEvidence.php','Finance/Assets/PartnershipHoldingJournals.php','Finance/Assets/PartnershipHoldingMatrix.php','Finance/Balance/BalancePartnershipHoldings.php',
            'Finance/Assets/PartnershipBookPlans.php','Finance/Balance/BalancePartnershipBookSources.php','Finance/Balance/BalancePartnershipStoredSources.php','Finance/Assets/PartnershipHoldingReview.php','../resources/ebilanz/partnership-column-labels-6.9.json','../public/partnership-review.js',
            'Finance/Assets/OwnIntangibleOriginalSources.php','Finance/Assets/OwnIntangibleBookBasis.php','Finance/Assets/OwnIntangibleTaxExclusion.php','Finance/Assets/OwnIntangibleJournals.php','Finance/Assets/OwnIntangibleEvidence.php','Finance/Assets/OwnIntangiblePlans.php','Finance/Assets/OwnIntangibleStorage.php','Finance/Balance/BalanceOwnIntangibleSources.php','Finance/Balance/BalanceOwnIntangibleValues.php','Finance/Balance/BalanceOwnIntangibleStoredSources.php','Finance/Assets/OwnIntangibleReview.php',
            '../tools/ebilanz_own_intangible_sources.py','../tools/ebilanz_own_intangible_book.py','../tools/ebilanz_own_intangible_tax.py','../tools/ebilanz_own_intangible_journals.py','../public/own-intangible-register.js','../views/Finance/Assets/asset-own-cost-rows.php','../views/Finance/Assets/asset-own-plan.php','../views/Finance/Assets/asset-own-values.php','../views/Finance/Assets/asset-own-history.php','../views/Finance/Balance/balance-tax-own-intangible.php',
            '../tools/ebilanz_own_intangible_values.py',
            'Finance/Assets/PoolOriginalSources.php','Finance/Assets/PoolOriginalBooks.php','Finance/Assets/PoolOriginalJournals.php','Finance/Assets/PoolOriginalBookJournals.php','Finance/Balance/BalancePoolOriginalValues.php','Finance/Balance/BalancePoolStoredSources.php','Finance/Assets/PoolOriginalReview.php','../tools/ebilanz_pool_original_sources.py','../tools/ebilanz_pool_original_books.py','../tools/ebilanz_pool_original_journals.py','../tools/ebilanz_pool_original_book_journals.py','../tools/ebilanz_pool_original_values.py','../resources/accounting/reviews/pool-original-member-r73.json','../views/Finance/Balance/balance-tax-pool-original.php',
            'Finance/Assets/OwnIntangibleBookJournals.php','Finance/Balance/BalanceOwnIntangibleBooks.php','Finance/Balance/BalanceOwnIntangibleMapping.php','../tools/ebilanz_own_intangible_book_journals.py','../tools/ebilanz_own_intangible_books.py',
            '../tools/ebilanz_partnership_basis.py','../tools/ebilanz_partnership_book.py','../tools/ebilanz_partnership_sources.py','../tools/ebilanz_partnership_journals.py','../tools/ebilanz_partnership_matrix.py','../tools/ebilanz_partnership_holdings.py',
            'Finance/Tax/TaxFilings.php','Finance/Tax/TaxEricAdapter.php','Finance/Balance/BalanceTaxFiling.php','Finance/Balance/BalanceTaxValues.php','Finance/Balance/BalanceTaxAssetValues.php','Finance/Balance/BalanceTaxAssetSale.php','Finance/Balance/BalanceTaxMapping.php','Finance/Balance/BalanceAccountClosing.php','../tools/ebilanz_account_closing.py','Finance/Balance/BalanceDirectionalClosing.php','../tools/ebilanz_directional_closing.py','Finance/Balance/BalanceCalculation.php','Finance/Balance/BalancePeriod.php','Finance/Balance/BalancePeriodSources.php','Finance/Balance/BalancePeriodAssets.php','Finance/Balance/BalancePeriodDayAllocation.php','Finance/Balance/BalancePeriodDailyDegressive.php','Finance/Balance/BalancePeriodDepreciation.php','Finance/Balance/BalanceAssetSchedule.php',
            'Finance/Balance/BalanceLedger.php','Finance/Balance/BalanceSources.php','Finance/Balance/BalanceSourceHistory.php','Finance/Balance/BalanceDisposalAccounts.php','Finance/Balance/BalanceSegmentCalculation.php','Finance/Balance/BalanceSegments.php','Finance/Balance/BalanceClubTaxReport.php','Finance/Balance/BalanceWholeClubReport.php','Finance/Balance/BalanceCapitalComparison.php','Finance/Balance/BalanceCombinedClubValues.php','Finance/Balance/BalanceClearings.php','Finance/Balance/BalanceInternalClearing.php','Finance/Balance/BalanceInternalClearingExport.php','Finance/Balance/BalanceClearingStatement.php','Finance/Balance/BalanceClubIncomeReconciliation.php','Finance/Balance/BalanceClubTaxReconciliation.php','Finance/Balance/BalanceTaxReconciliations.php','Finance/Assets/AssetBooks.php','Finance/Assets/AssetDisposal.php','Finance/Assets/AssetSale.php',
            'Finance/Assets/AssetPartialDisposal.php','Finance/Assets/AssetPartialDisposalReview.php','Finance/Assets/AssetPartialDisposalRecords.php','Finance/Assets/GwgDepreciation.php','Finance/Assets/DegressiveDepreciation.php','Finance/Balance/BalanceAssetAllocation.php','Finance/Balance/BalanceAssetAllocationDisposal.php','Finance/Balance/BalanceClosingCorrection.php','Finance/Balance/BalanceClosingCorrections.php','Finance/Balance/BalanceFilingRevision.php','Finance/Balance/BalancePeriodLedger.php','Finance/Balance/BalancePeriodCorrectionWorkflow.php','Finance/Balance/BalancePeriodExport.php',
            'Finance/Balance/BalancePeriodAreas.php','Finance/Balance/BalancePeriodAssetAllocation.php','Finance/Balance/BalanceCalendarAreaSource.php','Finance/Balance/BalancePeriodDisposal.php','Finance/Balance/BalancePeriodDisposalEvidence.php','Finance/Balance/BalancePeriodDegressive.php','Finance/Balance/BalancePeriodSale.php','Finance/Balance/BalancePeriodAssetCarry.php','Finance/Balance/BalancePeriodInvoiceCarry.php',
            'Finance/Balance/BalancePeriodTaxValues.php','Finance/Balance/BalancePeriodTaxAssetValues.php','Finance/Balance/BalancePeriodTaxReconciliations.php','Finance/Balance/BalancePeriodFilingValues.php','Finance/Balance/BalancePeriodFilingSource.php','Finance/Balance/BalancePeriodWholeFiling.php','Finance/Balance/BalanceCalendarFilingSource.php','Finance/Balance/BalancePeriodAssetUse.php','Finance/Balance/BalancePeriodSphereChange.php','Finance/Balance/BalancePeriodSphereEvidence.php','Finance/Balance/BalancePeriodSphereAllocation.php','Finance/Balance/BalanceReadSnapshot.php',
            'Finance/Balance/BalancePeriodSphereTax.php','Finance/Balance/BalancePeriodAssetExpenseSources.php','Finance/Balance/BalancePeriodBusinessFiling.php','Finance/Balance/BalancePeriodClubReport.php','Finance/Balance/BalancePeriodClubFilingValues.php','Finance/Balance/BalancePeriodClubFiling.php','Finance/Balance/BalancePeriodCombinedValues.php','Finance/Balance/BalanceCalendarClubSource.php','Finance/Balance/BalanceSourceRead.php','Finance/Tax/TaxFilingListMetadata.php','../tools/ebilanz_calendar_club.py','../tools/ebilanz_period_club.py','../tools/ebilanz_period_spheres.py',
            '../tools/ebilanz_asset_allocation.py','../tools/ebilanz_period.py','../tools/ebilanz_period_assets.py','../tools/ebilanz_period_asset_math.py','../tools/ebilanz_calendar_source.py'] as $file){
            $path=dirname(__DIR__, 2).'/'.$file;
            if(!is_file($path)||!is_readable($path))throw new DomainException('ELSTER-Programmpaket unvollständig. Bitte den Betrieb prüfen.');
            $hash=hash_file('sha256',$path);
            if($hash===false)throw new DomainException('ELSTER-Programmstand konnte nicht geprüft werden.');
            $parts.=$hash;
        }
        return hash('sha256',$parts);
    }
    public static function contentHash(string $xml):string
    {
        if(strlen($xml)>4194304||preg_match('/<!\s*(DOCTYPE|ENTITY)/i',$xml))throw new DomainException('XML ungültig.');
        $d=new \DOMDocument();$d->preserveWhiteSpace=false;
        if(!$d->loadXML($xml,LIBXML_NONET))throw new DomainException('XML ungültig.');
        foreach(iterator_to_array($d->getElementsByTagNameNS(TaxFormSchema::NS,'NutzdatenTicket')) as $node)$node->nodeValue='';
        $canonical=$d->C14N();if($canonical===false)throw new DomainException('XML nicht normalisierbar.');return hash('sha256',$canonical);
    }
    private static function pdf(array &$result):?string
    {
        $encoded=$result['pdf_b64']??null;unset($result['pdf_b64']);
        if($encoded===null)return null;
        $bytes=is_string($encoded)?base64_decode($encoded,true):false;
        if($bytes===false||strlen($bytes)>8*1024*1024||!str_starts_with($bytes,'%PDF-'))throw new DomainException('ELSTER-PDF fehlt oder ist beschädigt.');
        return $bytes;
    }
    public function validate(string $id,int $version):array
    {
        $this->access(true);$r=$this->get($id);$this->editable($r,$version);
        if($r['data_version']==='Bilanz_6_9'||isset($r['form_data']['balance_preparation'])){
            require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceTaxFiling.php';return (new BalanceTaxFiling($this->db,$this->actor,$this->balanceRuntime))->validate($id,$version);
        }
        $s=TaxFormSchema::load($r['data_version']);$c=TaxEricAdapter::configuration();$data=$r['form_data'];
        $this->checkAutomaticSource($r);
        $attachments=(new TaxAttachments($this->db,$this->actor))->files($data['attachments']??[]);
        if($r['family']==='receipts'&&!$attachments)throw new DomainException('Mindestens einen PDF-Nachweis auswählen.');
        $xml=$s->xml($data['fields'],$data['counts'],$data['header'],$r['environment'],$c['manufacturer_id'],$r['id'],$attachments);$hash=hash('sha256',$xml);$runtime=self::runtimeHash();
        $request=['data_version'=>$r['data_version'],'environment'=>$r['environment'],'xml'=>$xml,'xml_sha256'=>$hash];
        $result=($s->profile()['pdf_supported']??false)?TaxEricAdapter::preview($request):TaxEricAdapter::validate($request);
        $pdf=self::pdf($result);$valid=$result['code']===0&&($result['xml_sha256']??'')===$hash&&($result['environment']??'')===$r['environment'];
        if(($s->profile()['pdf_supported']??false)&&$pdf===null)$valid=false;
        if($runtime!==self::runtimeHash())throw new DomainException('ELSTER wurde während der Prüfung aktualisiert. Bitte erneut prüfen.');
        $this->access(true);
        $q=$this->query("UPDATE tax_filings SET state=?,version=version+1,xml_data=?,xml_sha256=?,content_sha256=?,runtime_sha256=?,validation=?,pdf=?,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state IN ('draft','validated','failed')",[$valid?'validated':'draft',$xml,$hash,self::contentHash($xml),$runtime,self::json($result),$pdf,$this->actor,gmdate('c'),$r['club_id'],$id,$version]);
        if($q->rowCount()!==1)throw new DomainException('Die Angaben wurden während der Prüfung geändert. Ergebnis verworfen; erneut prüfen.');
        return ['valid'=>$valid,'code'=>$result['code']];
    }
    public function ready(string $id,int $version,string $confirmation,bool $reviewed,bool $synthetic):array
    {
        $this->access(true);$r=$this->get($id);$this->editable($r,$version);
        $this->checkAutomaticSource($r);
        if(!$reviewed||$confirmation!=='SEND-'.strtoupper($r['environment']))throw new DomainException('Die vollständige Vorschau prüfen und die Abgabe ausdrücklich bestätigen.');
        if($r['state']!=='validated'||!$r['xml_data']||$r['xml_sha256']!==hash('sha256',$r['xml_data'])||$r['runtime_sha256']!==self::runtimeHash())throw new DomainException('Erklärung zuerst mit dem aktuellen Formularstand prüfen.');
        if($r['data_version']==='Bilanz_6_9')(new BalanceTaxFiling($this->db,$this->actor,$this->balanceRuntime))->assertApproved($r,true);
        $c=TaxEricAdapter::configuration();$p=$c['profiles'][$r['data_version']]??[];
        if(($p['transport_enabled']??false)!==true)throw new DomainException('Übermittlung für diesen Formularstand noch nicht freigegeben.');
        if($r['environment']==='production'){
            BoardAccess::require($this->db,$this->actor);
            if(($p['test_only']??false)===true)throw new DomainException('Dieser amtliche Formularstand ist ausschließlich für Tests zugelassen.');
            if(($c['production_enabled']??false)!==true||!in_array($r['data_version'],$c['production_versions']??[],true))throw new DomainException('Echte Abgaben sind noch nicht freigegeben.');
            $scope=$this->profile((int)$r['tax_year'])['scope'];
            if(!$scope['complete']||self::json($scope)!==self::json($r['scope_snapshot']))throw new DomainException('Steuerprofil vollständig klären und die Erklärung mit diesem Stand erneut speichern und prüfen.');
            $scopeFamily=$r['family']==='balance'?'ebilanz':$r['family'];
            if(($scope['families'][$scopeFamily]['state']??'review')==='review')throw new DomainException('Abgabepflicht für dieses Verfahren ist noch zu klären.');
        }elseif(!$synthetic)throw new DomainException('Testabgaben dürfen nur erfundene Testdaten enthalten.');
        (new TaxAttachments($this->db,$this->actor))->files($r['form_data']['attachments']??[]);
        if(($r['form_data']['attachments']??[])&&($c['attachment_feedback_enabled']??false)!==true)throw new DomainException('Anlagen-Rückmeldungen müssen vor dem Belegversand eingerichtet sein.');
        if($r['environment']==='production'&&($r['form_data']['attachments']??[])&&!filter_var($r['form_data']['header']['feedback_email']??'',FILTER_VALIDATE_EMAIL))throw new DomainException('E-Mail für Hinweise zu beanstandeten Anlagen angeben und den Belegauftrag erneut prüfen.');
        return $r;
    }
    public function send(string $id,int $version,#[\SensitiveParameter] string $certificate,#[\SensitiveParameter] string $pin,string $confirmation,bool $reviewed,bool $synthetic):array
    {
        $r=$this->ready($id,$version,$confirmation,$reviewed,$synthetic);
        (new TaxFilingEvidence($this->db,$this->actor))->assertCurrent($r,true);
        if(strlen($certificate)<1||strlen($certificate)>1048576||strlen($pin)<1||strlen($pin)>256||str_contains($pin,chr(0)))throw new DomainException('Zertifikat und PIN prüfen.');
        $attempt=bin2hex(random_bytes(16));$requestKey=hash('sha256',$r['id'].':'.$version);$now=gmdate('c');
        // Claim and commit before any external call. A crash leaves a visible locked "sending" state.
        $this->db->beginTransaction();try{
            // Same lock order as ledger/allocation writers. It also serializes
            // identical-content claims in different drafts of the same club.
            // Acquire before the transaction's first consistent read.
            if(!$this->query('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$r['club_id']])->fetchColumn())throw new DomainException('Verein nicht verfügbar.');
            $r=$this->ready($id,$version,$confirmation,$reviewed,$synthetic);
            (new TaxFilingEvidence($this->db,$this->actor))->assertCurrent($r,true);
            if($this->query("SELECT 1 FROM tax_filing_attempts WHERE club_id=? AND environment=? AND content_sha256=? AND state<>'failed'",[$r['club_id'],$r['environment'],$r['content_sha256']])->fetchColumn())throw new DomainException('Für diesen Inhalt gibt es bereits einen Abgabeversuch. Den vorhandenen Auftrag prüfen.');
            $this->query("INSERT INTO tax_filing_attempts(club_id,id,filing_id,request_key,environment,xml_sha256,content_sha256,approved_by,started_at,state) VALUES(?,?,?,?,?,?,?,?,?,'sending')",[$r['club_id'],$attempt,$id,$requestKey,$r['environment'],$r['xml_sha256'],$r['content_sha256'],$this->actor,$now]);
            $q=$this->query("UPDATE tax_filings SET state='sending',version=version+1,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state='validated'",[$this->actor,$now,$r['club_id'],$id,$version]);
            if($q->rowCount()!==1)throw new DomainException('Abgabe wurde bereits bearbeitet.');$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $pdf=null;$ticket='';$state='unknown';
        try{
            $request=['data_version'=>$r['data_version'],'environment'=>$r['environment'],'xml'=>$r['xml_data'],'xml_sha256'=>$r['xml_sha256'],'certificate_b64'=>base64_encode($certificate),'pin'=>$pin,'confirm'=>$confirmation,'synthetic_confirmed'=>$synthetic];
            try{$result=$r['family']==='balance'&&$this->balanceRuntime!==null?$this->balanceRuntime->transmit($request):TaxEricAdapter::transmit($request);}finally{unset($request);}
            $pdf=self::pdf($result);$ticket=is_string($result['transfer_ticket']??null)?$result['transfer_ticket']:'';
            $success=($result['sent']??false)===true&&$result['code']===0&&$ticket!==''&&strlen($ticket)<=256&&($result['xml_sha256']??'')===$r['xml_sha256']&&($result['environment']??'')===$r['environment']&&($r['family']!=='balance'||($result['transport_attempted']??null)===true);
            if($success)$state=$r['environment']==='test'?'sent_test':'sent_production';
            elseif(($result['transport_attempted']??true)===false&&($r['family']!=='balance'||($result['sent']??null)===false))$state='failed';
        }catch(\Throwable){$result=['code'=>-1,'message'=>'Keine verlässliche Abgabebestätigung. Übermittlung prüfen, nicht erneut senden.'];}
        finally{if(function_exists('sodium_memzero')){sodium_memzero($certificate);sodium_memzero($pin);}}
        $this->db->beginTransaction();try{
            $this->query("UPDATE tax_filing_attempts SET state=?,response=?,transfer_ticket=?,pdf=?,completed_at=? WHERE club_id=? AND id=? AND state='sending'",[$state,self::json($result),$ticket?:null,$pdf,gmdate('c'),$r['club_id'],$attempt]);
            $this->query("UPDATE tax_filings SET state=?,version=version+1,updated_at=? WHERE club_id=? AND id=? AND state='sending'",[$state,gmdate('c'),$r['club_id'],$id]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw new DomainException('Übermittlung gestartet, Bestätigung konnte nicht gespeichert werden. Nicht erneut senden.');}
        return ['state'=>$state,'ticket'=>$ticket];
    }
    private function checkAutomaticSource(array $filing):void
    {
        (new TaxFilingEvidence($this->db,$this->actor))->assertCurrent($filing);
        if($filing['data_version']==='Bilanz_6_9'||isset($filing['form_data']['balance_preparation'])){
            require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceTaxFiling.php';(new BalanceTaxFiling($this->db,$this->actor,$this->balanceRuntime))->assertCurrent($filing);
        }
        if(!isset($filing['form_data']['auto_preparation']))return;
        require_once __DIR__.'/TaxAutoPreparation.php';
        (new TaxAutoPreparation($this->db,$this->actor))->assertCurrent($filing);
    }
    public function download(string $id,string $kind):array
    {
        $r=$this->get($id);
        if($kind==='preview'&&$r['pdf'])return ['bytes'=>$r['pdf'],'mime'=>'application/pdf','name'=>'ELSTER-'.$r['environment'].'-Vorschau.pdf'];
        if($kind==='xml'&&$r['xml_data'])return ['bytes'=>$r['xml_data'],'mime'=>'application/xml','name'=>'ELSTER-'.$r['environment'].'.xml'];
        if(preg_match('/^attachment-([1-9][0-9]?)$/D',$kind,$m)&&$r['xml_data']){
            $selected=$r['form_data']['attachments'][(int)$m[1]-1]??null;
            if($selected){
                $d=new \DOMDocument();if(!$d->loadXML($r['xml_data'],LIBXML_NONET))throw new DomainException('Belegauftrag ist beschädigt.');
                foreach($d->getElementsByTagName('Anhang') as $node){
                    if($node->getElementsByTagName('Dateibezeichnung')->item(0)?->textContent!==$selected['name'])continue;
                    $bytes=base64_decode($node->getElementsByTagName('Dateiinhalt')->item(0)?->textContent??'',true);
                    if($bytes!==false&&str_starts_with($bytes,'%PDF-')&&hash_equals($selected['sha256'],hash('sha256',$bytes)))return ['bytes'=>$bytes,'mime'=>'application/pdf','name'=>$selected['name']];
                }
            }
        }
        if($kind==='receipt'){
            $a=$this->query("SELECT pdf,response FROM tax_filing_attempts WHERE club_id=? AND filing_id=? AND state IN ('sent_test','sent_production') ORDER BY started_at DESC LIMIT 1",[$r['club_id'],$id])->fetch();
            if($a&&$a['pdf'])return ['bytes'=>$a['pdf'],'mime'=>'application/pdf','name'=>'ELSTER-'.$r['environment'].'-Abgabeprotokoll.pdf'];
            if($a&&$r['data_version']==='Bilanz_6_9'){
                $response=self::decode($a['response']);$xml=$response['server_xml']??'';
                if(is_string($xml)&&$xml!==''&&strlen($xml)<=4194304&&!preg_match('/<!\s*(DOCTYPE|ENTITY)/i',$xml)){
                    $d=new \DOMDocument();$previous=libxml_use_internal_errors(true);
                    try{$valid=$d->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
                    if($valid)return ['bytes'=>$xml,'mime'=>'application/xml','name'=>'ELSTER-test-Abgabeprotokoll.xml'];
                }
            }
        }
        throw new DomainException('Dieses Dokument ist noch nicht verfügbar.');
    }
}
