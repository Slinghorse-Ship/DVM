<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
use Kulturhus\Verein\Infrastructure\Database\Transactions;
/** Optional invoice mailbox. Mail is untrusted: importing never posts or pays an invoice. */
final class InvoiceMailbox
{
    private string $key;
    public function __construct(private PDO $db,private string $actor,#[\SensitiveParameter] string $key){if(strlen($key)<32)throw new DomainException('Geschützter Serverschlüssel fehlt.');$this->key=hash_hkdf('sha256',$key,32,'kulturhus-invoice-mailbox-v1');}
    private function access(bool $manage=true):array{$a=Permissions::actor($this->db,$this->actor);if($a['finance_access']!=='write'||($manage&&!$a['settings_access']))throw new DomainException('Finanz-Schreibrecht und Einstellungsrecht erforderlich.',403);return $a;}
    private function manager():array{$a=$this->access();if(empty($a['is_superuser']))throw new DomainException('Nur der Superuser darf Postfachzugänge ändern.',403);return $a;}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function row(string $club,bool $write=false):array|false{return $this->q('SELECT * FROM invoice_mailboxes WHERE club_id=?'.($this->db->inTransaction()?($write?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT'):''),[$club])->fetch();}
    private static function config(array|false $row):array
    {
        if(!$row)return self::defaults();
        try{$data=json_decode($row['config'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $failure){throw new DomainException('Postfachkonfiguration ist beschädigt.',503,$failure);}
        if(!is_array($data))throw new DomainException('Postfachkonfiguration ist beschädigt.',503);
        return array_replace(self::defaults(),$data);
    }
    private function busy(\Throwable $failure):never
    {
        if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Rechnungsfach wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
        throw $failure;
    }
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return $result;}
        catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);}
    }
    public static function defaults():array{return ['enabled'=>false,'smtp_enabled'=>false,'email'=>'','imap_host'=>'','imap_user'=>'','folder'=>'INBOX','since'=>date('Y-m-d'),'smtp_host'=>'','smtp_port'=>'465','smtp_user'=>'','smtp_name'=>''];}
    public function state():array
    {
        return $this->read(function():array{$a=$this->access();$r=$this->row($a['club_id']);return ['data'=>self::config($r),'version'=>(int)($r['version']??0),'has_passwords'=>(bool)$r,'last_run'=>$r['last_run']??'','last_status'=>$r['last_status']??''];});
    }
    private function open(string $cipher,string $club):array{$raw=base64_decode($cipher,true);if($raw===false||strlen($raw)<29)throw new DomainException('Postfach-Zugang nicht entschlüsselbar.',503);$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$club);if($plain===false)throw new DomainException('Postfach-Zugang nicht entschlüsselbar.',503);return json_decode($plain,true,16,JSON_THROW_ON_ERROR);}
    private function seal(#[\SensitiveParameter] array $secrets,string $club):string{$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($secrets,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,$club);if($cipher===false)throw new DomainException('Postfach-Zugang konnte nicht geschützt werden.',503);return base64_encode($iv.$tag.$cipher);}
    public static function normalize(array $input):array
    {
        $d=self::defaults();
        foreach($d as$k=>$v){
            if(is_bool($v)){$d[$k]=SettingsSchema::bool($input[$k]??false);continue;}
            $value=$input[$k]??$v;
            if(!is_string($value)||preg_match('//u',$value)!==1||($k==='smtp_name'?mb_strlen($value,'UTF-8'):strlen($value))>254||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Postfachangaben prüfen.',422);
            $d[$k]=trim($value);
        }
        if($d['email']!==''&&!filter_var($d['email'],FILTER_VALIDATE_EMAIL))throw new DomainException('Rechnungsadresse prüfen.',422);
        foreach(['imap_host','smtp_host']as$k)if($d[$k]!==''&&(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/iD',$d[$k])||str_ends_with(strtolower($d[$k]),'.localhost')))throw new DomainException('Vollständigen Mailserver-Namen ohne Protokoll oder Pfad angeben.',422);
        if(!preg_match('/^[A-Za-z0-9 _.-]{1,80}$/D',$d['folder'])||!in_array($d['smtp_port'],['465','587'],true))throw new DomainException('IMAP-Ordner oder SMTP-Port prüfen.',422);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$d['since']);if(!$date||$date->format('Y-m-d')!==$d['since'])throw new DomainException('Abrufbeginn prüfen.',422);
        foreach(['imap'=>'enabled','smtp'=>'smtp_enabled']as$prefix=>$enabled)if($d[$enabled]&&($d['email']===''||$d[$prefix.'_host']===''||$d[$prefix.'_user']===''))throw new DomainException('Vor dem Aktivieren Adresse, Server und Benutzername vollständig eintragen.',422);
        return $d;
    }
    private static function assertSenderName(string $name):void
    {if(preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f]/',$name))throw new DomainException('Angezeigten Absendernamen ohne Zeilenumbrüche prüfen.',422);}
    private function plan(#[\SensitiveParameter] array $input,array|false $old,string $club):array
    {
        $prior=self::config($old);
        if(!array_key_exists('smtp_name',$input))$input['smtp_name']=$prior['smtp_name'];
        $d=self::normalize($input);if($d['smtp_enabled'])self::assertSenderName(self::displayName($this->db,$club,$d));$secrets=$old?$this->open($old['secrets'],$club):['imap_password'=>'','smtp_password'=>''];
        foreach(['imap','smtp']as$prefix){
            $field=$prefix.'_password';$value=$input[$field]??'';$clear=SettingsSchema::bool($input['clear_'.$field]??false);
            if(!is_string($value)||strlen($value)>1000||preg_match('//u',$value)!==1||str_contains($value,chr(0))||preg_match('/[\r\n]/',$value))throw new DomainException('Postfachpasswort prüfen.',422);
            if($clear&&$value!=='')throw new DomainException('Passwort entweder neu eingeben oder ausdrücklich entfernen.',422);
            if($old&&($prior[$prefix.'_host']!==$d[$prefix.'_host']||$prior[$prefix.'_user']!==$d[$prefix.'_user'])&&!$clear&&$value===''&&$secrets[$field]!=='')throw new DomainException('Bei geändertem Server oder Benutzername das Passwort ausdrücklich neu eingeben oder entfernen.',422);
            if($clear)$secrets[$field]='';elseif($value!=='')$secrets[$field]=$value;
        }
        if(($d['enabled']&&$secrets['imap_password']==='')||($d['smtp_enabled']&&$secrets['smtp_password']===''))throw new DomainException('Passwort für die aktivierte Verbindung eintragen.',422);
        $mailbox=hash('sha256',json_encode([$d['imap_host'],$d['imap_user'],$d['folder'],$d['since']],JSON_THROW_ON_ERROR));
        return ['data'=>$d,'secrets'=>$secrets,'mailbox_key'=>$mailbox,'same'=>$old&&hash_equals($old['mailbox_key'],$mailbox)];
    }
    /** Redacted current review only. No credential material is returned. */
    public function reviewContext():array
    {
        return $this->read(function():array{
            $a=$this->manager();$r=$this->row($a['club_id']);$secrets=$r?$this->open($r['secrets'],$a['club_id']):['imap_password'=>'','smtp_password'=>''];
            return ['version'=>(int)($r['version']??0),'data'=>self::config($r),'sender_name'=>self::displayName($this->db,$a['club_id'],self::config($r)),'passwords'=>array_map(static fn(string $v):bool=>$v!=='',$secrets),'credential_binding'=>$r?hash_hmac('sha256',$r['secrets'],$this->key):'','mailbox_key'=>$r['mailbox_key']??''];
        });
    }
    public function review(#[\SensitiveParameter] array $input,int $version):array
    {
        return $this->read(function()use($input,$version):array{
            $a=$this->manager();$r=$this->row($a['club_id']);
            if((int)($r['version']??0)!==$version)throw new DomainException('Postfach wurde inzwischen geändert. Bitte erneut prüfen.',409);
            if(($r['lock_until']??0)>time())throw new DomainException('Ein Postfachabruf läuft bereits. Bitte danach erneut prüfen.',409);
            $plan=$this->plan($input,$r,$a['club_id']);
            return ['data'=>$plan['data'],'sender_name'=>self::displayName($this->db,$a['club_id'],$plan['data']),'passwords'=>array_map(static fn(string $v):bool=>$v!=='',$plan['secrets'])];
        });
    }
    public function save(#[\SensitiveParameter] array $input,int $version):void
    {
        $club=$this->q('SELECT club_id FROM actors WHERE id=?',[$this->actor])->fetchColumn();
        if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{Transactions::run($this->db,function()use($input,$version,$club):void{
            if(!$this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $a=$this->manager();if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
            $old=$this->row($club,true);
            if((int)($old['version']??0)!==$version)throw new DomainException('Postfach wurde inzwischen geändert. Bitte erneut prüfen.',409);
            if(($old['lock_until']??0)>time())throw new DomainException('Ein Postfachabruf läuft bereits. Bitte danach erneut prüfen.',409);
            $plan=$this->plan($input,$old,$club);$d=$plan['data'];$same=$plan['same'];
            $this->q('INSERT INTO invoice_mailboxes(club_id,version,config,secrets,configured_by,mailbox_key,uidvalidity,last_uid,last_run,last_status,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),config=VALUES(config),secrets=VALUES(secrets),configured_by=VALUES(configured_by),mailbox_key=VALUES(mailbox_key),uidvalidity=VALUES(uidvalidity),last_uid=VALUES(last_uid),last_run=VALUES(last_run),last_status=VALUES(last_status),updated_at=VALUES(updated_at)',[$club,$version+1,json_encode($d,JSON_THROW_ON_ERROR),$this->seal($plan['secrets'],$club),$a['id'],$plan['mailbox_key'],$same?$old['uidvalidity']:0,$same?$old['last_uid']:0,$same?$old['last_run']:null,$same?$old['last_status']:'',gmdate('c')]);
            $this->q("INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,'invoice-mailbox.settings','invoice-mailbox',?,?)",[$club,$a['id'],$version+1,gmdate('c')]);
        });}catch(\Throwable $failure){$this->busy($failure);}
    }
    public function recent():array{$a=$this->access();return $this->q('SELECT i.*,d.title FROM invoice_mail_imports i JOIN documents d ON d.club_id=i.club_id AND d.id=i.document_id WHERE i.club_id=? ORDER BY i.imported_at DESC LIMIT 50',[$a['club_id']])->fetchAll();}
    private function original(array $r,array $mail,int $validity,?string $document):void{
        $original=$mail['original']??null;if(!is_string($original)||$original===''||strlen($original)>15728640)throw new DomainException('Originalnachricht fehlt oder ist zu groß.');
                    $originalHash=hash('sha256',$original);$originalUid=$validity.':'.(int)$mail['uid'];$oldOriginal=$this->q('SELECT sha256 FROM invoice_mail_originals WHERE club_id=? AND mailbox_key=? AND message_uid=?',[$r['club_id'],$r['mailbox_key'],$originalUid])->fetchColumn();
                    if($oldOriginal!==false&&!hash_equals($oldOriginal,$originalHash))throw new DomainException('Originalnachricht inzwischen verändert.');
                    if($oldOriginal===false){$originalQuery=$this->db->prepare('INSERT INTO invoice_mail_originals VALUES(?,?,?,?,?,?)');$originalQuery->bindValue(1,$r['club_id']);$originalQuery->bindValue(2,$r['mailbox_key']);$originalQuery->bindValue(3,$originalUid);$originalQuery->bindValue(4,$originalHash);$originalQuery->bindValue(5,$original,PDO::PARAM_LOB);$originalQuery->bindValue(6,gmdate('c'));$originalQuery->execute();}

        if($document!==null)$this->q('INSERT IGNORE INTO invoice_mail_original_links VALUES(?,?,?,?)',[$r['club_id'],$document,$r['mailbox_key'],$originalUid]);
    }
    private function event(array $row,string $status,array $mail=[],string $filename='',string $detail=''):void{
        $this->q('INSERT INTO invoice_mail_events(club_id,mailbox_key,message_uid,happened_at,status,sender,subject,filename,detail) VALUES(?,?,?,?,?,?,?,?,?)',[$row['club_id'],$row['mailbox_key'],(string)($mail['uid']??''),gmdate('c'),$status,mb_substr((string)($mail['sender']??''),0,254),mb_substr((string)($mail['subject']??''),0,200),mb_substr($filename,0,240),$detail]);
    }
    public function problems():array{$a=$this->access();return $this->q("SELECT * FROM invoice_mail_events WHERE club_id=? AND status='failed' ORDER BY id DESC LIMIT 30",[$a['club_id']])->fetchAll();}
    public function fetch(?callable $read=null):array{
        $a=$this->access(false);$r=$this->q('SELECT * FROM invoice_mailboxes WHERE club_id=?',[$a['club_id']])->fetch();$result=['new'=>0,'existing'=>0,'failed'=>0,'messages'=>0,'mail_only'=>0];if(!$r||empty(json_decode($r['config'],true)['enabled']))return $result+['status'=>'disabled'];
        if(!MailPolicy::allowed((string)getenv('APP_URL')))return $result+['status'=>'local_disabled'];
        $lease=time()+120;
        if($this->q('UPDATE invoice_mailboxes SET lock_until=? WHERE club_id=? AND lock_until<? AND version=?',[$lease,$a['club_id'],time(),$r['version']])->rowCount()!==1)throw new DomainException('Ein Postfachabruf läuft bereits.');
        try{
            $config=json_decode($r['config'],true,32,JSON_THROW_ON_ERROR)+$this->open($r['secrets'],$a['club_id']);
            $batch=($read??[InvoiceMailboxTransport::class,'read'])($config,(int)$r['uidvalidity'],(int)$r['last_uid']);
            if(!is_array($batch)||!is_array($batch['messages']??null)||count($batch['messages'])>25)throw new DomainException('Ungültiger Postfachabruf.');
            $docs=new Documents($this->db,$this->actor);$last=($batch['uidvalidity']==$r['uidvalidity'])?(int)$r['last_uid']:0;
            foreach($batch['messages'] as $mail){$purgedAttachment=false;++$result['messages'];$this->access(false);if($this->q('SELECT 1 FROM invoice_mailboxes WHERE club_id=? AND version=? AND lock_until=? AND lock_until>?',[$a['club_id'],$r['version'],$lease,time()])->fetchColumn()===false)throw new DomainException('Abruf abgelaufen.');$uid=(int)$mail['uid'];$eventMail=$mail;$eventMail['uid']=$batch['uidvalidity'].':'.$uid;if($uid<1||$uid<=$last)throw new DomainException('Postfachreihenfolge prüfen.');
                foreach($mail['attachments'] as $file){if($this->q('SELECT 1 FROM invoice_mailboxes WHERE club_id=? AND version=? AND lock_until=? AND lock_until>?',[$a['club_id'],$r['version'],$lease,time()])->fetchColumn()===false)throw new DomainException('Abruf abgelaufen.');try{
                    if(!in_array(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)),['pdf','xml','png','jpg','jpeg','heic','heif'],true))throw new DomainException('Nur Rechnungs-PDF, XML und Bilder werden übernommen.');
                    [$name,,$hash]=Documents::inspectFile($file['name'],$file['bytes']);$requestKey=substr(hash('sha256','invoice-mail:'.$a['club_id'].':'.$hash),0,32);if($docs->purgedUploadRequest($requestKey)){$purgedAttachment=true;++$result['existing'];continue;}
                    $existingDocument=$this->q('SELECT document_id FROM invoice_mail_imports WHERE club_id=? AND content_hash=?',[$a['club_id'],$hash])->fetchColumn();
                    if($existingDocument){$this->db->beginTransaction();try{$this->original($r,$mail,(int)$batch['uidvalidity'],(string)$existingDocument);$this->db->commit();}catch(\Throwable $originalError){$this->db->rollBack();throw new \RuntimeException('Originalnachricht konnte nicht gespeichert werden.',0,$originalError);}++$result['existing'];$this->event($r,'duplicate',$eventMail,$name);continue;}
                    $date=substr($mail['date'],0,10);$dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)$date=date('Y-m-d');
                    $subject=mb_substr((string)$mail['subject'],0,200);$sender=mb_substr((string)$mail['sender'],0,254);$notes='E-Mail-Eingang von '.$sender.' · '.$subject."\nNoch prüfen: Rechnungsdatum, Betrag und Zuordnung. Eingangsdatum vorbelegt.";
                    $matches=[];foreach($this->q("SELECT id,display_number FROM finance_entries WHERE club_id=? AND kind='expense' AND display_number<>''",[$a['club_id']])->fetchAll() as $entry)if(preg_match('/(?<![\pL\pN])'.preg_quote($entry['display_number'],'/').'(?![\pL\pN])/u',$subject.' '.$name))$matches[]=$entry['id'];$suggested=count($matches)===1?$matches[0]:'';
                    try{$docs->upload($name,$file['bytes'],['title'=>$name,'kind'=>'receipt','document_date'=>$date,'scope'=>'finance','notes'=>$notes,'links'=>[]],substr(hash('sha256','invoice-mail:'.$a['club_id'].':'.$hash),0,32),function($id)use($a,$hash,$sender,$subject,$date,$uid,$batch,$suggested,$r,$mail,$config){$this->original($r,$mail,(int)$batch['uidvalidity'],$id);$this->q('INSERT INTO invoice_mail_imports(club_id,content_hash,document_id,sender,subject,received_at,imported_at,message_uid,suggested_entry,mailbox_key,message_id,original_date,mailbox_label) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$hash,$id,$sender,$subject,$date,gmdate('c'),$batch['uidvalidity'].':'.$uid,$suggested,$r['mailbox_key'],mb_substr((string)($mail['message_id']??''),0,500),mb_substr((string)($mail['original_date']??''),0,200),$config['email'].' · '.$config['folder']]);});}catch(\Throwable $storeError){throw new \RuntimeException('Dokument konnte nicht gespeichert werden.',0,$storeError);}++$result['new'];$this->event($r,'imported',$eventMail,$name);
                }catch(DomainException){++$result['failed'];$this->event($r,'failed',$eventMail,(string)($file['name']??''),'Anhang nicht übernommen: Format, Größe oder Dokumentangaben prüfen. Original bleibt im Postfach.');}}
                $hasDocument=$this->q('SELECT 1 FROM invoice_mail_original_links WHERE club_id=? AND mailbox_key=? AND message_uid=? LIMIT 1',[$a['club_id'],$r['mailbox_key'],$eventMail['uid']])->fetchColumn();
                if(!$hasDocument&&!$purgedAttachment&&empty($mail['error'])){
                    $this->db->beginTransaction();try{
                        if($this->q('SELECT 1 FROM invoice_mailboxes WHERE club_id=? AND version=? AND lock_until=? AND lock_until>?',[$a['club_id'],$r['version'],$lease,time()])->fetchColumn()===false)throw new DomainException('Abruf abgelaufen.');
                        $this->original($r,$mail,(int)$batch['uidvalidity'],null);
                        $insert=$this->q('INSERT IGNORE INTO invoice_mail_only(club_id,id,mailbox_key,message_uid,sender,subject,received_at,imported_at) VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],bin2hex(random_bytes(16)),$r['mailbox_key'],$eventMail['uid'],mb_substr((string)($mail['sender']??''),0,254),mb_substr((string)($mail['subject']??''),0,200),substr((string)($mail['date']??''),0,10),gmdate('c')]);
                        if($insert->rowCount()===1){++$result['mail_only'];$this->event($r,'mail_only',$eventMail,'','Originalmail ohne übernommenen Beleg gespeichert. Nachricht lesen und manuell prüfen; keine Rechnung oder Buchung erzeugt.');}
                        $this->db->commit();
                    }catch(\Throwable $e){$this->db->rollBack();throw $e;}
                }
                if(!empty($mail['error'])){++$result['failed'];$this->event($r,'failed',$eventMail,'','Nachricht zu groß oder MIME-Struktur/Anhang beschädigt. Original im Postfach manuell prüfen.');}$last=$uid;
            }
            $status=$result['new'].' neu, '.$result['existing'].' bereits vorhanden, '.$result['mail_only'].' E-Mails ohne Beleg, '.$result['failed'].' Anhänge nicht übernommen; '.$result['messages'].' Nachrichten geprüft.';
            if($this->q('UPDATE invoice_mailboxes SET uidvalidity=?,last_uid=?,last_run=?,last_status=? WHERE club_id=? AND version=? AND lock_until=? AND lock_until>?',[(int)$batch['uidvalidity'],$last,gmdate('c'),$status,$a['club_id'],$r['version'],$lease,time()])->rowCount()!==1)throw new DomainException('Abruf konnte wegen einer zwischenzeitlichen Änderung nicht abgeschlossen werden.');return $result+['status'=>'ok'];
        }catch(\Throwable){$this->event($r,'failed',[],'','Abruf nicht abgeschlossen. Zugang und Verbindung prüfen; Cursor bleibt unverändert.');$this->q('UPDATE invoice_mailboxes SET last_run=?,last_status=? WHERE club_id=? AND version=? AND lock_until=?',[gmdate('c'),'Abruf fehlgeschlagen. Server, Passwort und Laufzeit prüfen; keine Nachricht gelöscht.',$a['club_id'],$r['version'],$lease]);throw new DomainException('Rechnungsfach konnte nicht abgerufen werden. Server, Passwort und Laufzeit prüfen.');}
        finally{$this->q('UPDATE invoice_mailboxes SET lock_until=0 WHERE club_id=? AND lock_until=? AND version=?',[$a['club_id'],$lease,$r['version']]);}
    }
    public static function worker(PDO $db,string $key):array{$out=[];foreach($db->query('SELECT configured_by FROM invoice_mailboxes')->fetchAll() as $r){try{$out[]=(new self($db,$r['configured_by'],$key))->fetch();}catch(\Throwable){$out[]=['status'=>'failed'];}}return $out;}
    /** Select only at invoice delivery; an enabled broken adapter must never fall back silently. */
    public static function generalInvoiceSender(PDO $db,string $club,string $key,string $publicUrl,callable $fallback):\Closure{
        return static function(...$args)use($db,$club,$key,$publicUrl,$fallback):void{
            $row=DocumentCatalog::query($db,'SELECT configured_by,config FROM invoice_mailboxes WHERE club_id=?',[$club])->fetch();
            if(!$row||empty(json_decode($row['config'],true,32,JSON_THROW_ON_ERROR)['smtp_enabled'])){$fallback(...$args);return;}
            $sender=(new self($db,$row['configured_by'],$key))->sender($publicUrl);
            if($sender===null)throw new DomainException('Rechnungsversand-Konfiguration inzwischen geändert.');$sender(...$args);
        };
    }
    /** Internal read-only connection data; caller must authorize the owning club first. */
    public static function imapConfiguration(PDO $db,string $club,#[\SensitiveParameter] string $key):array{
        $q=$db->prepare('SELECT * FROM invoice_mailboxes WHERE club_id=?');$q->execute([$club]);$row=$q->fetch();if(!$row)throw new DomainException('Rechnungspostfach fehlt.');
        $data=json_decode($row['config'],true,32,JSON_THROW_ON_ERROR);if(empty($data['enabled']))throw new DomainException('Rechnungspostfach deaktiviert.');
        $data+=(new self($db,$row['configured_by'],$key))->open($row['secrets'],$club);
        return array_intersect_key($data,array_flip(['imap_host','imap_user','imap_password','folder','enabled']));
    }
    /** Trusted purpose resolver, returns secrets only to a server-side SMTP adapter. */
    public static function displayName(PDO $db,string $club,array $data):string
    {
        if(is_string($data['smtp_name']??null)&&$data['smtp_name']!=='')return $data['smtp_name'];
        $lock=$db->inTransaction()?' FOR SHARE NOWAIT':'';
        $payload=DocumentCatalog::query($db,"SELECT payload FROM club_settings WHERE club_id=? AND section='club'".$lock,[$club])->fetchColumn();
        if(is_string($payload)){
            $values=json_decode($payload,true,64,JSON_THROW_ON_ERROR);
            if(is_string($values['name']??null)&&$values['name']!=='')return $values['name'];
        }
        $name=DocumentCatalog::query($db,'SELECT name FROM clubs WHERE id=?'.$lock,[$club])->fetchColumn();
        return is_string($name)?$name:'';
    }
    public static function smtpConfiguration(PDO $db,string $club,#[\SensitiveParameter] string $key):array
    {
        $q=$db->prepare('SELECT * FROM invoice_mailboxes WHERE club_id=?'.($db->inTransaction()?' FOR SHARE NOWAIT':''));$q->execute([$club]);$r=$q->fetch();
        if(!$r)throw new DomainException('Rechnungspostfach ist nicht eingerichtet.');$d=self::config($r);
        if(empty($d['smtp_enabled']))throw new DomainException('SMTP im Rechnungspostfach ist nicht aktiviert.');
        $service=new self($db,$r['configured_by'],$key);$secrets=$service->open($r['secrets'],$club);
        if(($secrets['smtp_password']??'')==='')throw new DomainException('Rechnungspostfach-Passwort fehlt.');
        $senderName=self::displayName($db,$club,$d);self::assertSenderName($senderName);
        return ['host'=>$d['smtp_host'],'port'=>(int)$d['smtp_port'],'user'=>$d['smtp_user'],'password'=>$secrets['smtp_password'],'from'=>$d['email'],'name'=>$senderName,'reply'=>$d['email']];
    }
    public function sender(string $publicUrl):?\Closure{$a=$this->access(false);$r=$this->row($a['club_id']);if(!$r)return null;$d=self::config($r);if(empty($d['smtp_enabled']))return null;$d['smtp_name']=self::displayName($this->db,$a['club_id'],$d);self::assertSenderName($d['smtp_name']);$d+=$this->open($r['secrets'],$a['club_id']);return InvoiceMailboxTransport::sender($d,$publicUrl);}
}
