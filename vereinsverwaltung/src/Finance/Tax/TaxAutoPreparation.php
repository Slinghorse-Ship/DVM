<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/TaxPreparation.php';
require_once __DIR__.'/TaxFilings.php';
require_once __DIR__.'/TaxAutoMapping2025.php';

/** Versioned preparation. Reads accounting; writes only explicit drafts. No transport. */
final class TaxAutoPreparation
{
    public function __construct(private PDO $db,private string $actor){}
    private function access(bool $write=false):array
    {
        try{$a=Permissions::actor($this->db,$this->actor);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),403,$failure);}
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung für die Steuer-Vorbereitung erforderlich.',403);
        return $a;
    }
    private function query(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private static function json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function hash(array $v):string{return hash('sha256',self::json($v));}
    public static function testMode():bool{return getenv('VEREIN_MODE')!=='production'&&getenv('VEREIN_TAX_AUTO_TEST')==='synthetic-only';}
    public function get(int $year):array
    {
        $a=$this->access();TaxFilingScope::build($year,[]);
        $r=$this->query('SELECT * FROM tax_auto_cases WHERE club_id=? AND tax_year=?',[$a['club_id'],$year])->fetch();
        if($r){$r['input']=json_decode($r['input_data'],true,64,JSON_THROW_ON_ERROR);unset($r['input_data']);return $r;}
        $stored=$this->query("SELECT payload FROM club_settings WHERE club_id=? AND section='club'",[$a['club_id']])->fetchColumn();
        $tax=(new Settings($this->db,$this->actor))->section('tax')['data'];
        $input=['from_year'=>(string)$year,'to_year'=>(string)$year,'activity_report'=>'','tax_documents'=>[],'tax_year_notes'=>[],'tax_guide'=>[]];
        // Reuse the latest matching archived answers, never edit the archived package.
        $preparation=new TaxPreparation($this->db,$this->actor);
        foreach($preparation->recent() as $doc){try{$old=$preparation->editValues($doc['id']);if((int)$old['to_year']===$year){$input=$old;break;}}catch(DomainException){continue;}}
        $input['auto_answers']=TaxAutoMapping2025::suggest($stored?json_decode($stored,true,32,JSON_THROW_ON_ERROR):[],$tax);
        return ['club_id'=>$a['club_id'],'tax_year'=>$year,'version'=>0,'input'=>$input,'filing_id'=>null];
    }
    private static function normalize(int $year,array $input):array
    {
        $allowed=['from_year','to_year','activity_report','tax_documents','tax_year_notes','tax_guide','auto_answers'];
        if(array_diff_key($input,array_flip($allowed)))throw new DomainException('Unbekannte Eingabe im Steuerassistenten.');
        $from=$input['from_year']??(string)$year;
        if(!is_string($from)||!preg_match('/^[0-9]{4}$/D',$from)||(int)($input['to_year']??$year)!==$year)throw new DomainException('Zeitraum prüfen.');
        foreach(['tax_documents','tax_year_notes','tax_guide','auto_answers'] as $key)if(isset($input[$key])&&!is_array($input[$key]))throw new DomainException('Angaben prüfen.');
        TaxYearNotes::normalize($input['tax_year_notes']??[],(int)$from,$year);
        $activity=$input['activity_report']??'';
        if(!is_string($activity)||strlen($activity)>40000||!mb_check_encoding($activity,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$activity))throw new DomainException('Tätigkeitsbericht prüfen.');
        $docs=$input['tax_documents']??[];
        if(count($docs)>10||count(array_unique($docs,SORT_REGULAR))!==count($docs))throw new DomainException('Höchstens zehn unterschiedliche Nachweise.');
        foreach($docs as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Nachweis prüfen.');
        $out=['from_year'=>$from,'to_year'=>(string)$year,'activity_report'=>$activity,'tax_documents'=>$docs,'tax_year_notes'=>$input['tax_year_notes']??[],
            'tax_guide'=>TaxDeclarationGuide::normalize($input['tax_guide']??[]),'auto_answers'=>TaxAutoMapping2025::normalize($input['auto_answers']??[])];
        if(strlen(self::json($out))>180000)throw new DomainException('Vorbereitung zu umfangreich.');return $out;
    }
    public static function normalizeDraft(int $year,array $input):array{return self::normalize($year,$input);}
    public function save(int $year,int $version,array $input,array $profileAnswers,int $profileVersion):void
    {
        $this->access(true);$input=self::normalize($year,$input);
        try{\Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($year,$version,$input,$profileAnswers,$profileVersion):void{
            $a=$this->access(true);$old=$this->query('SELECT * FROM tax_auto_cases WHERE club_id=? AND tax_year=? FOR UPDATE NOWAIT',[$a['club_id'],$year])->fetch();
            if((int)($old['version']??0)!==$version)throw new DomainException('Vorbereitung wurde inzwischen geändert. Bitte neu laden.',409);
            if($old['filing_id']??null){$state=$this->query('SELECT state FROM tax_filings WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$old['filing_id']])->fetchColumn();if($state===false)throw new DomainException('Ursprünglichen Abgabeauftrag prüfen.',409);if(in_array($state,['sending','unknown','sent_test','sent_production'],true))throw new DomainException('Der vorhandene Abgabeauftrag ist unveränderlich. Eine Berichtigung gesondert vorbereiten.',409);}
            (new TaxFilings($this->db,$this->actor))->saveProfile($year,$profileAnswers,$profileVersion);
            $args=[self::json($input),$this->actor,gmdate('c'),$a['club_id'],$year];
            if($version===0)$this->query('INSERT INTO tax_auto_cases(input_data,updated_by,updated_at,club_id,tax_year) VALUES(?,?,?,?,?)',$args);
            else{array_push($args,$version);$update=$this->query('UPDATE tax_auto_cases SET input_data=?,updated_by=?,updated_at=?,version=version+1 WHERE club_id=? AND tax_year=? AND version=?',$args);if($update->rowCount()!==1)throw new DomainException('Vorbereitung wurde inzwischen geändert. Bitte neu laden.',409);}
            (new ActivityReportReview($this->db,$this->actor))->prepare((int)$input['from_year'],$year,$input['activity_report']);
        });}catch(\PDOException$failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Steuerentwurf wird gerade bearbeitet. Bekannte Angaben bleiben erhalten.',503,$failure);throw$failure;}
    }
    public function preview(int $year):array
    {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{
            $case=$this->get($year);$in=$case['input'];$club=$case['club_id'];
            $preparation=new TaxPreparation($this->db,$this->actor);
            // Old snapshots may reference archived, reassigned or unavailable evidence.
            // Keep those selections unchanged and block completion, but let users open the form.
            $available=array_column($preparation->attachments(),'id');
            $missing=array_values(array_diff($in['tax_documents'],$available));
            $selected=array_values(array_intersect($in['tax_documents'],$available));
            $bundle=$preparation->build((int)$in['from_year'],$year,$in['activity_report'],$selected,$in['tax_year_notes'],$in['tax_guide']);
            $scope=(new TaxFilings($this->db,$this->actor))->profile($year)['scope'];$issues=[];
            $extra=['unavailable_documents'=>$missing];
            if($missing)$issues[]='Ausgewählte Nachweise sind nicht mehr verfügbar oder nicht mehr für den Finanzbereich freigegeben. Unter „Tätigkeitsbericht ergänzen und Nachweise wählen“ die Auswahl prüfen. Alte Unterlagen bleiben unverändert.';
            $extra['settings']=$this->query("SELECT section,version,payload FROM club_settings WHERE club_id=? AND section IN ('club','tax') ORDER BY section",[$club])->fetchAll();
            if(count($extra['settings'])!==2)$issues[]='Vereinsdaten und Steuereinstellungen ergänzen und speichern.';
            $extra['claims']=$this->query("SELECT c.id,c.entry_id,c.state,c.version,c.requested_on,c.amount_cents,c.paid_transaction_id,e.document_date,e.description FROM finance_reimbursement_claims c JOIN finance_entries e ON e.club_id=c.club_id AND e.id=c.entry_id LEFT JOIN finance_transactions t ON t.club_id=c.club_id AND t.id=c.paid_transaction_id WHERE c.club_id=? AND c.state<>'cancelled' AND e.document_date<=? AND (e.document_date>=? OR c.state IN ('open','approved') OR t.booked_on>=? OR c.waived_on>=?) ORDER BY c.id",[$club,$year.'-12-31',$in['from_year'].'-01-01',$in['from_year'].'-01-01',$in['from_year'].'-01-01'])->fetchAll();
            if($extra['claims'])$issues[]='Private Vorleistungen vorhanden: ursprüngliches Zahlungsdatum und steuerliche Jahreszuordnung sind nicht vollständig erfasst. Diese Fälle gesondert abstimmen; Erstattungsdatum nicht als ursprüngliches Zahlungsdatum übernehmen.';
            $extra['goods']=$this->query('SELECT id,version,received_on,value_cents FROM goods_donations WHERE club_id=? AND received_on BETWEEN ? AND ? ORDER BY id',[$club,$in['from_year'].'-01-01',$year.'-12-31'])->fetchAll();
            if($extra['goods'])$issues[]='Sachspenden vorhanden: Gesamteinnahmen mit den nicht zahlungswirksamen Zugängen abstimmen. Nicht nochmals als Bankeingang buchen.';
            $extra['assets']=$this->query('SELECT item_id,year,plan_version,opening_cents,depreciation_cents,closing_cents FROM asset_book_years WHERE club_id=? AND year BETWEEN ? AND ? ORDER BY year,item_id',[$club,(int)$in['from_year'],$year])->fetchAll();
            foreach($bundle['snapshot']['reports'] as $y=>$r)foreach($r['journal']['rows'] as $row){
                // Balance movements are not automatically revenue/expense. Account 7 also needs a specific rule.
                $code=(string)$row['account_code'];$direction=$row['direction'];
                if(!preg_match($direction==='income'?'/^4[0-9]{4}$/D':'/^[56][0-9]{4}$/D',$code))$issues[]=$y.': Nicht eindeutig als '.($direction==='income'?'Einnahme':'Ausgabe').' zuordenbares Konto '.$code.'. Darlehen, Anschaffungen, Eröffnungen und ähnliche Sonderfälle gesondert prüfen.';
            }
            $mapped=TaxAutoMapping2025::build($bundle['snapshot'],$in['auto_answers'],$scope,$issues);
            try{$schema=TaxFormSchema::load(TaxAutoMapping2025::VERSION);if(($schema->profile()['schema_sha256']??'')!==TaxAutoMapping2025::SCHEMA)throw new DomainException('Dieses Formular ist hier nicht verfügbar. Bitte Mein ELSTER verwenden.');$schema->normalize($mapped['fields'],$mapped['counts']);}
            catch(DomainException $e){$mapped['issues'][]=$e->getMessage();}
            $mapped['issues']=array_values(array_unique($mapped['issues']));$mapped['ready']=!$mapped['issues'];
            $hash=self::hash(['bundle'=>$bundle['hash'],'extra'=>$extra,'scope'=>$scope,'version'=>(int)$case['version'],'input'=>$in,'mapping'=>$mapped]);
            if($own)$this->db->commit();
            return ['case'=>$case,'bundle'=>$bundle,'mapping'=>$mapped,'scope'=>$scope,'extra'=>$extra,'hash'=>$hash];
        }catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function createFiling(int $year,int $version,string $expectedHash,string $environment,bool $synthetic=false):string
    {
        $this->access(true);
        if(!in_array($environment,['test','production'],true))throw new DomainException('Abgabeumgebung prüfen.');
        if($environment==='test'&&(!self::testMode()||!$synthetic))throw new DomainException('Automatische Testentwürfe nur in ausdrücklich isolierter Umgebung mit erfundenen Daten.');
        try{return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($year,$version,$expectedHash,$environment):string{
            $current=$this->access(true);$this->query('SELECT version FROM tax_auto_cases WHERE club_id=? AND tax_year=? FOR UPDATE NOWAIT',[$current['club_id'],$year])->fetchColumn();$p=$this->preview($year);$case=$p['case'];$m=$p['mapping'];
            if((int)$case['version']!==$version||$version<1||!hash_equals($p['hash'],$expectedHash))throw new DomainException('Daten geändert. Jahreszahlen und Vorschau erneut prüfen.');
            if(!$m['ready'])throw new DomainException('Zuerst die offenen Punkte klären.');
            $filings=new TaxFilings($this->db,$this->actor);
            if($case['filing_id']){
                $old=$filings->get($case['filing_id']);$binding=$old['form_data']['auto_preparation']??[];
                if(($binding['source_hash']??'')===$expectedHash&&$old['environment']===$environment){return $old['id'];}
                if(!in_array($old['state'],['draft','validated','failed'],true))throw new DomainException('Vorhandenen Abgabeauftrag zuerst klären.');
            }
            $id=$filings->create($m['data_version'],$environment,$year);
            $supportsAttachments=isset(TaxFormSchema::load($m['data_version'])->profile()['attachment_version']);
            $filings->save($id,1,$m['fields'],$m['header'],null,$supportsAttachments?$case['input']['tax_documents']:null);
            $data=$filings->get($id)['form_data'];
            $data['auto_preparation']=['version'=>1,'year'=>$year,'case_version'=>$version,'source_hash'=>$expectedHash,'payload_hash'=>self::hash($data),'origins'=>$m['origins'],'rounding'=>$m['rounding'],'evidence_delivery'=>$supportsAttachments?'selected_pdfs_only':'separate_procedure','source_snapshot'=>$p['bundle']['snapshot']];
            $this->query('UPDATE tax_filings SET form_data=? WHERE club_id=? AND id=?',[self::json($data),$case['club_id'],$id]);
            $q=$this->query('UPDATE tax_auto_cases SET filing_id=? WHERE club_id=? AND tax_year=? AND version=?',[$id,$case['club_id'],$year,$version]);if($q->rowCount()!==1)throw new DomainException('Vorbereitung wurde inzwischen geändert. Bitte neu laden.',409);
            return $id;
        });}catch(\PDOException$e){if(in_array((int)($e->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Steuerentwurf wird gerade bearbeitet. Bekannte Angaben bleiben erhalten.',503,$e);throw$e;}
    }
    public function packageRequestKey(int $year,int $version,string $expectedHash):string
    {
        $a=$this->access();if($year>(int)date('Y'))throw new DomainException('Ursprüngliches Jahr, Fassung und Prüfung vollständig auswählen.',422);
        return TaxAutoPackageBinding::key($a['club_id'],$a['id'],$year,$version,$expectedHash);
    }
    public function packageRequest(string $key):?array
    {
        $this->access();$first=(new TaxPreparation($this->db,$this->actor))->savedRequest($key);if(!$first)return null;
        return['key'=>$key,'binding'=>TaxAutoPackageBinding::original($first,$key),'original'=>$first];
    }
    public function reviewPackage(int $year,int $version,string $expectedHash):array
    {
        $key=$this->packageRequestKey($year,$version,$expectedHash);$p=$this->preview($year);
        if((int)$p['case']['version']!==$version||!hash_equals($p['hash'],$expectedHash))throw new DomainException('Daten geändert. Vorschau erneut prüfen.',409);
        if($p['extra']['unavailable_documents'])throw new DomainException('Ausgewählte ursprüngliche PDF-Nachweise zuerst prüfen; kein unvollständiges Ersatzpaket speichern.',409);
        return['key'=>$key,'year'=>$year,'case_version'=>$version,'source_hash'=>$expectedHash,'input'=>$p['case']['input'],'bundle'=>$p['bundle']];
    }
    public function savePackage(int $year,int $version,string $expectedHash):string
    {
        $a=$this->access(true);$key=$this->packageRequestKey($year,$version,$expectedHash);$first=$this->packageRequest($key);
        if($first){$binding=$first['binding'];if($binding['actor_id']!==$a['id']||$binding['year']!==$year||$binding['case_version']!==$version||!hash_equals($binding['source_hash'],$expectedHash))throw new DomainException('Diese Kennung gehört zu einem anderen ursprünglichen Jahrespaket.',409);return$first['original']['document']['id'];}
        if($this->db->inTransaction())throw new DomainException('Ein neues Jahrespaket außerhalb eines bereits laufenden Vorgangs erneut prüfen.',409);
        try{return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($a,$year,$version,$expectedHash,$key):string{
            $current=$this->access(true);if($current['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
            $this->query('SELECT version FROM tax_auto_cases WHERE club_id=? AND tax_year=? FOR SHARE NOWAIT',[$current['club_id'],$year])->fetchColumn();
            $first=$this->packageRequest($key);if($first)return$first['original']['document']['id'];
            $review=$this->reviewPackage($year,$version,$expectedHash);$in=$review['input'];
            $binding=TaxAutoPackageBinding::create($current,$year,$version,$expectedHash,$review['bundle']['hash']);
            return (new TaxPreparation($this->db,$this->actor))->save((int)$in['from_year'],$year,$in['activity_report'],$in['tax_documents'],$key,$in['tax_year_notes'],$in['tax_guide'],$review['bundle']['hash'],$binding);
        });}catch(\PDOException$failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Jahresunterlagen werden gerade gespeichert. Zuerst den Ausgang mit derselben Kennung lesen.',503,$failure);throw$failure;}
    }
    /** Called before validation and again under the transaction claiming a transmission. */
    public function assertCurrent(array $filing):void
    {
        $data=$filing['form_data'];$b=$data['auto_preparation']??null;if($b===null)return;
        unset($data['auto_preparation']);
        if(!is_array($b)||($b['version']??null)!==1||($b['payload_hash']??'')!==self::hash($data))throw new DomainException('Automatische Formularübernahme wurde verändert. Neu vorbereiten.');
        $p=$this->preview((int)$b['year']);
        if($p['case']['filing_id']!==$filing['id']||(int)$p['case']['version']!==$b['case_version']||!$p['mapping']['ready']||!hash_equals($p['hash'],(string)$b['source_hash']))throw new DomainException('Buchungen oder Angaben wurden geändert. Automatische Vorbereitung erneut öffnen und Vorschau prüfen.');
        if($filing['environment']==='test'&&!self::testMode())throw new DomainException('Automatische Testentwürfe sind hier gesperrt.');
    }
}
