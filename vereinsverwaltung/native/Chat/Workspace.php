<?php declare(strict_types=1);
namespace Kulturhus\Native\Chat;

use DomainException;
use LogicException;
use PDO;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;

final class Workspace
{
    public function __construct(private PDO $db, private Access $access) {}

    public function directory(): array { return $this->access->directory('chat'); }

    public function list(): array
    {
        $actor = $this->access->require('chat', 'read');
        return Db::run($this->db, 'SELECT t.*,p.last_read,
            (SELECT COUNT(*) FROM native_chat_messages m WHERE m.club_id=t.club_id AND m.thread_id=t.id AND m.seq>p.last_read AND m.actor_id<>p.actor_id) unread
            FROM native_chat_threads t JOIN native_chat_people p ON p.club_id=t.club_id AND p.thread_id=t.id
            WHERE t.club_id=? AND p.actor_id=? ORDER BY t.updated_at DESC,t.id', [$actor['club_id'],$actor['id']])->fetchAll();
    }

    public function create(string $title, array $actorIds): array
    {
        $title = self::text($title, 150);
        return Db::atomic($this->db, function () use ($title, $actorIds): array {
            $actor = $this->access->require('chat', 'read');
            $this->access->require('chat', 'edit');
            $people = $this->validatePeople([...$actorIds, $actor['id']]);
            if (count($people) < 2) throw new DomainException('Mindestens zwei Personen auswählen.');
            $id = bin2hex(random_bytes(16));
            Db::run($this->db, 'INSERT INTO native_chat_threads(id,club_id,title,owner_id,created_at,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$id,$actor['club_id'],$title,$actor['id']]);
            foreach ($people as $person) {
                Db::run($this->db, 'INSERT INTO native_chat_people(club_id,thread_id,actor_id,joined_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$actor['club_id'],$id,$person]);
            }
            return $this->get($id);
        });
    }

    public function get(string $id): array { return $this->thread($id, false); }

    /** Internal transaction guard shared with Polls; locks serialize membership and writes. */
    public function locked(string $id, string $capability = 'read'): array
    {
        if (!$this->db->inTransaction()) throw new LogicException('Chat-Sperre benötigt eine Transaktion.');
        $thread = $this->thread($id, true);
        if ($capability !== 'read') $this->access->require('chat', $capability);
        return $thread;
    }

    private function thread(string $id, bool $lock): array
    {
        $actor = $this->access->require('chat', 'read');
        $row = Db::run($this->db, 'SELECT t.*,p.last_read FROM native_chat_threads t
            JOIN native_chat_people p ON p.club_id=t.club_id AND p.thread_id=t.id
            WHERE t.club_id=? AND t.id=? AND p.actor_id=?' . ($lock ? ' FOR UPDATE' : ''), [$actor['club_id'],$id,$actor['id']])->fetch();
        if (!$row) throw new DomainException('Unterhaltung nicht verfügbar.');
        return $row;
    }

    public function people(string $id): array
    {
        $thread = $this->get($id);
        return Db::run($this->db, 'SELECT p.actor_id id,u.name,u.active,p.joined_at
            FROM native_chat_people p JOIN native_users u ON u.id=p.actor_id AND u.club_id=p.club_id
            WHERE p.club_id=? AND p.thread_id=? ORDER BY u.name,u.id', [$thread['club_id'],$id])->fetchAll();
    }

    /** Forward pagination; use the last returned seq for the next page. Bodies are plain text. */
    public function messages(string $id, int $after = 0,int $before=PHP_INT_MAX,int $limit=200): array
    {
        if ($after < 0) throw new DomainException('Ungültiger Nachrichtenstand.');
        $thread = $this->get($id);
        if($before<1||$limit<1||$limit>200)throw new DomainException('Nachrichtenabschnitt prüfen.');
        $rows=Db::run($this->db, 'SELECT m.seq,m.thread_id,m.actor_id,m.body,m.version,m.created_at,m.edited_at,u.name,
            (SELECT p.id FROM native_chat_polls p WHERE p.club_id=m.club_id AND p.thread_id=m.thread_id AND p.message_seq=m.seq) poll_id
            ,r.reply_to,origin.body reply_body,f.name file_name,f.mime file_mime,f.bytes file_bytes
            FROM native_chat_messages m LEFT JOIN native_users u ON u.id=m.actor_id AND u.club_id=m.club_id
            LEFT JOIN native_chat_replies r ON r.club_id=m.club_id AND r.thread_id=m.thread_id AND r.message_seq=m.seq
            LEFT JOIN native_chat_messages origin ON origin.club_id=r.club_id AND origin.thread_id=r.thread_id AND origin.seq=r.reply_to
            LEFT JOIN native_chat_files f ON f.club_id=m.club_id AND f.thread_id=m.thread_id AND f.message_seq=m.seq
            WHERE m.club_id=? AND m.thread_id=? AND m.seq>? AND m.seq<? ORDER BY m.seq LIMIT '.$limit, [$thread['club_id'],$id,$after,$before])->fetchAll();
        $withFiles=array_filter($rows,static fn($row)=>$row['file_name']!==null);
        if($withFiles&&Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_chat_file_extras'")->fetchColumn()){
            $sequences=array_column($withFiles,'seq');$args=[$thread['club_id'],$id,...$sequences];$where=' WHERE club_id=? AND thread_id=? AND message_seq IN ('.implode(',',array_fill(0,count($sequences),'?')).')';
            $extra=Db::run($this->db,'SELECT message_seq,ordinal,name,mime,bytes FROM native_chat_file_extras'.$where.' ORDER BY message_seq,ordinal',$args)->fetchAll();$group=[];
            foreach($extra as$file){$seq=$file['message_seq'];unset($file['message_seq']);$group[$seq][]=$file;}
            foreach($rows as&$row)if($row['file_name']!==null)$row['attachments']=[['ordinal'=>1,'name'=>$row['file_name'],'mime'=>$row['file_mime'],'bytes'=>$row['file_bytes']],...($group[$row['seq']]??[])];unset($row);
        }
        return$rows;
    }

    public function send(string $id, string $body, string $requestKey, ?int $replyTo = null): array
    {
        $body = self::text($body, 5000);
        self::requestKey($requestKey);
        return Db::atomic($this->db, function () use ($id, $body, $requestKey, $replyTo): array {
            $thread = $this->locked($id, 'edit');
            $actor = $this->access->user();
            if ($replyTo !== null) $this->message($thread, $replyTo);
            $hash = self::fingerprint($replyTo === null ? ['message',$body] : ['reply',$body,$replyTo]);
            $existing = $this->request($thread, $requestKey);
            if ($existing) {
                if (!hash_equals($existing['request_hash'], $hash)) throw new Conflict('Anfragekennung bereits mit anderem Inhalt verwendet.');
                return $this->message($thread, (int)$existing['seq']);
            }
            return $this->append($thread, $actor['id'], $body, $requestKey, $hash, $replyTo);
        });
    }

    /** Internal append; callers must first lock this thread in their active transaction. */
    public function append(array $thread, string $actorId, string $body, string $requestKey, string $requestHash, ?int $replyTo = null): array
    {
        // Recheck even for calls from other modules; public methods are never trusted bypasses.
        $this->locked($thread['id'], 'edit');
        $actor = $this->access->user();
        if ($actorId !== $actor['id'] || $thread['club_id'] !== $actor['club_id']) throw new DomainException('Ungültiger Nachrichtenautor.');
        $body = self::text($body, 5000);
        self::requestKey($requestKey);
        if ($replyTo !== null) $this->message($thread, $replyTo);
        Db::run($this->db, 'INSERT INTO native_chat_messages(club_id,thread_id,actor_id,body,request_key,request_hash,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$thread['club_id'],$thread['id'],$actorId,$body,$requestKey,$requestHash]);
        $seq = (int)$this->db->lastInsertId();
        if ($replyTo !== null) Db::run($this->db, 'INSERT INTO native_chat_replies(club_id,thread_id,message_seq,reply_to) VALUES(?,?,?,?)', [$thread['club_id'],$thread['id'],$seq,$replyTo]);
        Db::run($this->db, 'INSERT INTO native_chat_revisions(club_id,thread_id,message_seq,version,body,actor_id,saved_at) VALUES(?,?,?,1,?,?,UTC_TIMESTAMP(6))', [$thread['club_id'],$thread['id'],$seq,$body,$actorId]);
        Db::run($this->db, 'UPDATE native_chat_threads SET updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?', [$thread['club_id'],$thread['id']]);
        Notifications::enqueue($this->db, $thread, $seq, $actorId);
        return $this->message($thread, $seq);
    }

    /** Latest page, or history strictly before a visible sequence. */
    public function history(string $id, int $before = PHP_INT_MAX,int $size=100): array
    {
        $thread = $this->get($id);
        if($before<1||!in_array($size,[20,100],true))throw new DomainException('Nachrichtenabschnitt prüfen.');
        $rows = Db::run($this->db, 'SELECT seq FROM native_chat_messages WHERE club_id=? AND thread_id=? AND seq<? ORDER BY seq DESC LIMIT '.$size, [$thread['club_id'],$id,$before])->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) return [];
        return $this->messages($id,(int)min($rows)-1,$before,$size);
    }

    public function hasHistory(string $id,int $before):bool
    {
        $thread=$this->get($id);return(bool)Db::run($this->db,'SELECT 1 FROM native_chat_messages WHERE club_id=? AND thread_id=? AND seq<? LIMIT 1',[$thread['club_id'],$id,$before])->fetchColumn();
    }

    public function reply(string $id,int $seq):array{return $this->message($this->get($id),$seq);}

    /** Request hashes remain unchanged when messages are edited. */
    public function request(array $thread, string $requestKey): array|false
    {
        $this->get($thread['id']);
        $actor = $this->access->user();
        if ($thread['club_id'] !== $actor['club_id']) throw new DomainException('Unterhaltung nicht verfügbar.');
        return Db::run($this->db, 'SELECT seq,request_hash FROM native_chat_messages WHERE club_id=? AND thread_id=? AND actor_id=? AND request_key=?' . ($this->db->inTransaction() ? ' FOR UPDATE' : ''), [$actor['club_id'],$thread['id'],$actor['id'],$requestKey])->fetch();
    }

    public function edit(string $threadId, int $seq, string $body, int $expectedVersion): array
    {
        $body = self::text($body, 5000);
        return Db::atomic($this->db, function () use ($threadId, $seq, $body, $expectedVersion): array {
            $thread = $this->locked($threadId, 'edit');
            $actor = $this->access->user();
            $message = $this->message($thread, $seq);
            if ($message['actor_id'] !== $actor['id']) throw new DomainException('Nur eigene Nachrichten können geändert werden.');
            if (Db::run($this->db, 'SELECT 1 FROM native_chat_polls WHERE club_id=? AND thread_id=? AND message_seq=?', [$thread['club_id'],$threadId,$seq])->fetchColumn()) throw new DomainException('Veröffentlichte Umfragen sind unveränderlich.');
            if ((int)$message['version'] !== $expectedVersion) throw new Conflict('Nachricht wurde inzwischen geändert. Bitte neu laden.');
            if ($message['body'] === $body) return $message;
            $changed = Db::run($this->db, 'UPDATE native_chat_messages SET body=?,version=version+1,edited_at=UTC_TIMESTAMP(6) WHERE club_id=? AND thread_id=? AND seq=? AND version=?', [$body,$thread['club_id'],$threadId,$seq,$expectedVersion]);
            if ($changed->rowCount() !== 1) throw new Conflict('Nachricht wurde inzwischen geändert.');
            Db::run($this->db, 'INSERT INTO native_chat_revisions(club_id,thread_id,message_seq,version,body,actor_id,saved_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$thread['club_id'],$threadId,$seq,$expectedVersion+1,$body,$actor['id']]);
            return $this->message($thread, $seq);
        });
    }

    public function revisions(string $threadId, int $seq): array
    {
        $thread = $this->get($threadId);
        $this->message($thread, $seq);
        return Db::run($this->db, 'SELECT version,body,actor_id,saved_at FROM native_chat_revisions WHERE club_id=? AND thread_id=? AND message_seq=? ORDER BY version', [$thread['club_id'],$threadId,$seq])->fetchAll();
    }

    private function message(array $thread, int $seq): array
    {
        $row = Db::run($this->db, 'SELECT seq,thread_id,actor_id,body,version,created_at,edited_at FROM native_chat_messages WHERE club_id=? AND thread_id=? AND seq=?' . ($this->db->inTransaction() ? ' FOR UPDATE' : ''), [$thread['club_id'],$thread['id'],$seq])->fetch();
        if (!$row) throw new DomainException('Nachricht nicht verfügbar.');
        return $row;
    }

    public function markRead(string $id, int $through): void
    {
        if ($through < 0) throw new DomainException('Ungültiger Lesestand.');
        Db::atomic($this->db, function () use ($id, $through): void {
            $thread = $this->locked($id);
            $maximum = (int)Db::run($this->db, 'SELECT seq FROM native_chat_messages WHERE club_id=? AND thread_id=? ORDER BY seq DESC LIMIT 1 FOR UPDATE', [$thread['club_id'],$id])->fetchColumn();
            Db::run($this->db, 'UPDATE native_chat_people SET last_read=GREATEST(last_read,?) WHERE club_id=? AND thread_id=? AND actor_id=?', [min($maximum,$through),$thread['club_id'],$id,$this->access->user()['id']]);
            Db::run($this->db, 'UPDATE native_chat_outbox SET status="read" WHERE club_id=? AND thread_id=? AND actor_id=? AND message_seq<=? AND status="pending"', [$thread['club_id'],$id,$this->access->user()['id'],min($maximum,$through)]);
        });
    }

    public function addPeople(string $id, array $actorIds): void
    {
        Db::atomic($this->db, function () use ($id, $actorIds): void {
            $thread = $this->locked($id, 'edit');
            if ($thread['owner_id'] !== $this->access->user()['id']) $this->access->require('chat', 'manage');
            $people = $this->validatePeople($actorIds);
            $known = Db::run($this->db, 'SELECT actor_id FROM native_chat_people WHERE club_id=? AND thread_id=? FOR UPDATE', [$thread['club_id'],$id])->fetchAll(PDO::FETCH_COLUMN);
            $new = array_values(array_diff($people, $known));
            if (count($known) + count($new) > 50) throw new DomainException('Höchstens 50 Personen je Unterhaltung.');
            foreach ($new as $person) Db::run($this->db, 'INSERT INTO native_chat_people(club_id,thread_id,actor_id,joined_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$thread['club_id'],$id,$person]);
        });
    }

    public function leave(string $id): void
    {
        Db::atomic($this->db, function () use ($id): void {
            $thread = $this->locked($id);
            $actor = $this->access->user();
            if ($thread['owner_id'] === $actor['id']) {
                $successor = Db::run($this->db, "SELECT p.actor_id FROM native_chat_people p JOIN native_users u ON u.id=p.actor_id AND u.club_id=p.club_id AND u.active=1
                    JOIN native_grants g ON g.actor_id=u.id AND g.module='chat' AND g.capability='edit'
                    JOIN native_grants r ON r.actor_id=u.id AND r.module='chat' AND r.capability='read'
                    WHERE p.club_id=? AND p.thread_id=? AND p.actor_id<>? ORDER BY p.joined_at,p.actor_id LIMIT 1 FOR UPDATE", [$thread['club_id'],$id,$actor['id']])->fetchColumn();
                $others = count(Db::run($this->db, 'SELECT actor_id FROM native_chat_people WHERE club_id=? AND thread_id=? AND actor_id<>? FOR UPDATE', [$thread['club_id'],$id,$actor['id']])->fetchAll());
                if ($others > 0 && !$successor) throw new DomainException('Vor dem Verlassen benötigt eine verbleibende Person Chat-Schreibrechte.');
                if ($successor) Db::run($this->db, 'UPDATE native_chat_threads SET owner_id=?,version=version+1 WHERE club_id=? AND id=?', [$successor,$thread['club_id'],$id]);
            }
            Db::run($this->db, 'DELETE FROM native_chat_people WHERE club_id=? AND thread_id=? AND actor_id=?', [$thread['club_id'],$id,$actor['id']]);
            Db::run($this->db, 'UPDATE native_chat_outbox SET status="cancelled" WHERE club_id=? AND thread_id=? AND actor_id=? AND status="pending"', [$thread['club_id'],$id,$actor['id']]);
        });
    }

    private function validatePeople(array $ids): array
    {
        foreach ($ids as $id) if (!is_string($id) || $id === '') throw new DomainException('Ungültige Personenauswahl.');
        $ids = array_values(array_unique($ids));
        if (count($ids) > 50 || array_diff($ids, array_column($this->directory(), 'id'))) throw new DomainException('Nur aktive Chat-Mitglieder desselben Vereins auswählen; höchstens 50.');
        return $ids;
    }

    public static function text(string $value, int $max): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) throw new DomainException('Text enthält ungültige Steuerzeichen oder Zeichenkodierung.');
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max) throw new DomainException('Text ist leer oder zu lang.');
        return $value;
    }

    public static function requestKey(string $value): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $value)) throw new DomainException('Ungültige Anfragekennung.');
    }

    public static function fingerprint(array $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }
}
