<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** The first chair approves exact report bytes, not general permission to use a signature. */
final class ActivityReportReview
{
    public const KINDS=['tax'=>'Tätigkeitsbericht für das Finanzamt','assembly'=>'Tätigkeitsbericht für die Mitgliederversammlung'];
    public function __construct(private PDO $db, private string $actor, private ?WebsiteCalendar $website=null,private string $kind='tax')
    {
        if(!isset(self::KINDS[$kind]))throw new DomainException('Berichtsart auswählen.');
    }
    public function kind():string{return $this->kind;}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private static function json(array $a):string{return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function hash(array $a):string{return hash('sha256',self::json($a));}

    /** No superuser shortcut: this report is specifically delegated to the current first chair. */
    public function chair():?array
    {
        $a=Permissions::actor($this->db,$this->actor);$valid=[];
        foreach($this->q("SELECT actor_id,version FROM admission_board WHERE club_id=? AND enabled=1 AND office='1. Vorstand'",[$a['club_id']]) as $r){
            try{$p=Permissions::actor($this->db,$r['actor_id']);}catch(DomainException){continue;}
            if($p['club_id']!==$a['club_id']||$p['role']==='editor')continue;
            $name='';
            if($p['contact_id'])$name=(string)$this->q("SELECT name FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL",[$a['club_id'],$p['contact_id']])->fetchColumn();
            else{$raw=$this->q('SELECT payload FROM club_settings WHERE club_id=? AND section=?',[$a['club_id'],'account:'.$p['id']])->fetchColumn();$data=$raw?json_decode($raw,true):[];$name=is_string($data['display_name']??null)?$data['display_name']:'';}
            $valid[]=['actor_id'=>$p['id'],'board_version'=>(int)$r['version'],'name'=>trim($name)];
        }
        return count($valid)===1?$valid[0]:null;
    }
    public function isChair():bool
    {
        try{return ($this->chair()['actor_id']??null)===$this->actor;}catch(DomainException){return false;}
    }
    public function canRead():bool
    {
        try{$a=Permissions::actor($this->db,$this->actor);return $a['role']!=='editor'&&($this->isChair()||($this->kind==='tax'?in_array($a['finance_access'],['read','write'],true):(BoardAccess::allowed($this->db,$this->actor)||(new Documents($this->db,$this->actor))->canScope('orga'))));}catch(DomainException){return false;}
    }
    public function canPrepare():bool
    {
        try{$a=Permissions::actor($this->db,$this->actor);return $a['role']!=='editor'&&($this->isChair()||($this->kind==='tax'?$a['finance_access']==='write':(BoardAccess::allowed($this->db,$this->actor)||(new Documents($this->db,$this->actor))->canScope('orga',true))));}catch(DomainException){return false;}
    }
    private function access(bool $write=false):array
    {
        if(!($write?$this->canPrepare():$this->canRead()))throw new DomainException('Keine Berechtigung für den Tätigkeitsbericht.');
        return Permissions::actor($this->db,$this->actor);
    }
    private function atomic(callable $fn):mixed
    {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{$r=$fn();if($own)$this->db->commit();return $r;}catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function text(string $v,bool $empty=false):string
    {
        $v=str_replace(["\r\n","\r"],"\n",$v);
        if((!$empty&&trim($v)==='')||strlen($v)>160000||mb_strlen($v)>40000||!mb_check_encoding($v,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new DomainException('Bericht prüfen (höchstens 40.000 Zeichen).');
        return $v;
    }
    public function source(int $from,int $to,string $supplement=''):array
    {
        $a=$this->access();$supplement=self::text($supplement,true);
        // Calendar facts are shared. Restricted minutes are separate evidence, not silently copied.
        $generated=(new ActivityReport($this->db,$this->actor,$this->website))->build($from,$to,$this->kind,false);
        $raw=$this->q("SELECT payload FROM club_settings WHERE club_id=? AND section='club'",[$a['club_id']])->fetchColumn();$settings=$raw?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];
        $club=array_intersect_key($settings,array_flip(['name','street','postcode','city']));
        $purpose='';if($this->kind==='tax'){$raw=$this->q("SELECT payload FROM club_settings WHERE club_id=? AND section='tax'",[$a['club_id']])->fetchColumn();$tax=$raw?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];$purpose=(string)($tax['purpose']??'');}
        $period=$from===$to?(string)$from:$from.' - '.$to;
        $facts=preg_replace('/^Tätigkeitsbericht[^\n]*\n/u','',$generated['text'],1);
        $text=self::KINDS[$this->kind].' '.$period."\n";
        if($this->kind==='tax'&&$purpose!=='')$text.="\nSatzungszwecke\n\n".$purpose."\n";
        if($this->kind==='assembly')$text.="\nRückblick auf das Vereinsjahr\n";
        $text=self::text($text.$facts.(trim($supplement)!==''?"\nErgänzungen des Vereins\n\n".$supplement:''));
        return ['text'=>$text,'hash'=>self::hash(['kind'=>$this->kind,'from'=>$from,'to'=>$to,'generated'=>$generated,'purpose'=>$purpose,'supplement'=>$supplement,'club'=>$club]),'club'=>$club,'complete'=>!str_contains($generated['text'],'ACHTUNG: Website-Veranstaltungen nicht abrufbar'),'activity_count'=>count($generated['activities'])+count($generated['website_completed'])];
    }
    public function forPeriod(int $from,int $to):?array
    {
        $a=$this->access();$r=$this->q('SELECT id FROM activity_report_reviews WHERE club_id=? AND report_kind=? AND from_year=? AND to_year=?',[$a['club_id'],$this->kind,$from,$to])->fetchColumn();return $r?$this->get($r):null;
    }
    public function get(string $id):array
    {
        $a=$this->access();$r=$this->q('SELECT * FROM activity_report_reviews WHERE club_id=? AND id=? AND report_kind=?',[$a['club_id'],$id,$this->kind])->fetch();if(!$r)throw new DomainException('Tätigkeitsbericht nicht verfügbar.');return $r;
    }
    public function listing():array
    {
        $a=$this->access();return $this->q('SELECT id,report_kind,from_year,to_year,state,version,updated_at,approved_at FROM activity_report_reviews WHERE club_id=? AND report_kind=? ORDER BY to_year DESC,from_year DESC',[$a['club_id'],$this->kind])->fetchAll();
    }
    private function history(array $r,string $action):void
    {
        $pdf=$r['approved_pdf'];unset($r['approved_pdf']);
        $q=$this->db->prepare('INSERT INTO activity_report_revisions(club_id,report_id,version,snapshot,pdf) VALUES(?,?,?,?,?)');
        $q->bindValue(1,$r['club_id']);$q->bindValue(2,$r['id']);$q->bindValue(3,(int)$r['version'],PDO::PARAM_INT);$q->bindValue(4,self::json($r));$q->bindValue(5,$pdf,$pdf===null?PDO::PARAM_NULL:PDO::PARAM_LOB);$q->execute();
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$r['club_id'],$this->actor,'activity-report.'.$action,$r['id'],(int)$r['version'],gmdate('c')]);
    }
    private static function version(array $r,int $v):void{if((int)$r['version']!==$v)throw new DomainException('Der Bericht wurde inzwischen geändert. Bitte neu öffnen.');}

    /** Explicit preparation creates the review task; GETs never create or overwrite a report. */
    public function prepare(int $from,int $to,string $supplement='',?array $original=null):string
    {
        if($original!==null&&(array_diff(array_keys($original),['club_id','id','version'])||!is_string($original['club_id']??null)||$original['club_id']===''||!is_string($original['id']??null)||!is_int($original['version']??null)||$original['version']<0||$original['version']>2147483646||($original['version']===0?$original['id']!=='':!preg_match('/\A[a-f0-9]{32}\z/D',$original['id']))))throw new DomainException('Ursprünglichen Stand der Berichtsvorbereitung prüfen.',422);
        $this->access(true);$supplement=self::text($supplement,true);$source=$this->source($from,$to,$supplement);
        return $this->atomic(function()use($from,$to,$supplement,$source,$original):string{
            $a=$this->access(true);$old=$this->forPeriod($from,$to);
            if($original!==null){
                if($original['club_id']!==$a['club_id'])throw new DomainException('Die ursprüngliche Vereinszuordnung wurde geändert. Ursprüngliche Angaben behalten und aktuellen Zeitraum getrennt prüfen.',409);
                if($original['version']===0&&$old){
                    if((int)$old['version']===1&&$old['state']==='review'&&$old['updated_by']===$a['id']&&$old['supplement']===$supplement&&hash_equals($old['source_hash'],$source['hash']))return$old['id'];
                    throw new DomainException('Der ursprüngliche Berichtszeitraum wurde inzwischen vorbereitet oder geändert. Gespeicherten Stand getrennt prüfen.',409);
                }
                if($original['version']>0&&(!$old||$old['id']!==$original['id']||(int)$old['version']!==$original['version']))throw new DomainException('Der ursprüngliche Bericht wurde inzwischen geändert. Gespeicherten Stand getrennt prüfen.',409);
            }
            if($old){
                if($old['supplement']===$supplement&&hash_equals($old['source_hash'],$source['hash']))return $old['id'];
                // Preserve manually edited text. Changed sources require another explicit review.
                if($old['supplement']!==$supplement||$old['state']==='approved'){
                    $changed=$this->q("UPDATE activity_report_reviews SET supplement=?,state='review',version=version+1,approved_by=NULL,approved_at=NULL,approval_json=NULL,approved_pdf=NULL,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=?",[$supplement,$this->actor,gmdate('c'),$a['club_id'],$old['id'],(int)$old['version']]);
                    if($changed->rowCount()!==1)throw new DomainException('Der Bericht wurde inzwischen geändert. Bitte neu öffnen.',409);
                    $this->history($this->get($old['id']),'sources-changed');
                }
                return $old['id'];
            }
            $id=bin2hex(random_bytes(16));$this->q('INSERT INTO activity_report_reviews(club_id,id,report_kind,from_year,to_year,body,supplement,source_hash,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$this->kind,$from,$to,$source['text'],$supplement,$source['hash'],$a['id'],gmdate('c')]);
            $this->history($this->get($id),'review-requested');return $id;
        });
    }
    public function edit(string $id,int $version,string $body,string $sourceHash):void
    {
        $this->access(true);$body=self::text($body);
        $this->atomic(function()use($id,$version,$body,$sourceHash):void{
            $r=$this->get($id);self::version($r,$version);
            if(!$this->isChair())throw new DomainException('Den zur Prüfung vorgelegten Bericht kann nur der 1. Vorstand ändern.');
            $source=$this->source((int)$r['from_year'],(int)$r['to_year'],$r['supplement']);
            if(!hash_equals($source['hash'],$sourceHash))throw new DomainException('Die Tätigkeiten wurden inzwischen geändert. Bitte neu öffnen und prüfen.');
            $changed=$this->q("UPDATE activity_report_reviews SET body=?,source_hash=?,state='review',version=version+1,approved_by=NULL,approved_at=NULL,approval_json=NULL,approved_pdf=NULL,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=?",[$body,$sourceHash,$this->actor,gmdate('c'),$r['club_id'],$id,$version]);
            if($changed->rowCount()!==1)throw new DomainException('Der Bericht wurde inzwischen geändert. Bitte neu öffnen.',409);
            $this->history($this->get($id),'edited');
        });
    }
    /** Narrow maintenance of known old website aliases; never edit prose or approve a report. */
    public function correctSourceLinks(string $id,int $version):int
    {
        $this->access(true);
        return $this->atomic(function()use($id,$version):int{
            $r=$this->get($id);if((int)$r['version']!==$version)throw new DomainException('Der Bericht wurde inzwischen geändert. Bitte neu öffnen.',409);
            if($r['state']!=='review'||$r['approved_pdf']!==null||$r['approval_json']!==null||$r['approved_by']!==null||$r['approved_at']!==null)throw new DomainException('Quellenkorrektur nur am noch nicht freigegebenen Bericht.',409);
            $source=$this->source((int)$r['from_year'],(int)$r['to_year'],$r['supplement']);
            preg_match_all('~^Quelle: (https?://[^\s]+)[ \t\r]*$~m',$source['text'],$matches);$mapping=[];
            foreach($matches[1] as$url){$parts=parse_url($url);$slug=trim($parts['path']??'','/');$origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');foreach(['/site/start/site/'.$slug,'/site/start/site/'.$slug.'/',$origin.'/site/'.$slug,$origin.'/site/'.$slug.'/']as$alias)$mapping[$alias]=$url;}
            $count=0;$body=preg_replace_callback('~(?<=^Quelle: )([^\s]+)(?=[ \t\r]*$)~m',static function($match)use($mapping,&$count){if(isset($mapping[$match[1]])&&$mapping[$match[1]]!==$match[1]){$count++;return $mapping[$match[1]];}return $match[1];},$r['body']);
            if(!$count)return 0;
            // Preserve the original source basis, approval fields and every line outside these URLs.
            $changed=$this->q("UPDATE activity_report_reviews SET body=?,version=version+1,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state='review'",[$body,$this->actor,gmdate('c'),$r['club_id'],$id,$version]);
            if($changed->rowCount()!==1)throw new DomainException('Der Bericht wurde inzwischen geändert. Bitte neu öffnen.',409);
            $this->history($this->get($id),'source-links-corrected');return $count;
        });
    }

    private function reviewData(array $r,bool $withSignature):array
    {
        $this->access();$source=$this->source((int)$r['from_year'],(int)$r['to_year'],$r['supplement']);$chair=$this->chair();
        $name=$chair['name']??'';$club=$source['club'];$asset=null;
        if($withSignature){
            if(!$this->isChair())throw new DomainException('Nur der 1. Vorstand kann seine Unterschrift für diesen Bericht freigeben.');
            $asset=(new BoardSignature($this->db,$this->actor))->asset();if(!$asset)throw new DomainException('Die eigene Unterschrift zuerst unter Mein Account hinterlegen.');
        }
        $data=['activity_review'=>['kind'=>$this->kind,'title'=>self::KINDS[$this->kind],'club'=>$club,'from'=>(int)$r['from_year'],'to'=>(int)$r['to_year'],'body'=>$r['body'],'signer'=>$name,'place'=>$club['city']??'','issued'=>date('d.m.Y'),'approved'=>false],
               'signature_used'=>$withSignature];
        if($asset)$data['signature']=base64_encode($asset['bytes']);
        return ['data'=>$data,'source'=>$source,'chair'=>$chair,'asset'=>$asset,'hash'=>self::hash(['kind'=>$this->kind,'id'=>$r['id'],'version'=>(int)$r['version'],'body'=>$r['body'],'source'=>$source['hash'],'chair'=>$chair,'signature'=>$asset?[$asset['id'],$asset['sha256']]:null,'issued'=>date('Y-m-d')])];
    }
    public function preview(string $id,int $version,bool $withSignature):array
    {
        $r=$this->get($id);self::version($r,$version);$p=$this->reviewData($r,$withSignature);
        return ['hash'=>$p['hash'],'pdf'=>DocumentPreview::pdf($p['data'])];
    }
    public function approve(string $id,int $version,string $previewHash,bool $withSignature,bool $confirmed):void
    {
        if(!$this->isChair()||!$confirmed)throw new DomainException('Prüfung und Freigabe durch den aktuellen 1. Vorstand bestätigen.');
        $r=$this->get($id);self::version($r,$version);if($r['state']!=='review')throw new DomainException('Der Bericht ist bereits freigegeben.');
        $p=$this->reviewData($r,$withSignature);
        if(!$p['source']['complete']||trim($p['chair']['name']??'')===''||trim($p['source']['club']['name']??'')==='')throw new DomainException('Vereinsname, Name des 1. Vorstands und vollständige Tätigkeiten erforderlich.');
        if(!hash_equals($r['source_hash'],$p['source']['hash']))throw new DomainException('Die Tätigkeiten wurden seit der Erstellung geändert. Bericht prüfen und zuerst speichern.');
        if(!hash_equals($p['hash'],$previewHash))throw new DomainException('Bericht, Unterschrift oder Vorstandsamt wurden geändert. Bitte die vollständige Vorschau erneut öffnen.');
        $data=$p['data'];$data['activity_review']['approved']=true;$pdf=DocumentPreview::pdf($data);
        $this->atomic(function()use($id,$version,$previewHash,$withSignature,$pdf):void{
            if(!$this->isChair())throw new DomainException('Das Vorstandsamt wurde geändert.');
            $r=$this->get($id);self::version($r,$version);$p=$this->reviewData($r,$withSignature);
            if(!hash_equals($previewHash,$p['hash']))throw new DomainException('Bericht oder Quellen wurden inzwischen geändert. Erneut prüfen.');
            $approval=['body_sha256'=>hash('sha256',$r['body']),'source_sha256'=>$p['source']['hash'],'signer'=>$p['chair'],'signature_authorized'=>$withSignature,'signature_id'=>$p['asset']['id']??null,'signature_sha256'=>$p['asset']['sha256']??null,'pdf_sha256'=>hash('sha256',$pdf)];
            $q=$this->db->prepare("UPDATE activity_report_reviews SET state='approved',version=version+1,approved_by=?,approved_at=?,approval_json=?,approved_pdf=?,updated_by=?,updated_at=? WHERE club_id=? AND id=? AND version=? AND state='review'");
            $now=gmdate('c');foreach([$this->actor,$now,self::json($approval),$pdf,$this->actor,$now,$r['club_id'],$id,$version] as $ix=>$v)$q->bindValue($ix+1,$v,$ix===3?PDO::PARAM_LOB:($ix===8?PDO::PARAM_INT:PDO::PARAM_STR));$q->execute();
            if($q->rowCount()!==1)throw new DomainException('Der Bericht wurde inzwischen geändert. Bitte neu öffnen.',409);
            $this->history($this->get($id),'approved');
        });
    }
    public function approved(array $r):bool
    {
        $a=$this->access();if($r['club_id']!==$a['club_id']||$r['report_kind']!==$this->kind||$r['state']!=='approved'||!is_string($r['approved_pdf']))return false;
        $approval=json_decode($r['approval_json']??'',true);$source=$this->source((int)$r['from_year'],(int)$r['to_year'],$r['supplement']);
        return $source['complete']&&hash_equals($r['source_hash'],$source['hash'])&&($approval['body_sha256']??'')===hash('sha256',$r['body'])&&($approval['pdf_sha256']??'')===hash('sha256',$r['approved_pdf']);
    }
    public function file(string $id):array
    {
        $r=$this->get($id);if(!$this->approved($r))throw new DomainException('Der aktuelle Tätigkeitsbericht ist noch nicht freigegeben.');
        return ['bytes'=>$r['approved_pdf'],'name'=>'taetigkeitsbericht-'.($this->kind==='tax'?'finanzamt':'mitgliederversammlung').'-'.$r['from_year'].'-'.$r['to_year'].'.pdf'];
    }
}
