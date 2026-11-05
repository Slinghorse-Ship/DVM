<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;
/** Accepted tax-assistant commands preserve their complete first input and result. */
final class TaxAutoRequests
{
    public const FORMAT='dvm-tax-auto-request/1';
    public const CONTEXT='dvm-tax-auto-context/1';
    public function __construct(private PDO $db,private string $actorId){}
    public static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private function q(string $sql,array $values=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$values);}
    private function actor(bool $write=false):array
    {
        try{$actor=Permissions::actor($this->db,$this->actorId);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),403,$failure);}
        if(!in_array($actor['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Aktuelle Finanzberechtigung für Steuerentwürfe erforderlich.',403);return$actor;
    }
    private function available():void{if(!Database::tableExists($this->db,TaxAutoRequestSchema::TABLE))throw new DomainException('Unveränderliche Steuerbestätigungen benötigen den aktuellen Datenbankstand.',503);}
    private function busy(\Throwable $failure):never
    {
        if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Steuerentwurf wird gerade bearbeitet. Zuerst den Ausgang mit derselben Kennung lesen.',503,$failure);throw$failure;
    }
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return$result;}catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);}
    }
    private static function year(int $year,int $from):void{if($from<2000||$year>(int)date('Y')||$from>$year||$year-$from>2)throw new DomainException('Ein bis drei gültige Kalenderjahre für den Steuerentwurf wählen.',422);}
    private function report(string $club,int $from,int $year,string $lock):?array
    {
        $row=$this->q("SELECT id,version FROM activity_report_reviews WHERE club_id=? AND report_kind='tax' AND from_year=? AND to_year=? ".$lock,[$club,$from,$year])->fetch();return$row?['id'=>$row['id'],'version'=>(int)$row['version']]:null;
    }
    private static function reportOriginal(?array $row):?array
    {
        if($row===null)return null;$pdf=$row['approved_pdf'];unset($row['approved_pdf']);$row['approved_pdf']=$pdf===null?null:['sha256'=>hash('sha256',$pdf),'bytes'=>strlen($pdf)];return$row;
    }
    private function snapshot(int $year,int $from):array
    {
        return['case'=>(new TaxAutoPreparation($this->db,$this->actorId))->get($year),'profile'=>(new TaxFilings($this->db,$this->actorId))->profile($year),'report'=>self::reportOriginal((new ActivityReportReview($this->db,$this->actorId))->forPeriod($from,$year))];
    }
    public function workspace(int $year,?int $from=null):array
    {
        return$this->read(function()use($year,$from):array{
            $actor=$this->actor();$this->available();$caseRow=$this->q('SELECT version FROM tax_auto_cases WHERE club_id=? AND tax_year=? FOR SHARE NOWAIT',[$actor['club_id'],$year])->fetchColumn();$profileRow=$this->q('SELECT version FROM tax_filing_profiles WHERE club_id=? AND tax_year=? FOR SHARE NOWAIT',[$actor['club_id'],$year])->fetchColumn();
            $case=(new TaxAutoPreparation($this->db,$this->actorId))->get($year);$from??=(int)$case['input']['from_year'];self::year($year,$from);$reportRow=$this->q("SELECT * FROM activity_report_reviews WHERE club_id=? AND report_kind='tax' AND from_year=? AND to_year=? FOR SHARE NOWAIT",[$actor['club_id'],$from,$year])->fetch();$report=$reportRow?['id'=>$reportRow['id'],'version'=>(int)$reportRow['version']]:null;$profile=(new TaxFilings($this->db,$this->actorId))->profile($year);
            $context=['format'=>self::CONTEXT,'club_id'=>$actor['club_id'],'actor_id'=>$actor['id'],'year'=>$year,'from_year'=>$from,'case_version'=>$caseRow===false?0:(int)$caseRow,'profile_version'=>$profileRow===false?0:(int)$profileRow,'report'=>$report];
            return['context'=>$context,'case'=>$case,'profile'=>$profile,'report'=>self::reportOriginal($reportRow?:null)];
        });
    }
    private static function context(array $context):array
    {
        if(array_keys($context)!==['format','club_id','actor_id','year','from_year','case_version','profile_version','report']||$context['format']!==self::CONTEXT||!is_string($context['club_id'])||$context['club_id']===''||!is_string($context['actor_id'])||$context['actor_id']===''||!is_int($context['year'])||!is_int($context['from_year']))throw new DomainException('Ursprünglichen Steuerentwurfsstand vollständig prüfen.',422);
        if($context['from_year']<2000||$context['year']>9999||$context['from_year']>$context['year']||$context['year']-$context['from_year']>2)throw new DomainException('Ursprünglichen Prüfungszeitraum vollständig prüfen.',422);foreach(['case_version','profile_version']as$field)if(!is_int($context[$field])||$context[$field]<0||$context[$field]>2147483647)throw new DomainException('Ursprüngliche Steuerfassungen vollständig prüfen.',422);
        $report=$context['report'];if($report!==null&&(!is_array($report)||array_keys($report)!==['id','version']||!is_string($report['id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$report['id'])||!is_int($report['version'])||$report['version']<1))throw new DomainException('Ursprünglichen Berichtsentwurf vollständig prüfen.',422);return$context;
    }
    private static function capture(array $input):array
    {
        $keys=($input['operation']??null)==='filing'?['operation','context','values','answers','source_hash','environment','synthetic']:['operation','context','values','answers'];
        if(array_keys($input)!==$keys||!in_array($input['operation'],['draft','filing'],true)||!is_array($input['context'])||!is_array($input['values'])||!is_array($input['answers']))throw new DomainException('Steuerentwurf und Jahresangaben vollständig prüfen.',422);
        if($input['operation']==='filing'&&(!is_string($input['source_hash'])||!preg_match('/\A[a-f0-9]{64}\z/D',$input['source_hash'])||!in_array($input['environment'],['test','production'],true)||!is_bool($input['synthetic'])))throw new DomainException('Ursprünglichen Steuerentwurf vollständig prüfen.',422);
        self::context($input['context']);
        if(strlen(self::json($input))>200000)throw new DomainException('Steuerentwurf ist zu umfangreich.',422);return$input;
    }
    private function lockContext(array $context):void
    {
        $case=$this->q('SELECT version FROM tax_auto_cases WHERE club_id=? AND tax_year=? FOR UPDATE NOWAIT',[$context['club_id'],$context['year']])->fetchColumn();$profile=$this->q('SELECT version FROM tax_filing_profiles WHERE club_id=? AND tax_year=? FOR UPDATE NOWAIT',[$context['club_id'],$context['year']])->fetchColumn();$report=$this->report($context['club_id'],$context['from_year'],$context['year'],'FOR UPDATE NOWAIT');
        if(($case===false?0:(int)$case)!==$context['case_version']||($profile===false?0:(int)$profile)!==$context['profile_version']||$report!==$context['report'])throw new DomainException('Steuerentwurf, Steuerprofil oder Bericht wurde inzwischen geändert. Ganze Angaben mit dem aktuellen Stand vergleichen.',409);
    }
    public function save(array $input,string $key):array
    {
        $actor=$this->actor(true);$this->available();$input=self::capture($input);if($input['operation']!=='draft')throw new DomainException('Ursprünglichen Steuerentwurf vollständig prüfen.',422);$context=$input['context'];if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Steuerauftragskennung vollständig prüfen.',422);
        if($context['club_id']!==$actor['club_id']||$context['actor_id']!==$actor['id'])throw new DomainException('Ursprünglicher Steuerentwurf gehört zu einem anderen aktuellen Zugang.',403);
        $json=self::json($input);$hash=hash('sha256',$json);$first=$this->q('SELECT * FROM tax_auto_requests WHERE club_id=? AND request_key=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$actor['club_id'],$key])->fetch();
        if($first)return$this->repeat($first,$actor,$hash);if($this->db->inTransaction())throw new DomainException('Neuen Steuerentwurf außerhalb des bereits laufenden Vorgangs speichern.',409);
        try{return Transactions::run($this->db,function()use($actor,$input,$context,$key,$json,$hash):array{
            $current=$this->actor(true);if($current['club_id']!==$actor['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$first=$this->q('SELECT * FROM tax_auto_requests WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT',[$current['club_id'],$key])->fetch();if($first)return$this->repeat($first,$current,$hash);
            self::year($context['year'],$context['from_year']);$normalized=['case_input'=>TaxAutoPreparation::normalizeDraft($context['year'],$input['values']),'profile_answers'=>TaxFilingScope::normalize($input['answers'])];if((int)$normalized['case_input']['from_year']!==$context['from_year'])throw new DomainException('Geänderten Prüfungszeitraum zuerst ausdrücklich übernehmen.',409);
            $this->lockContext($context);$before=$this->snapshot($context['year'],$context['from_year']);(new TaxAutoPreparation($this->db,$this->actorId))->save($context['year'],$context['case_version'],$input['values'],$input['answers'],$context['profile_version']);$result=$this->snapshot($context['year'],$context['from_year']);if($result['case']['input']!==$normalized['case_input']||$result['profile']['scope']['answers']!==$normalized['profile_answers'])throw new DomainException('Tatsächlich gespeicherten Steuerentwurf gemeinsam prüfen.',503);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=self::json(['format'=>self::FORMAT,'request_key'=>$key,'club_id'=>$current['club_id'],'actor_id'=>$current['id'],'operation'=>'draft','year'=>$context['year'],'input'=>$input,'normalized'=>$normalized,'before'=>$before,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            $this->q('INSERT INTO tax_auto_requests(club_id,request_key,actor_id,operation,tax_year,case_version,profile_version,input_sha256,input_json,original_sha256,original_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$current['club_id'],$key,$current['id'],'draft',$context['year'],(int)$result['case']['version'],(int)$result['profile']['version'],$hash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$current['club_id'],$current['id'],'tax.auto.draft.saved',$key,(int)$result['case']['version'],$now->format('Y-m-d\TH:i:s.u\Z')]);return$this->decode($this->q('SELECT * FROM tax_auto_requests WHERE club_id=? AND request_key=?',[$current['club_id'],$key])->fetch());
        });}catch(\Throwable$failure){$this->busy($failure);}
    }
    private function repeat(array $row,array $actor,string $hash):array
    {
        if($row['actor_id']!==$actor['id']||!in_array($row['operation'],['draft','filing'],true)||!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese Steuerkennung gehört zu anderen ursprünglichen Eingaben. Gespeicherten Ausgang lesen.',409);return$this->decode($row);
    }
    private function decode(array $row):array
    {
        if($row['operation']==='filing')return$this->decodeFiling($row);
        try{$input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,128,JSON_THROW_ON_ERROR);$input=self::capture($input);$context=$input['context'];$normalized=$original['normalized']??null;if(!is_array($normalized)||array_keys($normalized)!==['case_input','profile_answers']||!is_array($normalized['case_input'])||!is_array($normalized['profile_answers']))throw new DomainException('Ursprüngliche normalisierte Steuerangaben prüfen lassen.',503);$before=$original['before']??[];$result=$original['result']??[];
            if(!hash_equals($row['input_sha256'],hash('sha256',$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!==self::FORMAT||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['operation']??null)!=='draft'||$row['operation']!=='draft'||($original['year']??null)!==(int)$row['tax_year']||($original['input']??null)!==$input||$context['club_id']!==$row['club_id']||$context['actor_id']!==$row['actor_id']||$context['year']!==(int)$row['tax_year']||($before['case']['version']??null)!==$context['case_version']||($before['profile']['version']??null)!==$context['profile_version']||($result['case']['club_id']??null)!==$row['club_id']||($result['case']['tax_year']??null)!==$context['year']||($result['case']['version']??null)!==$context['case_version']+1||($result['profile']['version']??null)!==$context['profile_version']+1||($result['case']['version']??null)!==(int)$row['case_version']||($result['profile']['version']??null)!==(int)$row['profile_version']||($result['case']['input']??null)!==$normalized['case_input']||($result['profile']['scope']['answers']??null)!==$normalized['profile_answers']||($result['case']['updated_by']??null)!==$row['actor_id']||($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Unveränderte ursprüngliche Steuerbestätigung prüfen lassen.',503);
            $report=$before['report']??null;$reference=$report===null?null:['id'=>$report['id'],'version'=>(int)$report['version']];if($reference!==$context['report'])throw new DomainException('Ursprünglichen Berichtsnachweis der Steuerbestätigung prüfen lassen.',503);
            return['key'=>$row['request_key'],'actor_id'=>$row['actor_id'],'operation'=>'draft','year'=>$context['year'],'input'=>$input,'normalized'=>$normalized,'before'=>$before,'result'=>$result,'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
        }catch(DomainException$failure){if($failure->getCode()===503)throw$failure;throw new DomainException('Unveränderte ursprüngliche Steuerbestätigung prüfen lassen.',503,$failure);}catch(\Throwable$failure){throw new DomainException('Unveränderte ursprüngliche Steuerbestätigung prüfen lassen.',503,$failure);}
    }
    /** Compare complete form values with stored defaults without inventing a change. */
    public static function sameDraft(int $year,array $left,array $right):bool
    {
        $normal=static function(array$values)use($year):array{$out=TaxAutoPreparation::normalizeDraft($year,$values);$out['tax_year_notes']=TaxYearNotes::normalize($out['tax_year_notes'],(int)$out['from_year'],$year);sort($out['tax_documents'],SORT_STRING);return$out;};return$normal($left)===$normal($right);
    }
    /** Prepare a local, unsubmitted return with the same immutable first-result contract. */
    public function prepareFiling(array $input,string $key):array
    {
        $actor=$this->actor(true);$this->available();$input=self::capture($input);if($input['operation']!=='filing'||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprünglichen Steuerentwurf vollständig prüfen.',422);$c=$input['context'];if($c['club_id']!==$actor['club_id']||$c['actor_id']!==$actor['id'])throw new DomainException('Ursprünglicher Steuerentwurf gehört zu einem anderen aktuellen Zugang.',403);
        $json=self::json($input);$hash=hash('sha256',$json);$first=$this->q('SELECT * FROM tax_auto_requests WHERE club_id=? AND request_key=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$actor['club_id'],$key])->fetch();if($first)return$this->repeat($first,$actor,$hash);if($this->db->inTransaction())throw new DomainException('Neuen Steuerentwurf außerhalb des bereits laufenden Vorgangs speichern.',409);
        try{return Transactions::run($this->db,function()use($actor,$input,$c,$key,$json,$hash):array{
            $current=$this->actor(true);if($current['club_id']!==$actor['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$first=$this->q('SELECT * FROM tax_auto_requests WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT',[$current['club_id'],$key])->fetch();if($first)return$this->repeat($first,$current,$hash);$this->lockContext($c);self::year($c['year'],$c['from_year']);
            $normalized=['case_input'=>TaxAutoPreparation::normalizeDraft($c['year'],$input['values']),'profile_answers'=>TaxFilingScope::normalize($input['answers'])];$before=$this->snapshot($c['year'],$c['from_year']);if(!self::sameDraft($c['year'],$normalized['case_input'],$before['case']['input'])||$normalized['profile_answers']!==$before['profile']['scope']['answers'])throw new DomainException('Geänderte Angaben zuerst als Zwischenstand speichern und das Ergebnis prüfen.',409);
            if($before['case']['filing_id']??null){$existing=(new TaxFilings($this->db,$this->actorId))->get($before['case']['filing_id']);if(!in_array($existing['state'],['draft','validated','failed'],true))throw new DomainException('Vorhandenen Abgabeauftrag zuerst klären.',409);}
            $normalized['case_input']=$before['case']['input'];
            $id=(new TaxAutoPreparation($this->db,$this->actorId))->createFiling($c['year'],$c['case_version'],$input['source_hash'],$input['environment'],$input['synthetic']);$result=$this->snapshot($c['year'],$c['from_year']);$result['filing']=(new TaxFilings($this->db,$this->actorId))->get($id);foreach(['pdf']as$field)if(isset($result['filing'][$field])){$bytes=$result['filing'][$field];$result['filing'][$field]=['sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes)];}
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=self::json(['format'=>self::FORMAT,'request_key'=>$key,'club_id'=>$current['club_id'],'actor_id'=>$current['id'],'operation'=>'filing','year'=>$c['year'],'input'=>$input,'normalized'=>$normalized,'before'=>$before,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            $this->q('INSERT INTO tax_auto_requests(club_id,request_key,actor_id,operation,tax_year,case_version,profile_version,input_sha256,input_json,original_sha256,original_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$current['club_id'],$key,$current['id'],'filing',$c['year'],$c['case_version'],$c['profile_version'],$hash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$current['club_id'],$current['id'],'tax.auto.filing.prepared',$key,$c['case_version'],$now->format('Y-m-d\TH:i:s.u\Z')]);return$this->decode($this->q('SELECT * FROM tax_auto_requests WHERE club_id=? AND request_key=?',[$current['club_id'],$key])->fetch());
        });}catch(\Throwable$e){$this->busy($e);}
    }
    private function decodeFiling(array $row):array
    {
        try{
            $input=self::capture(json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR));$o=json_decode($row['original_json'],true,128,JSON_THROW_ON_ERROR);$c=$input['context'];$before=$o['before']??[];$result=$o['result']??[];$normal=$o['normalized']??[];$filing=$result['filing']??[];$binding=$filing['form_data']['auto_preparation']??[];$beforeReport=$before['report']??null;$reportRef=$beforeReport===null?null:['id'=>$beforeReport['id'],'version'=>(int)$beforeReport['version']];
            $expectedCase=$before['case']??[];$expectedCase['filing_id']=$filing['id']??null;
            if(!hash_equals($row['input_sha256'],hash('sha256',$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($o['format']??null)!==self::FORMAT||($o['request_key']??null)!==$row['request_key']||($o['club_id']??null)!==$row['club_id']||($o['actor_id']??null)!==$row['actor_id']||($o['operation']??null)!=='filing'||$input['operation']!=='filing'||($o['year']??null)!==(int)$row['tax_year']||($o['input']??null)!==$input||$c['club_id']!==$row['club_id']||$c['actor_id']!==$row['actor_id']||$c['year']!==(int)$row['tax_year']||($before['case']['version']??null)!==$c['case_version']||($before['profile']['version']??null)!==$c['profile_version']||($result['case']??null)!==$expectedCase||($result['profile']??null)!==($before['profile']??null)||($result['report']??null)!==$beforeReport||$reportRef!==$c['report']||$c['case_version']!==(int)$row['case_version']||$c['profile_version']!==(int)$row['profile_version']||($normal['case_input']??null)!==($before['case']['input']??null)||($normal['profile_answers']??null)!==($before['profile']['scope']['answers']??null)||($filing['club_id']??null)!==$row['club_id']||($filing['tax_year']??null)!==$c['year']||($filing['environment']??null)!==$input['environment']||($binding['case_version']??null)!==$c['case_version']||($binding['source_hash']??null)!==$input['source_hash']||($o['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Unveränderte ursprüngliche Steuerbestätigung prüfen lassen.',503);
            $data=$filing['form_data'];unset($data['auto_preparation']);if(!hash_equals($binding['payload_hash']??'',hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))))throw new DomainException('Unveränderte ursprüngliche Steuerbestätigung prüfen lassen.',503);
            return['key'=>$row['request_key'],'actor_id'=>$row['actor_id'],'operation'=>'filing','year'=>$c['year'],'input'=>$input,'normalized'=>$normal,'before'=>$before,'result'=>$result,'created_at'=>$o['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
        }catch(\Throwable$e){throw new DomainException('Unveränderte ursprüngliche Steuerbestätigung prüfen lassen.',503,$e);}
    }
    /** Six immutable outcomes per page; never reconstruct old outcomes from current cases. */
    public function history(int $year,int $page=1):array
    {
        if($year<2000||$year>9999||$page<1||$page>1000000)throw new DomainException('Jahr und Bestätigungsseite vollständig prüfen.',422);
        return$this->read(function()use($year,$page):array{
            $a=$this->actor();$this->available();$count=(int)$this->q('SELECT COUNT(*) FROM tax_auto_requests WHERE club_id=? AND tax_year=?',[$a['club_id'],$year])->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min($page,$pages);
            $rows=$this->q('SELECT request_key,actor_id,operation,tax_year,case_version,profile_version,created_at FROM tax_auto_requests WHERE club_id=? AND tax_year=? ORDER BY created_at DESC,request_key DESC LIMIT 6 OFFSET '.(($page-1)*6),[$a['club_id'],$year])->fetchAll();
            return compact('rows','count','page','pages');
        });
    }
    public function receipt(string $key):?array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Steuerauftragskennung vollständig prüfen.',422);
        return$this->read(function()use($key):array{$actor=$this->actor();$this->available();$row=$this->q('SELECT * FROM tax_auto_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$key])->fetch();return['original'=>$row?$this->decode($row):null];})['original'];
    }
}
