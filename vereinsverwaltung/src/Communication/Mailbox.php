<?php declare(strict_types=1);
namespace Kulturhus\Verein\Communication;

use PDO;
use DomainException;
use Kulturhus\Verein\{BoardAccess,ContactArchive,InvoiceMailbox,MailChannelSettings};
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Communication\{Content,Mail\Mime};
use Kulturhus\Native\Delivery\DeliveryNotStarted;
use Kulturhus\Native\Integration\ManagerBridge;

/** Optional shared board mailbox. Original messages and human reply intentions are separate. */
final class Mailbox
{
    private SecretBox $box;
    private string $key;
    private \Closure $reader;
    private \Closure $sender;
    private \Closure $account;
    private bool $realSender;
    private string $threadStamp='';
    private array $threadGroups=[];
    public function __construct(private PDO $db,private string $actor,#[\SensitiveParameter] ?string $serverKey=null,?callable $reader=null,?callable $sender=null,?callable $account=null,private string $mailbox='board')
    {
        self::selection(['mailbox_account'=>$mailbox]);
        $serverKey??=ContactArchive::serverKey();if(strlen($serverKey)<32)throw new DomainException('Privater Postfachschlüssel fehlt.');
        $this->key=hash_hkdf('sha256',$serverKey,32,'dvm-general-mailbox-v1');$this->box=new SecretBox($this->key);
        $this->reader=$reader?\Closure::fromCallable($reader):MailboxTransport::read(...);
        $this->sender=$sender?\Closure::fromCallable($sender):MailboxTransport::send(...);$this->realSender=$sender===null;
        $this->account=$account?\Closure::fromCallable($account):function(string $club)use($serverKey):array{
            $c=MailChannelSettings::resolve($this->db,$club,$serverKey,['board'=>'invitations','invoices'=>'invoices','press'=>'press'][$this->mailbox])['override'];
            if($c===null)throw new DeliveryNotStarted('mail_configuration_missing');
            return ['host'=>$c['host'],'port'=>(int)$c['port'],'username'=>$c['user'],'password'=>$c['password'],'from'=>$c['from'],'reply_to'=>$c['reply']?:$c['from']];
        };
    }
    public const ACCOUNTS=['board'=>'Vorstand','invoices'=>'Rechnungen','press'=>'Presse'];
    public static function selection(array $input):string
    {
        $value=$input['mailbox_account']??'board';if(!is_string($value)||!isset(self::ACCOUNTS[$value]))throw new DomainException('Gemeinsames Postfach auswählen.',422);return $value;
    }
    public function selected():string{return $this->mailbox;}
    private function purpose(string $suffix='',?string $account=null):string{return (($account??$this->mailbox)==='board'?'general-mail':'mail-'.($account??$this->mailbox)).$suffix;}
    /** Current and retained original bindings, scoped before any message decryption. */
    private function bindingScope():array
    {
        $bindings=[];
        foreach($this->mailbox==='board'?['invoices','press']:[$this->mailbox]as$account){$data=$this->config($account)['data'];foreach([...($data['bindings']??[]),$data['binding']]as$binding)if(is_string($binding)&&preg_match('/\A[a-f0-9]{32}\z/D',$binding))$bindings[$binding]=true;}
        $bindings=array_keys($bindings);if(!$bindings)return[$this->mailbox==='board'?'1=1':'1=0',[]];
        return ['binding '.($this->mailbox==='board'?'NOT IN':'IN').' ('.implode(',',array_fill(0,count($bindings),'?')).')',$bindings];
    }
    private function actor():array
    {
        $actor=BoardAccess::require($this->db,$this->actor);
        return array_replace($actor,['manager_actor'=>$actor['id'],'manager_club'=>$actor['club_id'],'id'=>ManagerBridge::actorKey($actor['id'],$actor['club_id']),'club_id'=>ManagerBridge::clubKey($actor['club_id'])]);
    }
    private static function lock(string $club):string{return 'general-mailbox-'.substr(hash('sha256',$club),0,40);}
    private function config(?string $account=null):array
    {
        $a=$this->actor();$purpose=$this->purpose('', $account);$row=Db::run($this->db,"SELECT version,ciphertext FROM native_private_settings WHERE club_id=? AND purpose=?".($this->db->inTransaction()?' FOR SHARE':''),[$a['club_id'],$purpose])->fetch();
        return ['version'=>(int)($row['version']??0),'data'=>$row?$this->box->open($purpose.':'.$a['club_id'],$row['ciphertext']):['enabled'=>false,'email'=>'','imap_host'=>'','imap_user'=>'','folder'=>'INBOX','since'=>gmdate('Y-m-d'),'imap_password'=>'','binding'=>'']];
    }
    public function settings():array
    {
        $config=$this->config();$config['password_set']=$config['data']['imap_password']!=='';
        unset($config['data']['imap_password'],$config['data']['binding'],$config['data']['bindings']);$config['imap_available']=function_exists('imap_open');return $config;
    }
    /** Server-internal credentials for the existing status probe, never page data. */
    public function imapConfiguration():?array
    {
        $data=$this->config()['data'];return $data['enabled']?array_intersect_key($data,array_flip(['imap_host','imap_user','imap_password','folder','since']))+['enabled'=>true]:null;
    }
    public function saveSettings(array $input,int $version):void
    {
        $a=$this->actor();if(!BoardAccess::superuser($this->db,$this->actor))throw new DomainException('Nur der Superuser darf den Postfachzugang ändern.');
        $lock=self::lock($a['club_id']);if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Postfach wird gerade bearbeitet. Erneut versuchen.');
        try{Db::atomic($this->db,function()use($input,$version):void{
            $a=$this->actor();if(!BoardAccess::superuser($this->db,$this->actor))throw new DomainException('Postfachverwaltung nicht mehr freigegeben.');
            $old=$this->config();if($old['version']!==$version)throw new MailboxConflict('Postfach inzwischen geändert. Aktuellen Stand öffnen.');
            if(isset($input['enabled'])&&!in_array($input['enabled'],['0','1'],true))throw new DomainException('Postfachauswahl prüfen.');
            $data=InvoiceMailbox::normalize($input+['enabled'=>false]);$data=array_intersect_key($data,array_flip(['enabled','email','imap_host','imap_user','folder','since']));
            $password=$input['imap_password']??'';if(!is_string($password)||strlen($password)>1000||preg_match('/[\x00\r\n]/',$password))throw new DomainException('Postfachpasswort prüfen.');
            $changed=$data['imap_host']!==$old['data']['imap_host']||$data['imap_user']!==$old['data']['imap_user'];
            if($changed&&$old['data']['imap_password']!==''&&$password===''&&($input['clear_password']??'')!=='1')throw new DomainException('Bei neuem Server oder Benutzer das Passwort erneut eingeben.');
            $data['imap_password']=($input['clear_password']??'')==='1'?'':($password!==''?$password:$old['data']['imap_password']);
            if($data['enabled']&&$data['imap_password']==='')throw new DomainException('Vor dem Aktivieren das Postfachpasswort eintragen.');
            $same=$old['version']>0;foreach(['email','imap_host','imap_user','folder','since']as$key)$same=$same&&$data[$key]===$old['data'][$key];
            $data['binding']=$same?$old['data']['binding']:bin2hex(random_bytes(16));
            $data['bindings']=array_values(array_unique(array_filter([...($old['data']['bindings']??[]),$old['data']['binding'],$data['binding']])));$purpose=$this->purpose();
            Db::run($this->db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),ciphertext=VALUES(ciphertext),updated_by=VALUES(updated_by)",[$a['club_id'],$purpose,$version+1,$this->box->seal($purpose.':'.$a['club_id'],$data),$a['id']]);
        });}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function fetch():array
    {
        if($this->db->inTransaction())throw new DomainException('Postfachabruf benötigt einen eigenen Vorgang.');
        $a=$this->actor();$club=$a['club_id'];$lock=self::lock($club);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Postfach wird bereits abgerufen.');
        $external=[];try{
            $config=$this->config();if(!$config['data']['enabled'])throw new DomainException('Postfachabruf ist ausgeschaltet.');$binding=$config['data']['binding'];
            $cursorPurpose=$this->purpose('-cursor');$cipher=Db::run($this->db,"SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose=?",[$club,$cursorPurpose])->fetchColumn();
            $cursor=$cipher?$this->box->open($cursorPurpose.':'.$club,$cipher):[];
            if(($cursor['binding']??'')!==$binding)$cursor=[];
            $batch=($this->reader)($config['data'],(int)($cursor['uidvalidity']??0),(int)($cursor['last_uid']??0));
            if(!is_int($batch['uidvalidity']??null)||$batch['uidvalidity']<1||!is_array($batch['messages']??null)||!array_is_list($batch['messages'])||count($batch['messages'])>25)throw new DomainException('Postfachergebnis nicht vollständig.');
            return Db::atomic($this->db,function()use($club,$config,$binding,$cursor,$batch,$cursorPurpose,&$external):array{
                $a=$this->actor();$now=$this->config();if($now['version']!==$config['version']||!$now['data']['enabled'])throw new DomainException('Postfach oder Berechtigung inzwischen geändert.');
                $last=$batch['uidvalidity']===($cursor['uidvalidity']??0)?($cursor['last_uid']??0):0;$new=0;$existing=0;$bytes=0;
                foreach($batch['messages']as$entry){
                    if(!is_array($entry)||!is_int($entry['uid']??null)||$entry['uid']<=$last||!is_string($entry['original']??null))throw new DomainException('Originalnachrichten oder Abrufreihenfolge prüfen.');
                    $last=$entry['uid'];$raw=$entry['original'];$bytes+=strlen($raw);if($bytes>Mime::MAX_BATCH_BYTES)throw new DomainException('Postfachergebnis überschreitet 40 MB.');
                    $envelope=Mime::mailbox($raw);$hash=hash('sha256',$raw);
                    $old=Db::run($this->db,'SELECT original_hash FROM native_mailbox_messages WHERE club_id=? AND binding=? AND uidvalidity=? AND message_uid=? FOR UPDATE',[$club,$binding,$batch['uidvalidity'],$last])->fetchColumn();
                    if($old!==false){if(!hash_equals($old,$hash))throw new DomainException('Ein ursprünglicher Mailserver-Bezug enthält andere Bytes. Abruf gestoppt.');++$existing;continue;}
                    $id=bin2hex(random_bytes(16));$original=MailboxOriginalStorage::seal($raw,$this->key,$club,$id,$hash);$external[$id]=$original;
                    Db::run($this->db,"INSERT INTO native_mailbox_messages VALUES(?,?,?,?,?,?,?,?,'active',NULL,1,UTC_TIMESTAMP(6))",[$club,$id,$binding,$batch['uidvalidity'],$last,$hash,$this->box->seal('general-mail-message:'.$club.':'.$id,$envelope),$original]);++$new;
                }
                $cursor=['binding'=>$binding,'uidvalidity'=>$batch['uidvalidity'],'last_uid'=>$last];
                Db::run($this->db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,?,1,?,?) ON DUPLICATE KEY UPDATE version=version+1,ciphertext=VALUES(ciphertext),updated_by=VALUES(updated_by)",[$club,$cursorPurpose,$this->box->seal($cursorPurpose.':'.$club,$cursor),$a['id']]);
                return ['new'=>$new,'existing'=>$existing];
            });
        }catch(\Throwable $error){foreach($external as$id=>$cipher)try{if(!Db::run($this->db,'SELECT 1 FROM native_mailbox_messages WHERE club_id=? AND id=?',[$club,$id])->fetchColumn())MailboxOriginalStorage::discard($cipher,$this->key,$club,$id);}catch(\Throwable){}throw $error;}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function get(string $id,bool $lock=false):array
    {
        Content::id($id);$a=$this->actor();[$scope,$bindings]=$this->bindingScope();$row=Db::run($this->db,'SELECT club_id,id,binding,original_hash,envelope_cipher,state,assigned_to,version,received_at FROM native_mailbox_messages WHERE club_id=? AND id=? AND '.$scope.($lock?' FOR UPDATE':''),[$a['club_id'],$id,...$bindings])->fetch();
        if(!$row)throw new DomainException('Nachricht nicht verfügbar.');$row['envelope']=$this->box->open('general-mail-message:'.$a['club_id'].':'.$id,$row['envelope_cipher']);unset($row['envelope_cipher']);
        if(($row['envelope']['from']??'')==='Unbekannter Absender')$row['envelope']=Mime::mailbox($this->openOriginal($row));return $row;
    }
    public function original(string $id):string
    {
        return $this->openOriginal($this->get($id));
    }
    private function openOriginal(array $row):string
    {
        $id=$row['id'];$cipher=Db::run($this->db,'SELECT original_cipher FROM native_mailbox_messages WHERE club_id=? AND id=?',[$row['club_id'],$id])->fetchColumn();
        if(!is_string($cipher)||strlen($cipher)<40)throw new DomainException('Originalnachricht beschädigt.');
        $raw=MailboxOriginalStorage::open($cipher,$this->key,$row['club_id'],$id,$row['original_hash']);$this->actor();return $raw;
    }
    public static function page(mixed $value):int
    {
        if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$value)||(int)$value>1000000)throw new DomainException('Postfachseite prüfen.');return (int)$value;
    }
    public function formatted(string $id,bool $remoteImages=false):string
    {
        $raw=$this->original($id);$files=null;
        $image=function(string $cid)use($raw,$id,&$files):string{$files??=Mime::attachments($raw);foreach($files as$file)if($file['cid']===$cid&&$file['preview']!=='')return '?'.http_build_query(['view'=>'messages','tab'=>'inbox','mailbox_account'=>$this->mailbox,'mail'=>$id,'mailbox_attachment'=>(string)$file['part'],'mailbox_inline'=>'1'],'','&',PHP_QUERY_RFC3986);return '';};
        return MailboxHtml::document($raw,$remoteImages,$image);
    }
    public function attachments(string $id):array
    {
        $files=Mime::attachments($this->original($id));foreach($files as&$file)unset($file['bytes']);unset($file);return $files;
    }
    public function attachment(string $id,int $part):array
    {
        foreach(Mime::attachments($this->original($id))as$file)if($file['part']===$part)return $file;
        throw new DomainException('Anhang nicht verfügbar.',404);
    }
    public function listing(string $state='active',string $assigned='all',int $page=1,string $query=''):array
    {
        $a=$this->actor();$page=self::page($page);$query=self::searchQuery($query);if(!in_array($state,['active','archived','trashed','all'],true)||!in_array($assigned,['all','mine','unassigned'],true))throw new DomainException('Postfachfilter prüfen.');
        [$scope,$bindings]=$this->bindingScope();$where='club_id=? AND '.$scope;$args=[$a['club_id'],...$bindings];if($state!=='all'){$where.=' AND state=?';$args[]=$state;}
        if($assigned==='mine'){$where.=' AND assigned_to=?';$args[]=$a['id'];}elseif($assigned==='unassigned')$where.=' AND assigned_to IS NULL';
        return Db::atomic($this->db,function()use($where,$args,$page,$query):array{
            $sources=Db::run($this->db,'SELECT id,received_at FROM native_mailbox_messages WHERE '.$where.' ORDER BY received_at DESC,id DESC',$args)->fetchAll();
            if($sources)$this->conversationIds($sources[0]['id']);$groups=[];
            $replies=Db::run($this->db,'SELECT message_id,COUNT(*) AS total,MAX(updated_at) AS stamp FROM native_mailbox_replies WHERE club_id=? GROUP BY message_id',[$this->actor()['club_id']])->fetchAll();$replyCounts=array_column($replies,'total','message_id');$replyStamps=array_column($replies,'stamp','message_id');
            foreach($sources as$source){$ids=$this->threadGroups[$source['id']]??[$source['id']];$key=min($ids);if(isset($groups[$key]))continue;$group=['id'=>$source['id'],'ids'=>$ids,'stamp'=>$source['received_at'],'count'=>count($ids)];foreach($ids as$id){$group['count']+=(int)($replyCounts[$id]??0);$group['stamp']=max($group['stamp'],$replyStamps[$id]??'');}$groups[$key]=$group;}
            if($query!==''){$matches=$this->searchMatches($where,$args,$query);$groups=array_filter($groups,static fn(array $group):bool=>(bool)array_intersect_key($matches,array_flip($group['ids'])));}
            usort($groups,static fn($a,$b)=>[$b['stamp'],$b['id']]<=>[$a['stamp'],$a['id']]);$total=count($groups);$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
            $rows=[];foreach(array_slice($groups,($page-1)*6,6)as$group)$rows[]=$this->get($group['id'])+['conversation_ids'=>$group['ids'],'conversation_count'=>$group['count'],'conversation_updated_at'=>$group['stamp']];$this->actor();return compact('rows','total','pages','page');
        });
    }
    public static function searchQuery(mixed $query):string
    {
        if(!is_string($query)||strlen($query)>800||!mb_check_encoding($query,'UTF-8')||mb_strlen($query,'UTF-8')>200||preg_match('/[\x00-\x1f\x7f]/',$query))throw new DomainException('Suchtext mit höchstens 200 Zeichen eingeben.',422);
        return trim($query);
    }
    /** Search scoped encrypted envelopes in bounded batches, never create a plaintext index. */
    private function searchMatches(string $where,array $args,string $query):array
    {
        $club=$this->actor()['club_id'];$matches=[];$cursor='';
        $contains=static fn(string $value):bool=>mb_stripos($value,$query,0,'UTF-8')!==false;
        do{
            $batch=Db::run($this->db,'SELECT id,envelope_cipher FROM native_mailbox_messages WHERE '.$where.' AND id>? ORDER BY id LIMIT 100',[...$args,$cursor])->fetchAll();
            foreach($batch as$row){
                $cursor=$row['id'];$envelope=$this->box->open('general-mail-message:'.$club.':'.$cursor,$row['envelope_cipher']);
                if(($envelope['from']??'')==='Unbekannter Absender')$envelope=$this->get($cursor)['envelope'];
                foreach(['from','subject','text']as$field)if($contains((string)($envelope[$field]??''))){$matches[$cursor]=true;break;}
                // The stored excerpt is deliberately short. Search the bounded original body too.
                if(!isset($matches[$cursor])&&str_ends_with($envelope['text']??'','…')){
                    try{$body=Mime::htmlMessage($this->original($cursor));$text=$body['text']??\Kulturhus\Native\Shared\RichText::plain($body['html']??'');if($contains($text))$matches[$cursor]=true;}catch(DomainException){/* Metadata remains searchable for unsupported original parts. */}
                }
            }
        }while(count($batch)===100);
        $cursor='';
        do{
            $batch=Db::run($this->db,'SELECT r.id,r.message_id,r.ciphertext FROM native_mailbox_replies r JOIN (SELECT id FROM native_mailbox_messages WHERE '.$where.') selected ON selected.id=r.message_id WHERE r.club_id=? AND r.id>? ORDER BY r.id LIMIT 100',[...$args,$club,$cursor])->fetchAll();
            foreach($batch as$row){$cursor=$row['id'];$payload=$this->box->open('general-mail-reply:'.$club.':'.$cursor,$row['ciphertext']);foreach(['to','subject','text']as$field)if($contains((string)($payload[$field]??''))){$matches[$row['message_id']]=true;break;}}
        }while(count($batch)===100);
        $this->actor();return$matches;
    }
    /** Folder totals use the same own-account scope and selected responsibility filter as the list. */
    public function counts(string $assigned='all'):array
    {
        $a=$this->actor();if(!in_array($assigned,['all','mine','unassigned'],true))throw new DomainException('Postfachfilter prüfen.');[$scope,$bindings]=$this->bindingScope();$where='club_id=? AND '.$scope;$args=[$a['club_id'],...$bindings];if($assigned==='mine'){$where.=' AND assigned_to=?';$args[]=$a['id'];}elseif($assigned==='unassigned')$where.=' AND assigned_to IS NULL';$out=['active'=>0,'archived'=>0,'trashed'=>0];foreach(Db::run($this->db,'SELECT state,COUNT(*) AS total FROM native_mailbox_messages WHERE '.$where.' GROUP BY state',$args)as$row)if(array_key_exists($row['state'],$out))$out[$row['state']]=(int)$row['total'];return$out;
    }
    public function people():array
    {
        $a=$this->actor();$rows=Db::run($this->db,"SELECT a.id,COALESCE(NULLIF(c.name,''),a.id) AS name FROM actors a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.active=1 AND a.role<>'editor' AND (a.is_superuser=1 OR EXISTS(SELECT 1 FROM admission_board b WHERE b.club_id=a.club_id AND b.actor_id=a.id AND b.enabled=1)) ORDER BY name,a.id",[$a['manager_club']])->fetchAll();
        $rows=array_values(array_filter($rows,fn(array $row):bool=>BoardAccess::allowed($this->db,$row['id'])));
        $rows=\Kulturhus\Verein\ActorNames::rows($this->db,$a['manager_club'],$rows);
        return array_map(static fn(array $row):array=>['id'=>ManagerBridge::actorKey($row['id'],$a['manager_club']),'name'=>$row['name']],$rows);
    }
    public function change(string $id,int $version,string $assigned,string $state):array
    {
        if(!in_array($state,['active','archived','trashed'],true))throw new DomainException('Nachrichtenstatus prüfen.');
        return Db::atomic($this->db,function()use($id,$version,$assigned,$state):array{
            $row=$this->get($id,true);$a=$this->actor();if((int)$row['version']!==$version)throw new MailboxConflict('Nachricht inzwischen bearbeitet. Aktuellen Stand öffnen.');
            if($assigned!==''&&$assigned!==($row['assigned_to']??'')&&!in_array($assigned,array_column($this->people(),'id'),true))throw new DomainException('Eine aktuell berechtigte Person auswählen.');
            if(($row['assigned_to']??'')===$assigned&&$row['state']===$state)return $row;
            $event=['before'=>['assigned'=>$row['assigned_to'],'state'=>$row['state']],'after'=>['assigned'=>$assigned?:null,'state'=>$state]];
            Db::run($this->db,'UPDATE native_mailbox_messages SET assigned_to=?,state=?,version=version+1 WHERE club_id=? AND id=?',[$assigned?:null,$state,$a['club_id'],$id]);
            Db::run($this->db,'INSERT INTO native_mailbox_events VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))',[$a['club_id'],$id,$version+1,$a['id'],$this->box->seal('general-mail-event:'.$a['club_id'].':'.$id.':'.($version+1),$event)]);return $this->get($id);
        });
    }
    public function reply(string $id):array
    {
        Content::id($id);$a=$this->actor();$row=Db::run($this->db,'SELECT * FROM native_mailbox_replies WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$row)throw new DomainException('Antwort nicht verfügbar.');
        $this->get($row['message_id']);$row['payload']=$this->box->open('general-mail-reply:'.$a['club_id'].':'.$id,$row['ciphertext']);unset($row['ciphertext']);return $row;
    }
    private function replyContext(string $id):array
    {
        $row=$this->get($id);$config=$this->config()['data'];
        if(!$config['enabled']||!in_array($row['state'],['active','archived'],true)||$row['binding']!==$config['binding'])throw new DomainException('Postfach ausgeschaltet, geändert oder Nachricht im Papierkorb.');
        if($row['envelope']['reply_to']==='')throw new DomainException('Antwortadresse nicht eindeutig. Originalmail prüfen.');
        $account=($this->account)($this->actor()['manager_club']);if(strtolower($account['from'])!==strtolower($config['email']))throw new DomainException('Das Versandpostfach für Vereinsnachrichten muss dieselbe Adresse wie das empfangende Postfach verwenden.');
        return [$row,$account];
    }
    public function saveReply(string $message,string $id,int $version,string $text,?string $html=null):array
    {
        Content::id($id);if($html!==null){$html=MailboxHtml::reply($html);/* The active rich editor owns one body; the hidden no-JS text projection is not another human decision. */$text=\Kulturhus\Native\Shared\RichText::plain($html);}$text=str_replace(["\r\n","\r"],"\n",$text);$text=Content::originalText($text,80000,true);if(mb_strlen($text,'UTF-8')>20000)throw new DomainException('Die Antwort auf 20.000 Zeichen begrenzen.');
        $html??='<div style="white-space:pre-wrap">'.htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div>';
        return Db::atomic($this->db,function()use($message,$id,$version,$text,$html):array{
            [$source,$account]=$this->replyContext($message);$a=$this->actor();
            $old=Db::run($this->db,'SELECT id FROM native_mailbox_replies WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id])->fetchColumn();$old=$old?$this->reply($id):null;
            if($old&&$old['message_id']!==$message)throw new DomainException('Antwort gehört zu einer anderen Nachricht.');
            if($old&&(int)$old['version']!==$version){if($version===0&&$old['payload']['text']===$text&&($old['payload']['html']??'')===$html&&$old['payload']['original_hash']===$source['original_hash'])return $old;throw new MailboxConflict('Antwort inzwischen geändert. Deine Eingaben bleiben erhalten.');}
            if(($old&&!in_array($old['state'],['draft','blocked'],true))||(!$old&&$version!==0))throw new DomainException('Versand bereits begonnen oder Antwortfassung geändert.');
            $subject=$source['envelope']['subject'];if(!preg_match('/^re:/i',$subject))$subject='Re: '.$subject;$subject=mb_strcut($subject,0,320,'UTF-8');
            $payload=['to'=>$source['envelope']['reply_to'],'subject'=>$subject,'text'=>$text,'html'=>$html,'message_id'=>$old['payload']['message_id']??'<'.bin2hex(random_bytes(24)).'@'.substr(strrchr($account['from'],'@'),1).'>','parent_id'=>$source['envelope']['message_id'],'original_hash'=>$source['original_hash'],'binding'=>$source['binding'],'account_hash'=>$this->box->fingerprint(json_encode($account,JSON_THROW_ON_ERROR))];
            MailboxTransport::compose($account,$payload);
            Db::run($this->db,"INSERT INTO native_mailbox_replies VALUES(?,?,?,?, 'draft',?,?,NULL,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),state='draft',ciphertext=VALUES(ciphertext),updated_at=UTC_TIMESTAMP(6)",[$a['club_id'],$id,$message,$version+1,$this->box->seal('general-mail-reply:'.$a['club_id'].':'.$id,$payload),$a['id']]);return $this->reply($id);
        });
    }
    public function send(string $id,int $version,bool $confirm):array
    {
        if(!$confirm)throw new DomainException('Die genaue Antwort und Empfängeradresse bewusst bestätigen.');
        if($this->db->inTransaction())throw new DomainException('Antwortversand benötigt einen eigenen Vorgang.');
        $before=$this->reply($id);if(in_array($before['state'],['accepted','sending','uncertain'],true))return $before;
        if((int)$before['version']!==$version||!in_array($before['state'],['draft','blocked'],true))throw new MailboxConflict('Antwort geändert. Die tatsächlich gespeicherte Fassung erneut öffnen.');
        [$source,$account]=$this->replyContext($before['message_id']);$payload=$before['payload'];
        if(!hash_equals($payload['account_hash'],$this->box->fingerprint(json_encode($account,JSON_THROW_ON_ERROR)))||$payload['original_hash']!==$source['original_hash'])throw new DomainException('Versandpostfach oder Original geändert. Antwort erneut prüfen und speichern.');
        MailboxTransport::compose($account,$payload);if($this->realSender)MailboxTransport::ready($account);
        Db::atomic($this->db,function()use($id,$version):void{
            $a=$this->actor();Db::run($this->db,'SELECT id FROM native_mailbox_replies WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id]);$row=$this->reply($id);$this->replyContext($row['message_id']);
            if((int)$row['version']!==$version||!in_array($row['state'],['draft','blocked'],true))throw new MailboxConflict('Antwort geändert oder Versand bereits begonnen.');
            Db::run($this->db,"UPDATE native_mailbox_replies SET state='sending',version=version+1,sent_by=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[$a['id'],$a['club_id'],$id]);
        });
        $started=false;
        try{return Db::atomic($this->db,function()use($id,$version,$payload,&$started):array{
            $a=$this->actor();Db::run($this->db,'SELECT id FROM native_mailbox_replies WHERE club_id=? AND id=? FOR UPDATE',[$a['club_id'],$id]);$row=$this->reply($id);
            if($row['state']!=='sending'||(int)$row['version']!==$version+1)throw new DomainException('Versand bereits bearbeitet.');
            [$source,$account]=$this->replyContext($row['message_id']);if(!hash_equals($payload['account_hash'],$this->box->fingerprint(json_encode($account,JSON_THROW_ON_ERROR))))throw new DomainException('Versandpostfach inzwischen geändert.');
            try{$decoded=Mime::htmlMessage($this->openOriginal($source));$quotedText=$decoded['text']??\Kulturhus\Native\Shared\RichText::plain($decoded['html']??'');}catch(DomainException){$quotedText=$source['envelope']['text'];}
            $wire=$payload+['quote'=>['from'=>$source['envelope']['from'],'subject'=>$source['envelope']['subject'],'received_at'=>$source['received_at'],'text'=>$quotedText!==''?$quotedText:$source['envelope']['text']],'references'=>$source['envelope']['thread_references']??[]];
            MailboxTransport::compose($account,$wire);$started=true;$result=($this->sender)($account,$wire);
            if(!is_string($result)||!hash_equals($payload['message_id'],$result))throw new DomainException('SMTP-Annahme nicht eindeutig bestätigt.');
            Db::run($this->db,"UPDATE native_mailbox_replies SET state='accepted',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[$a['club_id'],$id]);return $this->reply($id);
        });}catch(\Throwable $error){
            $a=$this->actor();$current=$this->reply($id);
            if($current['state']==='accepted'&&$current['payload']===$payload)return $current;
            $state=!$started||$error instanceof DeliveryNotStarted?'blocked':'uncertain';
            Db::run($this->db,"UPDATE native_mailbox_replies SET state=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=? AND state='sending' AND version=?",[$state,$a['club_id'],$id,$version+1]);return $this->reply($id);
        }
    }
    public function latestReply(string $message):?array
    {
        $source=$this->get($message);$ids=$this->conversationIds($message);$id=Db::run($this->db,'SELECT id FROM native_mailbox_replies WHERE club_id=? AND message_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY updated_at DESC,id DESC LIMIT 1',[$source['club_id'],...$ids])->fetchColumn();return $id===false?null:$this->reply($id);
    }
    private function conversationIds(string $message):array
    {
        $source=$this->get($message);[$scope,$bindings]=$this->bindingScope();$club=$source['club_id'];
        $state=Db::run($this->db,'SELECT COUNT(*),COALESCE(SUM(version),0) FROM native_mailbox_messages WHERE club_id=? AND '.$scope,[$club,...$bindings])->fetch(PDO::FETCH_NUM);
        $replies=Db::run($this->db,'SELECT COUNT(*),COALESCE(SUM(version),0) FROM native_mailbox_replies WHERE club_id=?',[$club])->fetch(PDO::FETCH_NUM);
        $stamp=hash('sha256',json_encode([$club,$scope,$bindings,$state,$replies],JSON_THROW_ON_ERROR));
        if($stamp!==$this->threadStamp){
            $nodes=[];$identities=[];$references=[];$cursor='';
            do{
                $batch=Db::run($this->db,'SELECT id,binding,envelope_cipher FROM native_mailbox_messages WHERE club_id=? AND '.$scope.' AND id>? ORDER BY id LIMIT 100',[$club,...$bindings,$cursor])->fetchAll();
                foreach($batch as$row){
                    $cursor=$row['id'];$envelope=$this->box->open('general-mail-message:'.$club.':'.$cursor,$row['envelope_cipher']);if(($envelope['from']??'')==='Unbekannter Absender')$envelope=$this->get($cursor)['envelope'];$nodes[$cursor]=$row['binding'];
                    if(($envelope['message_id']??'')!=='')$identities[$row['binding']."\0".$envelope['message_id']][]=$cursor;
                    $refs=$envelope['thread_references']??null;
                    if(!is_array($refs))try{$refs=Mime::threadReferences($this->original($cursor));}catch(DomainException){$refs=[];}
                    $references[$cursor]=$refs;
                }
            }while(count($batch)===100);
            $cursor='';
            do{
                $batch=Db::run($this->db,"SELECT id,message_id,ciphertext FROM native_mailbox_replies WHERE club_id=? AND id>? AND state IN ('accepted','sending','uncertain') ORDER BY id LIMIT 100",[$club,$cursor])->fetchAll();
                foreach($batch as$row){$cursor=$row['id'];if(!isset($nodes[$row['message_id']]))continue;$payload=$this->box->open('general-mail-reply:'.$club.':'.$row['id'],$row['ciphertext']);$identities[$nodes[$row['message_id']]."\0".$payload['message_id']][]=$row['message_id'];}
            }while(count($batch)===100);
            $parents=array_combine(array_keys($nodes),array_keys($nodes));
            $root=static function(string $id)use(&$parents):string{while($parents[$id]!==$id){$parents[$id]=$parents[$parents[$id]];$id=$parents[$id];}return $id;};
            foreach($references as$id=>$refs)foreach($refs as$ref){
                if(!is_string($ref))continue;$targets=array_values(array_unique($identities[$nodes[$id]."\0".$ref]??[]));if(count($targets)!==1)continue;
                $a=$root($id);$b=$root($targets[0]);if($a!==$b)$parents[$a]=$b;
            }
            $groups=[];foreach($nodes as$id=>$binding)$groups[$root($id)][]=$id;
            $this->threadGroups=[];foreach($groups as$ids)foreach($ids as$id)$this->threadGroups[$id]=$ids;$this->threadStamp=$stamp;
        }
        return $this->threadGroups[$message]??[$message];
    }
    /** Newest conversation page, including incoming originals and the manager's own replies. */
    public function conversationPage(string $message,int $page=1):array
    {
        $source=$this->get($message);$ids=$this->conversationIds($message);$placeholders=implode(',',array_fill(0,count($ids),'?'));
        $visible="club_id=? AND id IN ($placeholders) AND (state<>'trashed' OR id=?)";$args=[$source['club_id'],...$ids,$message];
        $replyWhere="club_id=? AND message_id IN (SELECT id FROM native_mailbox_messages WHERE $visible)";$replyArgs=[$source['club_id'],...$args];
        $total=(int)Db::run($this->db,"SELECT COUNT(*) FROM native_mailbox_messages WHERE $visible",$args)->fetchColumn()+(int)Db::run($this->db,"SELECT COUNT(*) FROM native_mailbox_replies WHERE $replyWhere",$replyArgs)->fetchColumn();
        $pages=max(1,(int)ceil($total/12));$page=min(self::page($page),$pages);
        $entries=Db::run($this->db,"SELECT id,'incoming' AS direction,received_at AS stamp FROM native_mailbox_messages WHERE $visible UNION ALL SELECT id,'outgoing' AS direction,updated_at AS stamp FROM native_mailbox_replies WHERE $replyWhere ORDER BY stamp DESC,id DESC LIMIT 12 OFFSET ".(($page-1)*12),[...$args,...$replyArgs])->fetchAll();
        $rows=[];foreach($entries as$entry){$row=$entry['direction']==='incoming'?$this->get($entry['id']):$this->reply($entry['id']);$rows[]=$row+['direction'=>$entry['direction'],'updated_at'=>$entry['stamp']];}
        return compact('rows','page','pages','total');
    }
    public function replies(string $message,int $page=1):array
    {
        $source=$this->get($message);$page=self::page($page);$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_mailbox_replies WHERE club_id=? AND message_id=?',[$source['club_id'],$message])->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
        $ids=Db::run($this->db,'SELECT id FROM native_mailbox_replies WHERE club_id=? AND message_id=? ORDER BY updated_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6),[$source['club_id'],$message])->fetchAll(PDO::FETCH_COLUMN);$rows=array_map(fn(string $id):array=>$this->reply($id),$ids);return compact('rows','page','pages','total');
    }
    public function history(string $message,int $page=1):array
    {
        $source=$this->get($message);$page=self::page($page);$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_mailbox_events WHERE club_id=? AND message_id=?',[$source['club_id'],$message])->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
        $rows=Db::run($this->db,'SELECT version,actor_id,ciphertext,happened_at FROM native_mailbox_events WHERE club_id=? AND message_id=? ORDER BY version DESC LIMIT 6 OFFSET '.(($page-1)*6),[$source['club_id'],$message])->fetchAll();foreach($rows as&$row){$row['payload']=$this->box->open('general-mail-event:'.$source['club_id'].':'.$message.':'.$row['version'],$row['ciphertext']);unset($row['ciphertext']);}unset($row);return compact('rows','page','pages','total');
    }
}
