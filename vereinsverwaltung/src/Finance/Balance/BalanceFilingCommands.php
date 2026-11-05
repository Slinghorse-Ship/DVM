<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** First results of local E-balance work. Sending retains its separate attempt journal. */
final class BalanceFilingCommands
{
    public const FORMAT='dvm-balance-filing-command/1';
    public const CONTEXT='dvm-balance-filing-context/1';
    public function __construct(private PDO $db,private string $actorId,private ?BalanceFilingRuntime $runtime=null){}
    public static function json(mixed $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }
    public static function operation(string $action):string
    {
        if(!in_array($action,BalanceFilingActions::ACTIONS,true))throw new DomainException('Lokalen E-Bilanz-Schritt vollständig prüfen.',422);
        return $action==='tax-filing-validate'?'validate':substr($action,strlen('tax-filing-balance-'));
    }
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function actor(bool $write=false):array
    {
        try{$a=Permissions::actor($this->db,$this->actorId);}catch(DomainException $e){throw new DomainException($e->getMessage(),403,$e);}
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Aktuelle Finanzberechtigung für die E-Bilanz erforderlich.',403);
        return $a;
    }
    private function available():void
    {
        if(!Database::tableExists($this->db,'balance_filing_commands'))throw new DomainException('E-Bilanz-Bestätigungen benötigen den aktuellen Datenbankstand.',503);
    }
    private function busy(\Throwable $e):never
    {
        if($e instanceof \PDOException&&in_array((int)($e->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('E-Bilanz wird gerade bearbeitet. Zuerst den Ausgang mit derselben Kennung lesen.',503,$e);
        throw $e;
    }
    private function read(callable $operation):mixed
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return $result;}
        catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($e);}
    }
    private static function key(string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche E-Bilanz-Auftragskennung vollständig prüfen.',422);
    }
    private static function period(string $id):void
    {
        if(!preg_match('/\A([0-9]{4})([0-9]{2})([0-9]{2})-([0-9]{4})([0-9]{2})([0-9]{2})\z/D',$id,$m))throw new DomainException('Ursprünglichen Zeitraum vollständig prüfen.',422);
        $period=BalancePeriod::check(['start'=>$m[1].'-'.$m[2].'-'.$m[3],'end'=>$m[4].'-'.$m[5].'-'.$m[6]]);
        if(BalancePeriod::key($period)!==$id)throw new DomainException('Ursprünglichen Zeitraum vollständig prüfen.',422);
    }
    private function filing(?string $id):?array
    {
        if($id===null)return null;
        self::key($id);$r=(new TaxFilings($this->db,$this->actorId,$this->runtime))->get($id);
        if($r['family']!=='balance'||!is_array($r['form_data']['balance_preparation']??null))throw new DomainException('Ursprüngliche E-Bilanz nicht gefunden.',404);
        if($r['pdf']!==null)throw new DomainException('Ursprüngliche E-Bilanz-Vorschau gesondert prüfen lassen.',503);
        return $r;
    }
    /** Changes to reviews and original journal sources cannot silently replace an opened source. */
    private function creationSources(array $actor,int $year,?string $period):array
    {
        if($period===null)$report=(new BalanceLedger($this->db,$this->actorId))->report($year);
        else{$report=(new BalancePeriodLedger($this->db,$this->actorId))->report($period);if((int)$report['year']!==$year)throw new DomainException('Abschlusszeitraum und ursprüngliches Jahr stimmen nicht überein.',409);}
        $reviews=[];
        foreach(['balance_clearing_reviews','balance_tax_reconciliation_reviews']as $table){
            $reviews[$table]=$this->q('SELECT revision,payload,sha256 FROM '.$table.' WHERE club_id=? AND tax_year<=? ORDER BY tax_year,revision',[$actor['club_id'],$year])->fetchAll();
        }
        foreach(['balance_periods','balance_period_events']as $table){
            $rows=$this->q('SELECT * FROM '.$table.' WHERE club_id=?',[$actor['club_id']])->fetchAll();
            $rows=array_map(static fn(array $row):string=>self::json($row),$rows);sort($rows,SORT_STRING);$reviews[$table]=$rows;
        }
        return ['report_sha256'=>hash('sha256',self::json($report)),'reviews_sha256'=>hash('sha256',self::json($reviews))];
    }
    private static function software():string
    {
        // Local records remain usable before an optional ELSTER runtime has been configured.
        try{$runtime=TaxFilings::runtimeHash(false);}catch(DomainException){$runtime='unconfigured';}
        foreach([__FILE__,__DIR__.'/BalanceFilingActions.php',__DIR__.'/BalanceTaxFiling.php']as $path)$runtime.=hash_file('sha256',$path);
        return hash('sha256',$runtime);
    }
    private function current(array $a,int $year,?string $id,?string $period,string $lock):array
    {
        if($year<2000||$year>9999)throw new DomainException('Ursprüngliches E-Bilanz-Jahr vollständig prüfen.',422);
        $r=null;$reference=null;
        if($id!==null){
            $row=$this->q('SELECT id FROM tax_filings WHERE club_id=? AND id=? '.$lock,[$a['club_id'],$id])->fetchColumn();
            if($row===false)throw new DomainException('Ursprüngliche E-Bilanz nicht gefunden.',404);
            $r=$this->filing($id);$b=$r['form_data']['balance_preparation'];$period=$b['period_id']??null;
            if((int)$r['tax_year']!==$year)throw new DomainException('E-Bilanz und ursprüngliches Jahr stimmen nicht überein.',409);
            $reference=['id'=>$id,'version'=>(int)$r['version'],'sha256'=>hash('sha256',self::json($r))];
        }
        $settings=$this->q("SELECT section,version,payload FROM club_settings WHERE club_id=? AND section IN ('club','tax') ORDER BY section ".$lock,[$a['club_id']])->fetchAll();
        $sources=$r===null?$this->creationSources($a,$year,$period):(new BalanceTaxFiling($this->db,$this->actorId,$this->runtime))->sourceReport($r,true);
        $documents=$this->q("SELECT d.id,d.version,d.state,d.scopes,f.revision,f.sha256,f.mime FROM documents d JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=(SELECT MAX(v.revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id) WHERE d.club_id=? AND JSON_CONTAINS(d.scopes,JSON_QUOTE('finance')) ORDER BY d.id ".$lock,[$a['club_id']])->fetchAll();
        return ['format'=>self::CONTEXT,'club_id'=>$a['club_id'],'actor_id'=>$a['id'],'year'=>$year,'period_id'=>$period,'filing'=>$reference,'settings_sha256'=>hash('sha256',self::json($settings)),'source_sha256'=>hash('sha256',self::json($sources)),'software_sha256'=>self::software(),'documents_sha256'=>hash('sha256',self::json($documents))];
    }
    public function workspace(int $year,?string $id=null,?string $period=null):array
    {
        $this->actor();$this->available();
        if($this->db->inTransaction())throw new DomainException('E-Bilanz-Formular außerhalb des laufenden Vorgangs öffnen.',409);
        if($id!==null)self::key($id);if($period!==null)self::period($period);
        return $this->read(function()use($year,$id,$period):array{$a=$this->actor();return ['context'=>$this->current($a,$year,$id,$period,'FOR SHARE NOWAIT'),'filing'=>$this->filing($id)];});
    }
    private static function capture(array $input):array
    {
        if(array_keys($input)!==['action','context','values']||!is_string($input['action'])||!is_array($input['context'])||!is_array($input['values']))throw new DomainException('E-Bilanz-Auftrag vollständig prüfen.',422);
        $op=self::operation($input['action']);$c=$input['context'];
        if(!in_array(array_keys($c),[['format','club_id','actor_id','year','period_id','filing','settings_sha256','source_sha256','software_sha256'],['format','club_id','actor_id','year','period_id','filing','settings_sha256','source_sha256','software_sha256','documents_sha256']],true)||$c['format']!==self::CONTEXT||!is_string($c['club_id'])||$c['club_id']===''||!is_string($c['actor_id'])||$c['actor_id']===''||!is_int($c['year'])||$c['year']<2000||$c['year']>9999)throw new DomainException('Ursprünglichen E-Bilanz-Stand vollständig prüfen.',422);
        if(array_key_exists('documents_sha256',$c)&&(!is_string($c['documents_sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$c['documents_sha256'])))throw new DomainException('Ursprüngliche Finanzbelegfassungen vollständig prüfen.',422);
        if($c['period_id']!==null){if(!is_string($c['period_id']))throw new DomainException('Ursprünglichen Zeitraum vollständig prüfen.',422);self::period($c['period_id']);}
        foreach(['settings_sha256','source_sha256','software_sha256']as $key)if(!is_string($c[$key])||!preg_match('/\A[a-f0-9]{64}\z/D',$c[$key]))throw new DomainException('Ursprünglichen E-Bilanz-Stand vollständig prüfen.',422);
        if(($op==='create')!==($c['filing']===null))throw new DomainException('E-Bilanz-Schritt und ursprüngliche Fassung passen nicht zusammen.',422);
        if($c['filing']!==null){
            $f=$c['filing'];if(!is_array($f)||array_keys($f)!==['id','version','sha256']||!is_string($f['id'])||!is_int($f['version'])||$f['version']<1||!is_string($f['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$f['sha256']))throw new DomainException('Ursprüngliche E-Bilanz-Fassung vollständig prüfen.',422);self::key($f['id']);
        }
        foreach(['csrf','action','filing','version','tax_year','period_id','certificate_pin','certificate_source','tax_certificate']as $key)if(array_key_exists($key,$input['values']))throw new DomainException('Lokale E-Bilanz-Angaben vollständig prüfen. Zertifikate gehören zur gesonderten Abgabe.',422);
        BalanceFilingActions::values($input['action'],$input['values']);
        if(strlen(self::json($input))>2000000)throw new DomainException('E-Bilanz-Formular ist zu umfangreich.',422);
        return $input;
    }
    public function run(array $input,string $key):array
    {
        $a=$this->actor(true);$this->available();self::key($key);$input=self::capture($input);$c=$input['context'];
        if($a['id']!==$c['actor_id']||$a['club_id']!==$c['club_id'])throw new DomainException('Ursprünglicher E-Bilanz-Auftrag gehört zu einem anderen Zugang.',403);
        $json=self::json($input);$hash=hash('sha256',$json);
        $first=$this->q('SELECT * FROM balance_filing_commands WHERE club_id=? AND request_key=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$a['club_id'],$key])->fetch();
        if($first)return $this->repeat($first,$a,$hash);
        if($this->db->inTransaction())throw new DomainException('Neuen E-Bilanz-Auftrag außerhalb des laufenden Vorgangs speichern.',409);
        try{return Transactions::run($this->db,function()use($a,$input,$c,$key,$json,$hash):array{
            $current=$this->actor(true);if($current['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$current['club_id']])->fetchColumn();
            $first=$this->q('SELECT * FROM balance_filing_commands WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT',[$current['club_id'],$key])->fetch();
            if($first)return $this->repeat($first,$current,$hash);
            $id=$c['filing']['id']??null;
            if($this->current($current,$c['year'],$id,$c['period_id'],'FOR UPDATE NOWAIT')!==$c)throw new DomainException('E-Bilanz, Abschluss, Vereinsdaten oder Programm wurden inzwischen geändert. Ganze Angaben mit dem aktuellen Stand vergleichen.',409);
            $before=$this->filing($id);
            $post=$input['values']+['filing'=>$id??'','version'=>(string)($c['filing']['version']??0),'tax_year'=>(string)$c['year'],'period_id'=>$c['period_id']??''];
            $applied=BalanceFilingActions::apply($this->db,$this->actorId,$input['action'],$post,$this->runtime);$after=$applied['filing'];
            $again=$this->actor(true);if($again['club_id']!==$current['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$op=self::operation($input['action']);
            $original=self::json(['format'=>self::FORMAT,'key'=>$key,'club_id'=>$current['club_id'],'actor_id'=>$current['id'],'operation'=>$op,'year'=>$c['year'],'input'=>$input,'before'=>$before,'result'=>$after,'answer'=>$applied['answer'],'notice'=>$applied['notice'],'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            $this->q('INSERT INTO balance_filing_commands(club_id,request_key,actor_id,operation,tax_year,filing_id,filing_version,input_sha256,input_json,original_sha256,original_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$current['club_id'],$key,$current['id'],$op,$c['year'],$after['id'],(int)$after['version'],$hash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$current['club_id'],$current['id'],'tax.balance.command.'.$op,$key,(int)$after['version'],$now->format('Y-m-d\TH:i:s.u\Z')]);
            return $this->decode($this->q('SELECT * FROM balance_filing_commands WHERE club_id=? AND request_key=?',[$current['club_id'],$key])->fetch());
        });}catch(\Throwable $e){$this->busy($e);}
    }
    private function repeat(array $row,array $actor,string $hash):array
    {
        if($row['actor_id']!==$actor['id']||!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese E-Bilanz-Kennung gehört zu anderen ursprünglichen Angaben. Gespeicherten Ausgang lesen.',409);
        return $this->decode($row);
    }
    private function decode(array $row):array
    {
        try{
            $input=self::capture(json_decode($row['input_json'],true,128,JSON_THROW_ON_ERROR));$o=json_decode($row['original_json'],true,128,JSON_THROW_ON_ERROR);$c=$input['context'];$before=$o['before'];$after=$o['result'];$op=self::operation($input['action']);
            if(!hash_equals($row['input_sha256'],hash('sha256',$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||$o['format']!==self::FORMAT||$o['key']!==$row['request_key']||$o['club_id']!==$row['club_id']||$o['actor_id']!==$row['actor_id']||$o['operation']!==$op||$op!==$row['operation']||$o['input']!==$input||$o['year']!==$c['year']||$c['year']!==(int)$row['tax_year']||$c['club_id']!==$row['club_id']||$c['actor_id']!==$row['actor_id']||$after['id']!==$row['filing_id']||$after['club_id']!==$row['club_id']||$after['tax_year']!==$c['year']||$after['version']!==(int)$row['filing_version']||$after['family']!=='balance'||!is_array($after['form_data']['balance_preparation']??null)||$o['created_at']!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche E-Bilanz-Bestätigung prüfen lassen.',503);
            if($op==='create'){
                if($before!==null||$after['version']!==1||$after['state']!=='draft'||$after['environment']!==($input['values']['environment']??'test'))throw new DomainException('Ursprünglichen neu angelegten E-Bilanz-Entwurf prüfen lassen.',503);
            }elseif(!is_array($before)||$before['id']!==$c['filing']['id']||$before['version']!==$c['filing']['version']||!hash_equals($c['filing']['sha256'],hash('sha256',self::json($before)))||$after['id']!==$before['id']||$after['version']!==$before['version']+1||$after['environment']!==$before['environment'])throw new DomainException('Ursprünglichen E-Bilanz-Vergleich prüfen lassen.',503);
            foreach([$before,$after]as $filing)if($filing!==null&&$filing['xml_data']!==null&&$filing['xml_sha256']!==hash('sha256',$filing['xml_data']))throw new DomainException('Ursprüngliche E-Bilanz-XML prüfen lassen.',503);
            return ['key'=>$row['request_key'],'actor_id'=>$row['actor_id'],'operation'=>$op,'year'=>$c['year'],'input'=>$input,'before'=>$before,'result'=>$after,'answer'=>$o['answer'],'notice'=>$o['notice'],'created_at'=>$o['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
        }catch(\Throwable $e){throw new DomainException('Unveränderte ursprüngliche E-Bilanz-Bestätigung prüfen lassen.',503,$e);}
    }
    public function receipt(string $key):?array
    {
        self::key($key);
        return $this->read(function()use($key):?array{$a=$this->actor();$this->available();$row=$this->q('SELECT * FROM balance_filing_commands WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$a['club_id'],$key])->fetch();return $row?$this->decode($row):null;});
    }
    public function history(int $year,int $page=1):array
    {
        if($year<2000||$year>9999||$page<1||$page>1000000)throw new DomainException('Jahr und E-Bilanz-Bestätigungsseite vollständig prüfen.',422);
        return $this->read(function()use($year,$page):array{
            $a=$this->actor();$this->available();$count=(int)$this->q('SELECT COUNT(*) FROM balance_filing_commands WHERE club_id=? AND tax_year=?',[$a['club_id'],$year])->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min($pages,$page);
            $rows=$this->q('SELECT request_key,actor_id,operation,tax_year,filing_id,filing_version,created_at FROM balance_filing_commands WHERE club_id=? AND tax_year=? ORDER BY created_at DESC,request_key DESC LIMIT 6 OFFSET '.(($page-1)*6),[$a['club_id'],$year])->fetchAll();return compact('rows','count','page','pages');
        });
    }
}
