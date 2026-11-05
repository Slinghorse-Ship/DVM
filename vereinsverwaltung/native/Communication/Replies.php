<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use Kulturhus\Native\Integration\MetaRejected;
use Kulturhus\Native\Integration\StadtLandFunk\{Connection, ReplyRejected};

/** Private comment drafts and durable explicit sends. An ambiguous write is never replayed. */
final class Replies
{
    private ReplyProvider $meta;
    private ReplyProvider $personal;
    private MessageReplyProvider $messages;
    public function __construct(private PDO $db, private SecretBox $box, ?callable $http = null, ?Connection $personal = null)
    {
        $this->meta = new MetaReplyProvider($db, $box, $http);
        $this->personal = new PersonalReplyProvider($db, $personal ?? new Connection($db, $box));
        $this->messages = new MessageReplyProvider($db, $box, $http);
    }
    private function provider(string $channel, string $kind = 'comment'): ReplyProvider
    {
        if ($kind === 'message') {
            if (!in_array($channel,['facebook','instagram'],true)) throw new DomainException('Nachrichtenkanal prüfen.');
            return $this->messages;
        }
        return match ($channel) {
            'facebook', 'instagram' => $this->meta,
            'stadtlandfunk' => $this->personal,
            default => throw new DomainException('Unbekannter Antwortkanal.'),
        };
    }

    public function source(Access $access, string $id): array
    {
        $access->require("social", "manage");
        Content::id($id);
        $row = Db::run(
            $this->db,
            "SELECT * FROM native_social_attention WHERE club_id=? AND id=?" .
                ($this->db->inTransaction() ? " FOR SHARE" : ""),
            [$access->club(), $id],
        )->fetch();
        if (
            !$row ||
            !in_array($row["kind"], ['comment','message'], true) ||
            ($row['kind'] === 'message' && $row['channel'] === 'stadtlandfunk') ||
            !in_array($row["channel"], ["facebook", "instagram", "stadtlandfunk"], true)
        ) {
            throw new DomainException(
                "Antwortentwürfe sind hier für abgeglichene Facebook-/Instagram-/StadtLand.Funk-Kommentare verfügbar.",
            );
        }
        $row["payload"] = $this->box->open(
            "social-attention:" . $access->club() . ":" . $id,
            $row["ciphertext"],
        );
        unset($row["ciphertext"]);
        return $row;
    }

    private function account(Access $access, array $source, ?string $binding = null): array
    {
        return $this->provider($source["channel"], self::kind($source))->account($access, $source, $binding);
    }
    private static function kind(array $row): string { return $row['kind'] ?? $row['payload']['kind'] ?? 'comment'; }
    private function messageAttention(Access $access,string $thread):string
    {
        $conversation=$this->messages->conversation($access,$thread);
        $incoming=array_values(array_filter($conversation['payload']['messages'],static fn($message)=>$message['incoming']));
        if (!$incoming) throw new DomainException('Keine eingehende Originalnachricht.');
        $last=end($incoming);
        return substr(hash('sha256',$access->club().':'.$conversation['channel'].':message:'.$conversation['payload']['conversation'].':'.$last['id']),0,32);
    }
    public function messageSource(Access $access,string $thread):array
    {
        return $this->source($access,$this->messageAttention($access,$thread));
    }
    /** Read retained message/mail history after expiry or disconnection; never for sending. */
    public function messageOriginal(Access $access,string $thread):array
    {
        $id=$this->messageAttention($access,$thread);
        try{return $this->source($access,$id);}catch(DomainException $error){
            $archive=Db::run($this->db,"SELECT id FROM native_social_originals WHERE club_id=? AND source_type='attention' AND source_id=? AND kind='message' AND channel IN ('facebook','instagram') ORDER BY archived_at DESC,id DESC LIMIT 1",[$access->club(),$id])->fetchColumn();
            if(!$archive)throw $error;
            $stored=(new OriginalArchive($this->db,$this->box))->get($access,$archive);$row=$stored['original'];
            $row['payload']=$this->box->open('social-attention:'.$access->club().':'.$id,$row['ciphertext']);unset($row['ciphertext']);return $row+['_archived'=>true,'_archive_id'=>$archive,'_history_connection'=>$stored['source_context']['connection_id']??null];
        }
    }
    public function forSource(Access $access,array $source,string $connection):?array
    {
        $access->require('social','manage');
        $id=Db::run($this->db,"SELECT id FROM native_social_replies WHERE club_id=? AND attention_id=? AND source_fingerprint=? AND connection_id=? AND reply_slot=''",[$access->club(),$source['id'],$source['fingerprint'],$connection])->fetchColumn();
        return $id?$this->get($access,$id):null;
    }

    /** Read-only history binding; never grants current provider or send authority. */
    public function retainedConnection(Access $access,array $source):?string
    {
        $access->require('social','manage');
        if(!empty($source['_archived'])&&is_string($source['_history_connection']??null)&&preg_match('/\A[a-f0-9]{32,64}\z/D',$source['_history_connection']))return $source['_history_connection'];
        $args=[$access->club(),$source['id'],$source['fingerprint']];
        $rows=Db::run($this->db,'SELECT connection_id FROM native_social_replies WHERE club_id=? AND attention_id=? AND source_fingerprint=? UNION SELECT connection_id FROM native_social_mail_threads WHERE club_id=? AND attention_id=? AND source_fingerprint=? LIMIT 2',[...$args,...$args])->fetchAll(PDO::FETCH_COLUMN);
        return count($rows)===1?$rows[0]:null;
    }

    public function connection(Access $access, string $attention): string
    {
        return $this->account($access, $this->source($access, $attention))["_reply_binding"];
    }

    public function save(
        Access $access,
        string $attention,
        int $sourceVersion,
        string $text,
        string $id = "",
        int $version = 0,
    ): array {
        return $this->store($access,$attention,$sourceVersion,$text,$id,$version,'');
    }

    /** Durable human intent, separate from editable and incoming email slots. */
    public function prepareHuman(Access $access,string $attention,int $sourceVersion,string $text,string $slot,string $draftId='',int $draftVersion=0):array
    {
        if(!preg_match('/\Ah[a-f0-9]{31}\z/D',$slot))throw new DomainException('Menschlichen Sendevorgang neu öffnen.');
        return $draftId!==''?$this->save($access,$attention,$sourceVersion,$text,$draftId,$draftVersion):$this->store($access,$attention,$sourceVersion,$text,'',0,$slot);
    }

    public function sendHuman(Access $access,string $attention,int $sourceVersion,string $text,string $key,string $draftId='',int $draftVersion=0):array
    {
        return (new HumanSends($this->db,$this))->send($access,$attention,$sourceVersion,$text,$key,$draftId,$draftVersion);
    }

    public function humanReferences(Access $access,array $source,string $connection):array
    {
        return (new HumanSends($this->db,$this))->references($access,$source,$connection);
    }

    public function humanAt(Access $access,array $source,string $connection,string $reply):array
    {
        return (new HumanSends($this->db,$this))->at($access,$source,$connection,$reply);
    }
    public function searchHuman(Access $access,array $source,string $connection,string $term,array $originalIds=[]):?array
    {
        return (new HumanSends($this->db,$this))->search($access,$source,$connection,$term,$originalIds);
    }

    public function humanFingerprint(Access $access,string $value,string $purpose):string
    {
        if(!in_array($purpose,['request','text'],true))throw new \LogicException('Human fingerprint purpose missing.');
        return $this->box->fingerprint('social-human-'.$purpose.':'.$access->club().':'.$value);
    }

    /** Internal durable mail command; separate from every editable human draft. */
    public function queueMail(Access $access,string $attention,int $sourceVersion,string $text,string $mail):array
    {
        $access->require('social','publish');Content::id($mail);
        $linked=Db::run($this->db,'SELECT t.attention_id,t.source_fingerprint,t.connection_id FROM native_social_mail_dispatch d JOIN native_social_mail_replies m ON m.club_id=d.club_id AND m.id=d.mail_id JOIN native_social_mail_threads t ON t.club_id=m.club_id AND t.id=m.thread_id WHERE d.club_id=? AND d.mail_id=? AND d.state=\'pending\' AND d.reply_id IS NULL',[$access->club(),$mail])->fetch();
        $source=$this->source($access,$attention);
        if(!$linked||$linked['attention_id']!==$attention||$linked['source_fingerprint']!==$source['fingerprint']||$linked['connection_id']!==$this->connection($access,$attention))throw new DomainException('Mailantwort gehört nicht zum aktuellen Original.');
        return $this->store($access,$attention,$sourceVersion,$text,'',0,$mail);
    }
    private function store(Access $access,string $attention,int $sourceVersion,string $text,string $id,int $version,string $slot):array
    {
        $access->require("social", "manage");
        $access->require("social", "edit");
        $text = Content::originalText($text, 8000, true);
        if (preg_match_all("/./us", $text) > 2000) {
            throw new DomainException(
                "Antwort auf höchstens 2000 Zeichen begrenzen.",
            );
        }
        return Db::atomic($this->db, function () use (
            $access,
            $attention,
            $sourceVersion,
            $text,
            $id,
            $version,
            $slot,
        ) {
            $user = $access->require("social", "edit");
            $access->require("social", "manage");
            $source = $this->source($access, $attention);
            if ((int) $source["version"] !== $sourceVersion) {
                throw new DomainException(
                    "Kommentar inzwischen geändert. Bitte erneut auswählen.",
                );
            }
            $account = $this->account($access, $source);
            if ($source['kind'] === 'message') MessageReplyProvider::validateText($source['channel'],$text);
            $existing = Db::run(
                $this->db,
                "SELECT id,status,version FROM native_social_replies WHERE club_id=? AND attention_id=? AND source_fingerprint=? AND connection_id=? AND reply_slot=? FOR UPDATE",
                [
                    $user["club_id"],
                    $attention,
                    $source["fingerprint"],
                    $account["_reply_binding"],
                    $slot,
                ],
            )->fetch();
            if ($id !== "" || $existing) {
                if ($id === "") {
                    throw new DomainException(
                        "Antwort bereits vorbereitet. Den vorhandenen Entwurf öffnen.",
                    );
                }
                Content::id($id);
                if((new HumanSends($this->db,$this))->installed()&&Db::run($this->db,'SELECT 1 FROM native_social_human_sends WHERE club_id=? AND reply_id=? FOR UPDATE',[$user['club_id'],$id])->fetchColumn())throw new DomainException('Diese ursprüngliche Nachricht gehört bereits zu einem Sendevorgang. Neue Nachricht öffnen.');
                if (
                    !$existing ||
                    $existing["id"] !== $id ||
                    !in_array(
                        $existing["status"],
                        ["draft", "rejected", "blocked"],
                        true,
                    ) ||
                    (int) $existing["version"] !== $version
                ) {
                    throw new DomainException(
                        "Antwort bereits vorbereitet oder Versand begonnen. Den vorhandenen Entwurf prüfen.",
                    );
                }
                $version++;
            } else {
                $id = bin2hex(random_bytes(16));
                $version = 1;
            }
            $data = ["text" => $text, "original" => $source["payload"], 'kind' => $source['kind']];
            Db::run(
                $this->db,
                "INSERT INTO native_social_replies(club_id,id,attention_id,channel,parent_id,connection_id,source_fingerprint,reply_slot,status,version,ciphertext,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),reason=NULL,remote_id=NULL,sent_by=NULL,version=VALUES(version),ciphertext=VALUES(ciphertext),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()",
                [
                    $user["club_id"],
                    $id,
                    $attention,
                    $source["channel"],
                    $source["parent_id"],
                    $account["_reply_binding"],
                    $source["fingerprint"],
                    $slot,
                    $slot===''?'draft':'queued',
                    $version,
                    $this->box->seal(
                        "social-reply:" . $user["club_id"] . ":" . $id,
                        $data,
                    ),
                    $user["id"],
                    $user["id"],
                ],
            );
            return $this->get($access, $id);
        });
    }

    public function get(Access $access, string $id): array
    {
        $actor=$access->require("social", "manage");
        return $this->replyForClub($actor['club_id'],$id);
    }
    private function replyForClub(string $club,string $id):array
    {
        Content::id($id);
        $row = Db::run(
            $this->db,
            "SELECT * FROM native_social_replies WHERE club_id=? AND id=?" .
                ($this->db->inTransaction() ? " FOR UPDATE" : ""),
            [$club, $id],
        )->fetch();
        if (!$row) {
            throw new DomainException("Antwortentwurf nicht gefunden.");
        }
        $row["payload"] = $this->box->open(
            "social-reply:" . $club . ":" . $id,
            $row["ciphertext"],
        );
        unset($row["ciphertext"]);
        return $row;
    }

    public function listing(Access $access): array
    {
        $access->require("social", "manage");
        $rows = Db::run(
            $this->db,
            "SELECT * FROM native_social_replies WHERE club_id=? ORDER BY updated_at DESC,id LIMIT 200",
            [$access->club()],
        )->fetchAll();
        foreach ($rows as &$row) {
            $row["payload"] = $this->box->open(
                "social-reply:" . $access->club() . ":" . $row["id"],
                $row["ciphertext"],
            );
            unset($row["ciphertext"]);
        }
        return $rows;
    }

    /** Metadata only: long mail histories never load all original MIME payloads. */
    public function mailReferences(Access $access,array $source,string $connection,int $limit=5001):array
    {
        $access->require('social','manage');
        if($limit<1||$limit>5001)throw new DomainException('Mailverlaufgrenze prüfen.');
        $where=$source['kind']==='message'?'t.parent_id=?':'t.attention_id=?';
        $rows=Db::run($this->db,"SELECT d.mail_id,d.reply_id,d.state AS delivery_state,d.created_at,r.remote_id FROM native_social_mail_dispatch d JOIN native_social_mail_replies m ON m.club_id=d.club_id AND m.id=d.mail_id JOIN native_social_mail_threads t ON t.club_id=m.club_id AND t.id=m.thread_id LEFT JOIN native_social_replies r ON r.club_id=d.club_id AND r.id=d.reply_id WHERE t.club_id=? AND t.channel=? AND t.kind=? AND $where AND t.connection_id=? ORDER BY d.created_at DESC,d.mail_id DESC LIMIT ".$limit,[$access->club(),$source['channel'],$source['kind'],$source['kind']==='message'?$source['parent_id']:$source['id'],$connection])->fetchAll();
        if(count($rows)>5000)throw new DomainException('Mehr als 5000 Mailantworten; Originale bleiben erhalten.');
        return $rows;
    }

    /** Read only the chosen original; caller cannot substitute a different conversation. */
    public function mailAt(Access $access,array $source,string $connection,string $mail):array
    {
        $actor=$access->require('social','manage');return $this->mailForClub($actor['club_id'],$source,$connection,$mail);
    }
    private function mailForClub(string $club,array $source,string $connection,string $mail):array
    {
        $where=$source['kind']==='message'?'t.parent_id=?':'t.attention_id=?';
        $row=Db::run($this->db,"SELECT d.mail_id,d.reply_id,d.state AS delivery_state,m.ciphertext,d.created_at,t.attention_id,t.parent_id FROM native_social_mail_dispatch d JOIN native_social_mail_replies m ON m.club_id=d.club_id AND m.id=d.mail_id JOIN native_social_mail_threads t ON t.club_id=m.club_id AND t.id=m.thread_id WHERE t.club_id=? AND t.channel=? AND t.kind=? AND $where AND t.connection_id=? AND d.mail_id=?",[$club,$source['channel'],$source['kind'],$source['kind']==='message'?$source['parent_id']:$source['id'],$connection,$mail])->fetch();
        if(!$row)throw new DomainException('Mail gehört nicht zum ursprünglichen Gespräch.');
        if($row['reply_id']){
            $reply=$this->replyForClub($club,$row['reply_id']);
            if($reply['channel']!==$source['channel']||$reply['parent_id']!==$source['parent_id']||$reply['connection_id']!==$connection||($reply['payload']['kind']??'comment')!==$source['kind'])throw new DomainException('Originale Mailantwortbindung prüfen.');
        }else{
            $original=$this->box->open('social-mail-answer:'.$club.':'.$row['mail_id'],$row['ciphertext']);
            try{$text=Mail\ReplyText::extract($original['reply_text']??$original['text']);}catch(DomainException){$text=$original['text'];}
            $reply=['id'=>$row['mail_id'],'attention_id'=>$row['attention_id'],'parent_id'=>$row['parent_id'],'status'=>in_array($row['delivery_state'],['pending','ready'],true)?'queued':$row['delivery_state'],'remote_id'=>null,'reply_slot'=>$row['mail_id'],'created_at'=>$row['created_at'],'updated_at'=>$row['created_at'],'payload'=>['text'=>$text,'kind'=>$source['kind']]];
        }
        $reply['created_at']=$row['created_at'];
        if($reply['status']==='queued'&&$row['delivery_state']==='blocked')$reply['status']='blocked';
        return $reply;
    }
    /** Search the exact visible human text, one MIME original at a time under a current locked grant. */
    public function searchMail(Access $access,array $source,string $connection,string $term,array $originalIds=[]):?array
    {
        $access->require('social','manage');$term=InboxCatalogue::query(['q'=>$term])['q'];
        return Db::atomic($this->db,function()use($access,$source,$connection,$term,$originalIds):?array{
            $actor=$access->require('social','manage');$club=$actor['club_id'];$remote=array_fill_keys($originalIds,true);$count=0;
            foreach($this->mailReferences($access,$source,$connection)as$ref){
                if($ref['remote_id']&&isset($remote[$ref['remote_id']]))continue;
                $answer=$this->mailForClub($club,$source,$connection,$ref['mail_id']);$text=$answer['payload']['text'];
                if(mb_stripos('Ihr E-Mail '.$text,$term)!==false){$access->require('social','manage');return ['id'=>'mail:'.$ref['mail_id'],'text'=>$text];}
                if(++$count%100===0)$access->require('social','manage');
            }
            $access->require('social','manage');return null;
        });
    }

    /** Recent unsynchronized comment answers remain visible immediately. */
    public function mailHistory(Access $access,array $source,string $connection):array
    {
        $out=[];
        foreach(array_reverse($this->mailReferences($access,$source,$connection,40))as$row)$out[]=$this->mailAt($access,$source,$connection,$row['mail_id']);
        return $out;
    }

    public function send(
        Access $access,
        string $id,
        int $version,
        bool $confirmed,
    ): array {
        if (!$confirmed) {
            throw new DomainException(
                "Die angezeigte Antwort ausdrücklich zum Versand bestätigen.",
            );
        }
        if ($this->db->inTransaction()) {
            throw new DomainException(
                "Antwortversand benötigt eine eigene Verbindung.",
            );
        }
        $access->require("social", "manage");
        $access->require("social", "publish");
        $row = $this->get($access, $id);
        $this->mailAuthority($access,$row);
        $club = $row["club_id"];
        $ready=$row['reply_slot']===''?'draft':'queued';
        if ($row["status"] !== $ready || (int) $row["version"] !== $version) {
            throw new DomainException(
                "Antwortfassung geändert oder bereits versendet. Kein erneuter Versand.",
            );
        }
        $source = $this->source($access, $row["attention_id"]);
        if (!hash_equals($source["fingerprint"], $row["source_fingerprint"])) {
            throw new DomainException(
                "Kommentar inzwischen geändert. Neue Antwort vorbereiten.",
            );
        }
        $account = $this->account($access, $source, $row["connection_id"]);
        $this->provider($row["channel"],self::kind($row))->current($access, $account, $source, $row);
        // Commit intent before network writes. A crash leaves a non-replayable sending row.
        Db::atomic($this->db, function () use ($access, $id, $version, $row, $ready) {
            $this->mailAuthority($access,$row);
            $user = $access->require("social", "publish");
            $access->require("social", "manage");
            $locked = $this->get($access, $id);
            $source = $this->source($access, $row["attention_id"]);
            $this->account($access, $source, $row["connection_id"]);
            if (
                $locked["status"] !== $ready ||
                (int) $locked["version"] !== $version ||
                !hash_equals($source["fingerprint"], $row["source_fingerprint"])
            ) {
                throw new DomainException(
                    "Antwort oder Kommentar inzwischen geändert.",
                );
            }
            Db::run(
                $this->db,
                "UPDATE native_social_replies SET status='sending',version=version+1,sent_by=?,ciphertext=?,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",
                [$user["id"],$this->box->seal('social-reply:'.$user['club_id'].':'.$id,$locked['payload']+['sending_at'=>gmdate('Y-m-d H:i:s')]), $user["club_id"], $id],
            );
        });
        $started = false;
        $reference = null;
        try {
            return Db::atomic($this->db, function () use (
                $access,
                $id,
                $row,
                &$started,
                &$reference,
            ) {
                $access->require("social", "publish");
                $access->require("social", "manage");
                $locked = $this->get($access, $id);
                $source = $this->source($access, $row["attention_id"]);
                $account = $this->account(
                    $access,
                    $source,
                    $row["connection_id"],
                );
                if (
                    $locked["status"] !== "sending" ||
                    (int) $locked["version"] !== (int) $row["version"] + 1 ||
                    !hash_equals(
                        $source["fingerprint"],
                        $row["source_fingerprint"],
                    )
                ) {
                    throw new DomainException(
                        "Antwort oder Original inzwischen geändert.",
                    );
                }
                $row['payload'] = $locked['payload'];
                $provider = $this->provider($row['channel'],self::kind($row));
                if (self::kind($row) === 'message') {
                    $provider->current($access,$account,$source,$row);
                    $access->require('social','publish');$access->require('social','manage');
                    $this->account($access,$source,$row['connection_id']);
                }
                $this->mailAuthority($access,$locked);
                $started = true;
                try {
                    $reference = $provider->publish($access, $account, $source, $row);
                    $confirmed = $provider->proof($access, $account, $row, $reference);
                    $status = $confirmed ? "sent" : "uncertain";
                } catch (MetaRejected | ReplyRejected) {
                    $status = "rejected";
                } catch (\Throwable) {
                    $status = "uncertain";
                }
                $this->finish($access, $row, $status, $reference);
                return $this->get($access, $id);
            });
        } catch (\Throwable $error) {
            // Do not erase the durable intent if a provider or local commit is ambiguous.
            Db::run(
                $this->db,
                "UPDATE native_social_replies SET status=?,remote_id=?,reason=?,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=? AND status='sending'",
                [
                    $started ? "uncertain" : "blocked",
                    $reference,
                    $started
                        ? "provider_result_unknown"
                        : "authority_or_source_changed",
                    $club,
                    $id,
                ],
            );
            throw new DomainException(
                $started
                    ? "Anbieterergebnis unbekannt. Antwort nicht erneut versenden; Verlauf prüfen."
                    : "Rechte oder Original geändert. Antwort nicht versendet.",
            );
        }
    }

    private function mailAuthority(Access $access,array $reply):void
    {
        if((new HumanSends($this->db,$this))->assertReply($access,$reply))return;
        if(($reply['reply_slot']??'')!=='')(new Mail\Dispatcher($this->db,$this->box,$this))->assertReply($access,$reply);
    }

    private function finish(
        Access $access,
        array $row,
        string $status,
        ?string $remote,
    ): void {
        Db::run(
            $this->db,
            "UPDATE native_social_replies SET status=?,remote_id=?,reason=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",
            [
                $status,
                $remote,
                $status === "sent"
                    ? null
                    : ($status === "rejected"
                        ? "provider_rejected"
                        : "provider_result_unknown"),
                $access->club(),
                $row["id"],
            ],
        );
        if ($status === "sent") {
            Db::run(
                $this->db,
                "UPDATE native_social_attention SET state='done',version=version+1,updated_by=? WHERE club_id=? AND id=? AND fingerprint=?",
                [
                    $access->user()["id"],
                    $access->club(),
                    $row["attention_id"],
                    $row["source_fingerprint"],
                ],
            );
        }
    }

    /** Read-only provider check. It never creates, retries or guesses a reply. */
    public function reconcile(Access $access, string $id, int $version): array
    {
        $access->require("social", "manage");
        $access->require("social", "publish");
        $row = $this->get($access, $id);
        if (
            !in_array($row["status"], ["sent", "uncertain", "sending"], true) ||
            !$row["remote_id"] ||
            (int) $row["version"] !== $version
        ) {
            throw new DomainException(
                "Keine aktuelle unabhängig prüfbare Antwortkennung vorhanden. Original beim Anbieter prüfen; nicht erneut senden.",
            );
        }
        $source = [
            "channel" => $row["channel"],
            "parent_id" => $row["parent_id"],
            'kind' => self::kind($row),
            'payload' => $row['payload']['original'],
        ];
        $account = $this->account($access, $source, $row["connection_id"]);
        $proof = $this->provider($row["channel"],self::kind($row))->proof($access, $account, $row, $row["remote_id"]);
        return Db::atomic($this->db, function () use (
            $access,
            $row,
            $version,
            $proof,
        ) {
            $access->require("social", "publish");
            $access->require("social", "manage");
            $current = $this->get($access, $row["id"]);
            $this->account($access, $row, $row["connection_id"]);
            if ((int) $current["version"] !== $version) {
                throw new DomainException("Antwort inzwischen geändert.");
            }
            if ($proof && $current["status"] !== "sent") {
                $this->finish($access, $row, "sent", $row["remote_id"]);
            }
            return [
                "reply" => $this->get($access, $row["id"]),
                "confirmed" => $proof,
            ];
        });
    }
}
