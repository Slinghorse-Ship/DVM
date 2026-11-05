<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Integration\StadtLandFunk\Connection;

/** Personal app comments keep their own account, home area and original event. */
final class PersonalReplyProvider implements ReplyProvider
{
    public function __construct(private PDO $db, private Connection $connection) {}

    public function account(Access $access, array $source, ?string $binding = null): array
    {
        $identity = $this->connection->replyIdentity($access, $binding);
        $object = Db::run($this->db, "SELECT kind,payload_json FROM native_social_objects WHERE club_id=? AND channel='stadtlandfunk' AND remote_id=?" . ($this->db->inTransaction() ? ' FOR SHARE' : ''), [$access->club(), $source['parent_id']])->fetch();
        $payload = $object ? json_decode($object['payload_json'], true, 32, JSON_THROW_ON_ERROR) : [];
        if (!$object || $object['kind'] !== 'event') throw new DomainException('Eigener StadtLand.Funk-Termin fehlt.');
        foreach ($identity as $key => $value) if (($payload[$key] ?? null) !== $value) throw new DomainException('Originaltermin zuerst mit der aktuellen StadtLand.Funk-Verbindung abgleichen.');
        if (($source['kind'] ?? '') === 'comment') {
            if (!is_string($source['payload']['creator_id'] ?? null)) throw new DomainException('Ursprünglichen Kommentar zuerst erneut mit StadtLand.Funk abgleichen.');
            if (SocialHub::remoteId($source['payload']['creator_id']) === $identity['account_id']) throw new DomainException('Eigene Kommentarantwort ist keine eingehende Frage.');
        }
        return $identity + ['_reply_binding' => $identity['connection_id']];
    }

    private function comments(Access $access, array $account, string $parent): array
    {
        $rows = $this->connection->read($access, '/grapevine/comment?' . http_build_query(['postId' => SocialHub::remoteId($parent)]), $account['connection_id']);
        if (!array_is_list($rows) || count($rows) > 10000) throw new DomainException('Persönliche Kommentarliste nicht vollständig bestätigt.');
        $comments = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['postId'] ?? $parent) !== $parent) throw new DomainException('Kommentar gehört nicht zum ursprünglichen Termin.');
            $id = SocialHub::remoteId($row['id'] ?? null);
            if (isset($comments[$id])) throw new DomainException('Kommentarliste enthält doppelte Anbieterkennungen.');
            $comments[$id] = $row;
        }
        return $comments;
    }

    public function current(Access $access, array $account, array $source, array $row): void
    {
        $original = $row['payload']['original'];
        $remote = SocialHub::remoteId($original['remote_id']);
        $creator = SocialHub::remoteId($original['creator_id'] ?? null);
        $current = $this->comments($access, $account, $source['parent_id'])[$remote] ?? null;
        if (!$current || !empty($current['deleted']) || ($current['text'] ?? null) !== $original['text'] || ($current['creator']['id'] ?? null) !== $creator || $creator === $account['account_id'] || in_array($current['filterStatus'] ?? '', ['BLOCKED', 'FLAGGED'], true)) {
            throw new DomainException('Kommentar beim Anbieter geändert, entfernt oder gesperrt. Neu abgleichen; nichts versendet.');
        }
    }

    public function publish(Access $access, array $account, array $source, array $row): string
    {
        $result = $this->connection->reply($access, $account['connection_id'], SocialHub::remoteId($source['parent_id']), SocialHub::remoteId($row['payload']['original']['remote_id']), $row['payload']['text']);
        $id = SocialHub::remoteId($result['commentId'] ?? $result['comment']['id'] ?? null);
        if (isset($result['comment']['id']) && $result['comment']['id'] !== $id) throw new DomainException('Antwortkennung beim Anbieter nicht eindeutig.');
        return $id;
    }

    public function proof(Access $access, array $account, array $row, string $remote): bool
    {
        $reply = $this->comments($access, $account, $row['parent_id'])[SocialHub::remoteId($remote)] ?? null;
        return $reply !== null && empty($reply['deleted']) && ($reply['postId'] ?? null) === $row['parent_id'] && ($reply['creator']['id'] ?? null) === $account['account_id'] && ($reply['replyTo']['id'] ?? null) === ($row['payload']['original']['creator_id'] ?? null) && ($reply['text'] ?? null) === $row['payload']['text'] && !in_array($reply['filterStatus'] ?? '', ['BLOCKED', 'FLAGGED'], true);
    }
}
