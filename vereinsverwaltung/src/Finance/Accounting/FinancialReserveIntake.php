<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/FinancialReserveCalculation.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';

/** Evidence intake only. No account rule, tax adjustment or XML approval. */
final class FinancialReserveIntake
{
    public const FLAGS=['original_personal_scope_reviewed','whole_business_transfer_reviewed','reserve_continuity_reviewed','no_deadline_restart_reviewed','no_earlier_reinvestment_reviewed'];
    public const DOCS=['original','transfer','continuity','election'];
    public const AMOUNTS=['sale_price','sale_cost','sold_tax_book','formed','available','reduction','cost'];
    public function __construct(private PDO $db,private string $actor){}
    private static function need(bool $ok,string $message):void {if(!$ok)throw new DomainException($message);}
    public function build(array $report,array $profile,array $input):array
    {
        self::need($this->db->inTransaction(),'Quellenaufnahme benötigt eine eigene Schreibtransaktion.');
        $keys=['account','original_taxpayer','sale_year','held_since','sold_on','acquired_on','transferred_on','transferred_reserve',...self::AMOUNTS,...self::FLAGS,...FinancialReserveCalculation::FLAGS];
        foreach(self::DOCS as $key)$keys[]=$key.'_document_id';
        $actual=array_keys($input);sort($actual);sort($keys);
        self::need($actual===$keys,'Originalbildung und Rücklagenübernahme vollständig eingeben.');
        foreach($input as $value)self::need(is_string($value),'Quellenangaben müssen einzelne Werte sein.');
        $receiver=$profile['company']['legal_status']??'';
        self::need(($profile['kind']??'')==='whole_taxable_club'&&in_array($receiver,['GMBH','gGMBH'],true),'Nur einen belegten Buchwertübergang auf eine GmbH aufnehmen.');
        $account=$report['accounts'][$input['account']]??null;
        self::need(is_array($account)&&in_array($account['skr_code'],['72500','72550'],true)&&$account['group']==='expense','Originales Finanzanlagenkonto 72500 oder 72550 auswählen.');
        $taxpayer=trim($input['original_taxpayer']);
        self::need($taxpayer!==''&&mb_strlen($taxpayer)<=200&&!preg_match('/[\x00-\x1f]/',$taxpayer),'Ursprünglichen Rücklagenbildner eindeutig bezeichnen.');
        self::need(preg_match('/^[0-9]{4}$/D',$input['sale_year'])===1,'Originales Gewinnjahr prüfen.');
        $original=['schema'=>1,'year'=>$report['year'],'taxpayer_kind'=>'natural_person_sole_business','sale_year'=>(int)$input['sale_year'],'held_since'=>$input['held_since'],'sold_on'=>$input['sold_on'],'acquired_on'=>$input['acquired_on'],'replacement_kind'=>'corporation_shares'];
        foreach(self::AMOUNTS as $key)$original[$key.'_cents']=Finance::cents($input[$key]);
        foreach(FinancialReserveCalculation::FLAGS as $key)$original[$key]=$input[$key]==='1';
        foreach(self::FLAGS as $key)self::need($input[$key]==='1','Originalbildung, Buchwerteinbringung und unveränderte Frist einzeln bestätigen.');
        $proof=FinancialReserveCalculation::derive($original);
        $on=$input['transferred_on'];
        self::need(preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$on)===1,'Steuerlichen Übertragungsstichtag prüfen.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$on);
        self::need($date!==false&&$date->format('Y-m-d')===$on&&$original['sold_on']<=$on&&$on<=$original['acquired_on'],'Originalbildung, Einbringung und Ersatzkauf zeitlich abstimmen.');
        $transferred=Finance::cents($input['transferred_reserve']);
        self::need($transferred===$original['available_cents'],'Übernommenen Rücklagenbetrag mit dem ursprünglichen Restbetrag abstimmen.');
        $documents=[];$evidence=new AssetValuationEvidence($this->db,$this->actor);
        foreach(self::DOCS as $key)$documents[$key]=$evidence->reference($input[$key.'_document_id'],true);
        self::need(count(array_unique(array_column($documents,'document_id')))===4,'Originalbildung, Einbringung, Fortführung und Wahl getrennt belegen.');
        return ['schema'=>1,'status'=>'pending_tax_review','year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'account'=>$input['account'],'skr_code'=>$account['skr_code'],'original_taxpayer'=>$taxpayer,'original'=>$original,'receiver_legal_status'=>$receiver,'transfer_kind'=>'whole_business_contribution_UmwStG20','transfer_value'=>'book_value','transferred_on'=>$on,'transferred_reserve_cents'=>$transferred,'confirmations'=>array_fill_keys(self::FLAGS,true),'documents'=>$documents,'calculation'=>$proof+['original_share_reinvestment_deadline'=>($original['sale_year']+2).'-12-31'],'recorded_by'=>$this->actor,'recorded_at'=>gmdate('c'),'activation_ready'=>false,'native_xml_qualified'=>false];
    }
    public static function assertExportable(array $preparation):void
    {
        // Any retained active intake blocks export, even if its status was corrupted.
        if(array_key_exists('financial_reserve_intake',$preparation))throw new DomainException('Übernommene Finanzanlagenrücklage ist als Quellenentwurf erfasst. Steuerliche Behandlung, Anlagenwerte und XML-Zuordnung sind noch nicht freigegeben.');
    }
}
