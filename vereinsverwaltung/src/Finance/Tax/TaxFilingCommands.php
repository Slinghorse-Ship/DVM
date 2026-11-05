<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;
/** Immutable first results of local preparation. Transmission owns its separate attempt journal. */
final class TaxFilingCommands
{
    public const FORMAT='dvm-tax-filing-command/1';
    public const CONTEXT='dvm-tax-filing-context/1';
    public const OPERATIONS=['profile','create','save','validate','evidence'];
    public function __construct(private PDO$db,private string$actorId){}
    public static function json(array$value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private function q(string$sql,array$args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function actor(bool$write=false):array
    {
        try{$a=Permissions::actor($this->db,$this->actorId);}catch(DomainException$e){throw new DomainException($e->getMessage(),403,$e);}
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Aktuelle Finanzberechtigung für Steuererklärungen erforderlich.',403);return$a;
    }
    private function available():void{if(!Database::tableExists($this->db,'tax_filing_commands'))throw new DomainException('Unveränderliche Erklärungsbestätigungen benötigen den aktuellen Datenbankstand.',503);}
    private function busy(\Throwable$e):never
    {
        if($e instanceof \PDOException&&in_array((int)($e->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Steuererklärung wird gerade bearbeitet. Zuerst den Ausgang mit derselben Kennung lesen.',503,$e);throw$e;
    }
    private function read(callable$operation):array
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$value=$operation();if($own)$this->db->commit();return$value;}catch(\Throwable$e){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($e);}
    }
    private static function year(int$year):void{if($year<2000||$year>(int)date('Y'))throw new DomainException('Gültiges Erklärungsjahr auswählen.',422);}
    private static function pdf(?string$bytes):?array{return$bytes===null?null:['sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes)];}
    private static function recorded(array$state):array
    {
        foreach(['filing','evidence']as$key)if(isset($state[$key]))$state[$key]['pdf']=self::pdf($state[$key]['pdf']);return$state;
    }
    private function state(int$year,?string$id):array
    {
        $f=new TaxFilings($this->db,$this->actorId);$filing=$id===null?null:$f->get($id);if($filing!==null&&(int)$filing['tax_year']!==$year)throw new DomainException('Erklärung und ursprüngliches Jahr passen nicht zusammen.',409);
        return['profile'=>$f->profile($year),'filing'=>$filing,'evidence'=>$id===null?null:(new TaxFilingEvidence($this->db,$this->actorId))->linked($id)];
    }
    private function settings(string$club):string{return hash('sha256',self::json($this->q("SELECT section,version,payload FROM club_settings WHERE club_id=? AND section IN ('club','tax') ORDER BY section FOR SHARE NOWAIT",[$club])->fetchAll()));}
    private static function catalogue():?array
    {
        try{$c=TaxEricAdapter::configuration();foreach(['_path','catalog']as$key)if(!is_file($c[$key])||!is_readable($c[$key]))throw new DomainException('Formularkatalog derzeit nicht lesbar.',503);return['configuration'=>hash_file('sha256',$c['_path']),'catalogue'=>hash_file('sha256',$c['catalog'])];}catch(DomainException){return null;}
    }
    private function current(array$actor,int$year,?string$id,string$lock):array
    {
        $profile=$this->q('SELECT version FROM tax_filing_profiles WHERE club_id=? AND tax_year=? '.$lock,[$actor['club_id'],$year])->fetchColumn();$reference=null;
        if($id!==null){$row=$this->q('SELECT * FROM tax_filings WHERE club_id=? AND id=? '.$lock,[$actor['club_id'],$id])->fetch();if(!$row)throw new DomainException('Ursprüngliche Steuererklärung nicht gefunden.',404);if((int)$row['tax_year']!==$year)throw new DomainException('Erklärung und ursprüngliches Jahr passen nicht zusammen.',409);foreach(['form_data','scope_snapshot','validation']as$key)$row[$key]=$row[$key]===null?[]:json_decode($row[$key],true,64,JSON_THROW_ON_ERROR);$reference=['id'=>$row['id'],'version'=>(int)$row['version'],'state'=>$row['state'],'data_version'=>$row['data_version'],'environment'=>$row['environment'],'sha256'=>hash('sha256',self::json(self::recorded(['filing'=>$row])))];}
        return['format'=>self::CONTEXT,'club_id'=>$actor['club_id'],'actor_id'=>$actor['id'],'year'=>$year,'profile_version'=>$profile===false?0:(int)$profile,'filing'=>$reference,'settings_sha256'=>$this->settings($actor['club_id']),'forms'=>self::catalogue()];
    }
    public function workspace(int$year,?string$id=null):array
    {
        if($this->db->inTransaction()){$this->actor();throw new DomainException('Steuerformular außerhalb des bereits laufenden Vorgangs öffnen.',409);}
        self::year($year);if($id!==null&&!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Ursprüngliche Erklärungskennung vollständig prüfen.',422);
        return$this->read(function()use($year,$id):array{$a=$this->actor();$this->available();$context=$this->current($a,$year,$id,'FOR SHARE NOWAIT');return['context'=>$context,'state'=>self::recorded($this->state($year,$id))];});
    }
    private static function capture(array$input):array
    {
        if(array_keys($input)!==['operation','context','values']||!in_array($input['operation'],self::OPERATIONS,true)||!is_array($input['context'])||!is_array($input['values']))throw new DomainException('Erklärungsangaben vollständig prüfen.',422);$c=$input['context'];
        if(array_keys($c)!==['format','club_id','actor_id','year','profile_version','filing','settings_sha256','forms']||$c['format']!==self::CONTEXT||!is_string($c['club_id'])||$c['club_id']===''||!is_string($c['actor_id'])||$c['actor_id']===''||!is_int($c['year'])||$c['year']<2000||$c['year']>9999||!is_int($c['profile_version'])||$c['profile_version']<0||!is_string($c['settings_sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$c['settings_sha256']))throw new DomainException('Ursprünglichen Erklärungsstand vollständig prüfen.',422);
        if($c['filing']!==null){$f=$c['filing'];if(!is_array($f)||array_keys($f)!==['id','version','state','data_version','environment','sha256']||!is_string($f['id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$f['id'])||!is_int($f['version'])||$f['version']<1||!is_string($f['state'])||!is_string($f['data_version'])||!in_array($f['environment'],['test','production'],true)||!is_string($f['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$f['sha256']))throw new DomainException('Ursprüngliche Erklärungsfassung vollständig prüfen.',422);}
        if($c['forms']!==null){if(!is_array($c['forms'])||array_keys($c['forms'])!==['configuration','catalogue'])throw new DomainException('Ursprünglichen Formularstand vollständig prüfen.',422);foreach($c['forms']as$value)if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value))throw new DomainException('Ursprünglichen Formularstand vollständig prüfen.',422);}
        if(in_array($input['operation'],['profile','create'],true)!==($c['filing']===null))throw new DomainException('Erklärungsschritt und ursprüngliche Fassung passen nicht zusammen.',422);if(strlen(self::json($input))>2000000)throw new DomainException('Erklärungsformular ist zu umfangreich.',422);return$input;
    }
    private static function values(string$op,array$v):void
    {
        $keys=match($op){'profile'=>['answers'],'create'=>['data_version','environment'],'save'=>['fields','header','add_group','documents'],default=>[]};if($op==='save'&&array_key_exists('document_sources',$v))$keys[]='document_sources';if(array_keys($v)!==$keys)throw new DomainException('Erklärungsangaben vollständig prüfen.',422);
        if($op==='profile'&&!is_array($v['answers']))throw new DomainException('Steuerprofil vollständig prüfen.',422);
        if($op==='create'&&(!is_string($v['data_version'])||$v['data_version']===''||!in_array($v['environment'],['test','production'],true)))throw new DomainException('Formular und Abgabeumgebung vollständig prüfen.',422);
        if($op==='save'){if(!is_array($v['fields'])||!is_array($v['header'])||($v['add_group']!==null&&!is_string($v['add_group']))||($v['documents']!==null&&!is_array($v['documents'])))throw new DomainException('Formularangaben und Nachweise vollständig prüfen.',422);foreach($v['fields']as$value)if(!is_string($value))throw new DomainException('Formularfelder vollständig prüfen.',422);if(array_diff(array_keys($v['header']),['office','supplier','feedback_email']))throw new DomainException('Absenderangaben vollständig prüfen.',422);foreach($v['header']as$value)if(!is_string($value))throw new DomainException('Absenderangaben vollständig prüfen.',422);}
    }
    public function run(array$input,string$key):array
    {
        $a=$this->actor(true);$this->available();$input=self::capture($input);$c=$input['context'];if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Erklärungsauftragskennung vollständig prüfen.',422);if($c['club_id']!==$a['club_id']||$c['actor_id']!==$a['id'])throw new DomainException('Ursprünglicher Erklärungsauftrag gehört zu einem anderen Zugang.',403);$json=self::json($input);$hash=hash('sha256',$json);$first=$this->q('SELECT * FROM tax_filing_commands WHERE club_id=? AND request_key=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$a['club_id'],$key])->fetch();if($first)return$this->repeat($first,$a,$hash);if($this->db->inTransaction())throw new DomainException('Neuen Erklärungsauftrag außerhalb des bereits laufenden Vorgangs speichern.',409);
        try{return Transactions::run($this->db,function()use($a,$input,$c,$key,$json,$hash):array{
            $current=$this->actor(true);if($current['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$first=$this->q('SELECT * FROM tax_filing_commands WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT',[$current['club_id'],$key])->fetch();if($first)return$this->repeat($first,$current,$hash);self::year($c['year']);$id=$c['filing']['id']??null;if($this->current($current,$c['year'],$id,'FOR UPDATE NOWAIT')!==$c)throw new DomainException('Steuerprofil, Erklärung, Vereinsdaten oder Formularstand wurden inzwischen geändert. Ganze Angaben mit dem aktuellen Stand vergleichen.',409);$op=$input['operation'];$v=$input['values'];self::values($op,$v);$before=$this->state($c['year'],$id);$f=new TaxFilings($this->db,$this->actorId);
            if(isset($before['filing'])&&($before['filing']['family']==='balance'||isset($before['filing']['form_data']['balance_preparation'])))throw new DomainException('E-Bilanz im zugehörigen Bilanzbereich bearbeiten.',409);
            $target=$id;$answer=null;
            switch($op){
                case'profile':$f->saveProfile($c['year'],$v['answers'],$c['profile_version']);break;
                case'create':$target=$f->create($v['data_version'],$v['environment'],$c['year']);break;
                case'save':
                    if($v['documents']!==null){
                        $expected=$v['document_sources']??null;if(!is_array($expected)||count($expected)!==count($v['documents']))throw new DomainException('Ursprüngliche Nachweisfassungen vollständig prüfen.',422);
                        $selected=(new TaxAttachments($this->db,$this->actorId))->select($v['documents'],$c['year']);
                        foreach($selected as$i=>$item)if(($expected[$i]??null)!==array_intersect_key($item,array_flip(['id','kind','revision','sha256'])))throw new DomainException('Ausgewählter Nachweis wurde geändert. Ursprüngliche und aktuelle Fassung ausdrücklich vergleichen.',409);
                    }
                    $f->save($id,$c['filing']['version'],$v['fields'],$v['header'],$v['add_group'],$v['documents']);
                    if($v['documents']!==null){$actual=$f->get($id)['form_data']['attachments'];foreach($actual as$i=>$item)if(($expected[$i]??null)!==array_intersect_key($item,array_flip(['id','kind','revision','sha256'])))throw new DomainException('Ausgewählter Nachweis wurde geändert. Ursprüngliche und aktuelle Fassung ausdrücklich vergleichen.',409);}
                    break;
                case'validate':$answer=$f->validate($id,$c['filing']['version']);break;
                case'evidence':$target=(new TaxFilingEvidence($this->db,$this->actorId))->prepare($id,$c['filing']['version']);break;
            }
            $after=$this->state($c['year'],$target);$beforePdf=($op==='evidence'?$before['evidence']['pdf']??null:$before['filing']['pdf']??null);$originalPdf=$after['filing']['pdf']??null;$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=self::json(['format'=>self::FORMAT,'request_key'=>$key,'club_id'=>$current['club_id'],'actor_id'=>$current['id'],'operation'=>$op,'year'=>$c['year'],'input'=>$input,'before'=>self::recorded($before),'result'=>self::recorded($after),'answer'=>$answer,'binary'=>['before_pdf'=>self::pdf($beforePdf),'original_pdf'=>self::pdf($originalPdf)],'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);$params=[$current['club_id'],$key,$current['id'],$op,$c['year'],$target,(int)($after['filing']['version']??0),(int)$after['profile']['version'],$hash,$json,hash('sha256',$original),$original,$beforePdf,$originalPdf,$now->format('Y-m-d H:i:s.u')];
            $q=$this->db->prepare('INSERT INTO tax_filing_commands(club_id,request_key,actor_id,operation,tax_year,filing_id,filing_version,profile_version,input_sha256,input_json,original_sha256,original_json,before_pdf,original_pdf,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');foreach($params as$i=>$value)$q->bindValue($i+1,$value,$value===null?PDO::PARAM_NULL:(in_array($i,[12,13],true)?PDO::PARAM_LOB:(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR)));$q->execute();$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$current['club_id'],$current['id'],'tax.filing.command.'.$op,$key,(int)($after['filing']['version']??$after['profile']['version']),$now->format('Y-m-d\TH:i:s.u\Z')]);return$this->decode($this->q('SELECT * FROM tax_filing_commands WHERE club_id=? AND request_key=?',[$current['club_id'],$key])->fetch());
        });}catch(\Throwable$e){$this->busy($e);}
    }
    private function repeat(array$row,array$actor,string$hash):array
    {
        if($row['actor_id']!==$actor['id']||!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese Erklärungskennung gehört zu anderen ursprünglichen Angaben. Gespeicherten Ausgang lesen.',409);return$this->decode($row);
    }
    private function decode(array$row):array
    {
        try{$input=self::capture(json_decode($row['input_json'],true,128,JSON_THROW_ON_ERROR));$o=json_decode($row['original_json'],true,128,JSON_THROW_ON_ERROR);$c=$input['context'];$before=$o['before']??[];$after=$o['result']??[];$op=$input['operation'];$filing=$after['filing']??null;
            if(!hash_equals($row['input_sha256'],hash('sha256',$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($o['format']??null)!==self::FORMAT||($o['request_key']??null)!==$row['request_key']||($o['club_id']??null)!==$row['club_id']||($o['actor_id']??null)!==$row['actor_id']||($o['operation']??null)!==$row['operation']||$op!==$row['operation']||($o['year']??null)!==(int)$row['tax_year']||($o['input']??null)!==$input||$c['club_id']!==$row['club_id']||$c['actor_id']!==$row['actor_id']||$c['year']!==(int)$row['tax_year']||($before['profile']['version']??null)!==$c['profile_version']||($after['profile']['version']??null)!==(int)$row['profile_version']||($filing['id']??null)!==$row['filing_id']||($filing['version']??0)!==(int)$row['filing_version']||($o['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Unveränderte ursprüngliche Erklärungsbestätigung prüfen lassen.',503);
            if($c['filing']!==null){if(($before['filing']['id']??null)!==$c['filing']['id']||($before['filing']['version']??null)!==$c['filing']['version']||!hash_equals($c['filing']['sha256'],hash('sha256',self::json(['filing'=>$before['filing']]))))throw new DomainException('Ursprüngliche Erklärungsfassung der Bestätigung prüfen lassen.',503);}
            if($filing!==null&&(($filing['club_id']??null)!==$row['club_id']||($filing['tax_year']??null)!==$c['year']))throw new DomainException('Ursprünglichen Verein und Erklärungsjahr prüfen lassen.',503);
            if($op==='profile'&&($filing!==null||$after['profile']['version']!==$c['profile_version']+1))throw new DomainException('Ursprünglichen Steuerprofilausgang prüfen lassen.',503);
            if($op==='create'&&(($filing['version']??null)!==1||($filing['state']??null)!=='draft'||($filing['data_version']??null)!==($input['values']['data_version']??null)||($filing['environment']??null)!==($input['values']['environment']??null)))throw new DomainException('Ursprüngliche neu angelegte Erklärung prüfen lassen.',503);
            if(in_array($op,['save','validate'],true)&&(($filing['id']??null)!==$c['filing']['id']||($filing['version']??null)!==$c['filing']['version']+1))throw new DomainException('Ursprünglichen gespeicherten Erklärungsstand prüfen lassen.',503);
            if($op==='evidence'&&(($filing['form_data']['parent_binding']['id']??null)!==$c['filing']['id']||($filing['form_data']['parent_binding']['sha256']??null)!==($before['filing']['xml_sha256']??null)))throw new DomainException('Ursprünglichen zugehörigen Belegauftrag prüfen lassen.',503);
            foreach(['before_pdf','original_pdf']as$name)if(($o['binary'][$name]??null)!==self::pdf($row[$name]))throw new DomainException('Ursprüngliche Vorschau der Erklärungsbestätigung prüfen lassen.',503);
            if(($o['binary']['original_pdf']??null)!==($filing['pdf']??null)||($o['binary']['before_pdf']??null)!==($op==='evidence'?($before['evidence']['pdf']??null):($before['filing']['pdf']??null)))throw new DomainException('Ursprüngliche Vorschauzuordnung der Erklärungsbestätigung prüfen lassen.',503);
            return['key'=>$row['request_key'],'actor_id'=>$row['actor_id'],'operation'=>$op,'year'=>$c['year'],'input'=>$input,'before'=>$before,'result'=>$after,'answer'=>$o['answer'],'binary'=>$o['binary'],'created_at'=>$o['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
        }catch(\Throwable$e){throw new DomainException('Unveränderte ursprüngliche Erklärungsbestätigung prüfen lassen.',503,$e);}
    }
    public function receipt(string$key):?array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Erklärungsauftragskennung vollständig prüfen.',422);return$this->read(function()use($key):array{$a=$this->actor();$this->available();$r=$this->q('SELECT * FROM tax_filing_commands WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$a['club_id'],$key])->fetch();return['original'=>$r?$this->decode($r):null];})['original'];
    }
    public function file(string$key,bool$before=false):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Erklärungsauftragskennung vollständig prüfen.',422);return$this->read(function()use($key,$before):array{$a=$this->actor();$this->available();$r=$this->q('SELECT * FROM tax_filing_commands WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$a['club_id'],$key])->fetch();if(!$r)throw new DomainException('Ursprüngliche Erklärungsbestätigung nicht gefunden.',404);$this->decode($r);$bytes=$r[$before?'before_pdf':'original_pdf'];if($bytes===null)throw new DomainException('Für diesen Ausgang ist keine ursprüngliche PDF-Vorschau hinterlegt.',404);return['bytes'=>$bytes,'mime'=>'application/pdf','name'=>$before?'Steuererklaerung-vorher.pdf':'Steuererklaerung-urspruengliche-vorschau.pdf','sha256'=>hash('sha256',$bytes)];});
    }
    public function history(int$year,int$page=1):array
    {
        if($year<2000||$year>9999||$page<1||$page>1000000)throw new DomainException('Jahr und Bestätigungsseite vollständig prüfen.',422);return$this->read(function()use($year,$page):array{$a=$this->actor();$this->available();$count=(int)$this->q('SELECT COUNT(*) FROM tax_filing_commands WHERE club_id=? AND tax_year=?',[$a['club_id'],$year])->fetchColumn();$pages=max(1,(int)ceil($count/6));$page=min($page,$pages);$rows=$this->q('SELECT request_key,actor_id,operation,tax_year,filing_id,filing_version,profile_version,created_at FROM tax_filing_commands WHERE club_id=? AND tax_year=? ORDER BY created_at DESC,request_key DESC LIMIT 6 OFFSET '.(($page-1)*6),[$a['club_id'],$year])->fetchAll();return compact('rows','count','page','pages');});
    }
}
