<?php declare(strict_types=1);
namespace Kulturhus\Native\Chat;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;

/** Named membership, aggregate results, one revisable answer per account. */
final class Polls
{
    private Workspace $chat;
    public function __construct(private PDO $db, private Access $access) { $this->chat = new Workspace($db, $access); }

    /** $closesAt accepts an explicit UTC instant, e.g. 2026-10-08T18:00:00Z. */
    public function create(string $threadId, string $question, array $options, ?string $closesAt, string $requestKey): array
    {
        $question = Workspace::text($question, 500);
        Workspace::requestKey($requestKey);
        if (count($options) < 2 || count($options) > 10) throw new DomainException('Zwei bis zehn Antworten eingeben.');
        $labels = [];
        $normalized = [];
        foreach ($options as $option) {
            if (!is_string($option)) throw new DomainException('Ungültige Umfrageantwort.');
            $label = Workspace::text($option, 160);
            $key = mb_strtolower((string)preg_replace('/\s+/u', ' ', $label), 'UTF-8');
            if (in_array($key, $normalized, true)) throw new DomainException('Umfrageantworten müssen unterschiedlich sein.');
            $normalized[] = $key;
            $labels[] = $label;
        }
        $deadline = null;
        if ($closesAt !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $closesAt, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $closesAt) throw new DomainException('Umfrageende als gültigen UTC-Zeitpunkt angeben.');
            $deadline = $date->format('Y-m-d H:i:s.000000');
        }
        return Db::atomic($this->db, function () use ($threadId, $question, $labels, $deadline, $requestKey): array {
            $thread = $this->chat->locked($threadId, 'edit');
            $hash = Workspace::fingerprint(['poll',$question,$labels,$deadline]);
            $old = $this->chat->request($thread, $requestKey);
            if ($old) {
                if (!hash_equals($old['request_hash'], $hash)) throw new Conflict('Anfragekennung bereits mit anderem Inhalt verwendet.');
                $id = Db::run($this->db, 'SELECT id FROM native_chat_polls WHERE club_id=? AND thread_id=? AND message_seq=? FOR UPDATE', [$thread['club_id'],$threadId,$old['seq']])->fetchColumn();
                if (!$id) throw new Conflict('Anfrage gehört nicht zu einer Umfrage.');
                return $this->get($threadId, $id);
            }
            if ($deadline !== null && $deadline <= $this->now()) throw new DomainException('Das Umfrageende muss in der Zukunft liegen.');
            $actor = $this->access->user();
            $message = $this->chat->append($thread, $actor['id'], $question, $requestKey, $hash);
            $id = bin2hex(random_bytes(16));
            Db::run($this->db, 'INSERT INTO native_chat_polls(id,club_id,thread_id,message_seq,author_id,question,closes_at) VALUES(?,?,?,?,?,?,?)', [$id,$thread['club_id'],$threadId,$message['seq'],$actor['id'],$question,$deadline]);
            foreach ($labels as $number => $label) {
                Db::run($this->db, 'INSERT INTO native_chat_poll_options(club_id,thread_id,poll_id,option_number,label) VALUES(?,?,?,?,?)', [$thread['club_id'],$threadId,$id,$number+1,$label]);
            }
            return $this->get($threadId, $id);
        });
    }

    public function get(string $threadId, string $pollId): array
    {
        $thread = $this->chat->get($threadId);
        $row = $this->row($thread, $pollId);
        $row['options'] = Db::run($this->db, 'SELECT o.option_number,o.label,COUNT(v.actor_id) votes
            FROM native_chat_poll_options o LEFT JOIN native_chat_poll_votes v ON v.club_id=o.club_id AND v.thread_id=o.thread_id AND v.poll_id=o.poll_id AND v.option_number=o.option_number
            WHERE o.club_id=? AND o.thread_id=? AND o.poll_id=? GROUP BY o.option_number,o.label ORDER BY o.option_number' . ($this->db->inTransaction() ? ' FOR UPDATE' : ''), [$thread['club_id'],$threadId,$pollId])->fetchAll();
        $own = Db::run($this->db, 'SELECT option_number,version FROM native_chat_poll_votes WHERE club_id=? AND thread_id=? AND poll_id=? AND actor_id=?' . ($this->db->inTransaction() ? ' FOR UPDATE' : ''), [$thread['club_id'],$threadId,$pollId,$this->access->user()['id']])->fetch();
        $row['own_option'] = $own ? (int)$own['option_number'] : null;
        $row['vote_version'] = $own ? (int)$own['version'] : 0;
        $row['total_votes'] = array_sum(array_column($row['options'], 'votes'));
        $row['open'] = $this->isOpen($row);
        return $row;
    }

    public function vote(string $threadId, string $pollId, int $option, int $expectedVersion, string $requestKey): array
    {
        Workspace::requestKey($requestKey);
        return Db::atomic($this->db, function () use ($threadId, $pollId, $option, $expectedVersion, $requestKey): array {
            $thread = $this->chat->locked($threadId, 'edit');
            $poll = $this->row($thread, $pollId);
            $actorId = $this->access->user()['id'];
            $scope = [$thread['club_id'],$threadId,$pollId,$actorId];
            $hash = Workspace::fingerprint([$option,$expectedVersion]);
            $request = Db::run($this->db, 'SELECT request_hash FROM native_chat_vote_requests WHERE club_id=? AND thread_id=? AND poll_id=? AND actor_id=? AND request_key=? FOR UPDATE', [...$scope,$requestKey])->fetch();
            if ($request) {
                if (!hash_equals($request['request_hash'], $hash)) throw new Conflict('Stimmkennung bereits mit anderer Auswahl verwendet.');
                return $this->get($threadId, $pollId);
            }
            if (!$this->isOpen($poll)) throw new DomainException('Die Umfrage ist beendet.');
            if (!Db::run($this->db, 'SELECT 1 FROM native_chat_poll_options WHERE club_id=? AND thread_id=? AND poll_id=? AND option_number=?', [$thread['club_id'],$threadId,$pollId,$option])->fetchColumn()) throw new DomainException('Ungültige Antwort dieser Umfrage.');
            $current = Db::run($this->db, 'SELECT version FROM native_chat_poll_votes WHERE club_id=? AND thread_id=? AND poll_id=? AND actor_id=? FOR UPDATE', $scope)->fetchColumn();
            if ((int)$current !== $expectedVersion) throw new Conflict('Die Stimme wurde inzwischen geändert. Bitte neu laden.');
            if ($current === false) {
                Db::run($this->db, 'INSERT INTO native_chat_poll_votes(club_id,thread_id,poll_id,actor_id,option_number,version,updated_at) VALUES(?,?,?,?,?,1,UTC_TIMESTAMP(6))', [...$scope,$option]);
            } else {
                Db::run($this->db, 'UPDATE native_chat_poll_votes SET option_number=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND thread_id=? AND poll_id=? AND actor_id=? AND version=?', [$option,...$scope,$expectedVersion]);
            }
            Db::run($this->db, 'INSERT INTO native_chat_vote_requests(club_id,thread_id,poll_id,actor_id,request_key,request_hash,resulting_version) VALUES(?,?,?,?,?,?,?)', [...$scope,$requestKey,$hash,$expectedVersion+1]);
            return $this->get($threadId, $pollId);
        });
    }

    public function close(string $threadId, string $pollId, int $expectedVersion): array
    {
        return Db::atomic($this->db, function () use ($threadId, $pollId, $expectedVersion): array {
            $thread = $this->chat->locked($threadId, 'edit');
            $poll = $this->row($thread, $pollId);
            if ($poll['author_id'] !== $this->access->user()['id']) $this->access->require('chat', 'manage');
            if ((int)$poll['version'] !== $expectedVersion) throw new Conflict('Die Umfrage wurde inzwischen geändert.');
            if ($poll['closed_at'] === null) Db::run($this->db, 'UPDATE native_chat_polls SET closed_at=UTC_TIMESTAMP(6),version=version+1 WHERE club_id=? AND thread_id=? AND id=? AND version=?', [$thread['club_id'],$threadId,$pollId,$expectedVersion]);
            return $this->get($threadId, $pollId);
        });
    }

    private function row(array $thread, string $pollId): array
    {
        $row = Db::run($this->db, 'SELECT * FROM native_chat_polls WHERE club_id=? AND thread_id=? AND id=?' . ($this->db->inTransaction() ? ' FOR UPDATE' : ''), [$thread['club_id'],$thread['id'],$pollId])->fetch();
        if (!$row) throw new DomainException('Umfrage nicht verfügbar.');
        return $row;
    }
    private function now(): string { return (string)Db::run($this->db, 'SELECT UTC_TIMESTAMP(6)')->fetchColumn(); }
    private function isOpen(array $poll): bool { return $poll['closed_at'] === null && ($poll['closes_at'] === null || $poll['closes_at'] > $this->now()); }
}
