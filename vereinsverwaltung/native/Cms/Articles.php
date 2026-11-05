<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** News is a typed workflow over Pages: no second copy of content or publication state. */
final class Articles
{
    private readonly Pages $pages;

    public function __construct(private readonly PDO $db, private readonly Access $access)
    {
        $this->pages = new Pages($db, $access);
    }

    public function list(string $query = '', string $status = 'active'): array
    {
        if (!in_array($status, ['active','archive','draft','published','review','approved','all'], true)) throw new InvalidArgumentException('Ungültiger Beitragsstatus.');
        if (preg_match('//u',$query)!==1 || preg_match('/[\x00-\x1f\x7f]/',$query) || preg_match_all('/./us',$query)>120) throw new InvalidArgumentException('Suchtext auf 120 Zeichen begrenzen.');
        $query=trim($query);$pattern='/'.preg_quote($query,'/').'/iu';
        $items = array_values(array_filter($this->pages->list(), static function (array $p) use ($status,$query,$pattern): bool {
            if ($p['template'] !== 'news') return false;
            if ($status==='archive' && !$p['archived']) return false;
            if ($status!=='archive' && $status!=='all' && $p['archived']) return false;
            if ($status==='draft' && $p['published_revision']!==null) return false;
            if ($status==='published' && $p['published_revision']===null) return false;
            if (in_array($status, ['review', 'approved'], true) && NewsReview::status($p) !== $status) return false;
            return $query==='' || preg_match($pattern,$p['title']) || preg_match($pattern,$p['excerpt']) || preg_match($pattern,$p['slug']);
        }));
        usort($items, static fn (array $a, array $b): int => strcmp($b['news_date'], $a['news_date']) ?: strcmp($a['slug'], $b['slug']) ?: strcmp($a['id'], $b['id']));
        return $items;
    }

    public function get(string $id): array
    {
        $item = $this->pages->get($id);
        if ($item['template'] !== 'news') {
            throw new DomainException('Beitrag nicht verfügbar.');
        }
        return $item;
    }

    /** Partial editor input preserves declarative fields, navigation and image metadata. */
    public function save(array $input, int $expectedVersion = 0): array
    {
        $this->access->require('website', 'edit');
        $id = $input['id'] ?? '';
        if (!is_string($id)) throw new InvalidArgumentException('Ungültige Beitrags-ID.');
        $item = $id !== '' ? $this->get($id) : ['template' => 'news', 'nav_visible' => false];
        if (isset($input['template']) && $input['template'] !== 'news') throw new InvalidArgumentException('Beiträge benötigen die Vorlage news.');
        $item = array_replace($item, $input);
        $item['template'] = 'news';
        if (array_key_exists('body', $input) && !array_key_exists('body_html', $input)) $item['body_html'] = '';
        if (!is_string($item['slug'] ?? null) || !preg_match('~\Aaktuelles/[a-z0-9]+(?:-[a-z0-9]+)*\z~D', $item['slug'])) {
            throw new InvalidArgumentException('Beitragsadresse unter aktuelles/ mit Kleinbuchstaben, Zahlen und Bindestrichen angeben.');
        }
        if (array_key_exists('body_html', $input) && !array_key_exists('body', $input)) $item['body'] = '';
        return $this->pages->save($item, $expectedVersion);
    }

    public function publish(string $id, int $expectedVersion): array
    {
        $this->get($id);
        return $this->pages->publish($id, $expectedVersion);
    }

    public function review(string $id, string $status, int $expectedVersion): array
    {
        $this->get($id);
        return $this->pages->review($id, $status, $expectedVersion);
    }

    public function unpublish(string $id, int $expectedVersion): array
    {
        $this->get($id);
        return $this->pages->unpublish($id, $expectedVersion);
    }

    public function archive(string $id, int $expectedVersion): array
    {
        $this->get($id);
        return $this->pages->archive($id,$expectedVersion);
    }

    public function unarchive(string $id, int $expectedVersion): array
    {
        $this->get($id);
        return $this->pages->unarchive($id,$expectedVersion);
    }

    public function revisions(string $id): array
    {
        $this->get($id);
        return $this->pages->revisions($id);
    }

    public function restore(string $id, int $revision, int $expectedVersion): array
    {
        $this->get($id);
        foreach ($this->pages->revisions($id) as $old) {
            if ($old['revision'] === $revision && $old['payload']['template'] !== 'news') throw new DomainException('Diese Version war kein Beitrag.');
        }
        return $this->pages->restore($id, $revision, $expectedVersion);
    }

    public function publicArticle(string $slug, string $channel = 'website'): ?array
    {
        $item = $this->pages->publicPage($slug, $channel);
        return $item && $item['template'] === 'news' ? $item : null;
    }

    /** Date is editorial metadata, not an automatic scheduled-publication switch. */
    public function publicList(int $limit = 12, int $offset = 0): array
    {
        $this->access->require('website', 'read');
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) throw new InvalidArgumentException('Beitragslimit 1–100 und Offset 0–1000000 erforderlich.');
        [$from,$parameters]=$this->publicFrom();
        $rows = Db::run($this->db, 'SELECT p.id,p.published_revision,p.published_at,r.payload_json ' . $from . ' ORDER BY JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,\'$.news_date\')) DESC,p.published_slug COLLATE utf8mb4_bin,p.id LIMIT ' . $limit . ' OFFSET ' . $offset, $parameters)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r): array => FieldPolicy::publicSource($this->db,$this->access->club(),json_decode($r['payload_json'], true, 512, JSON_THROW_ON_ERROR)) + ['id' => $r['id'], 'version' => (int) $r['published_revision'], 'published_revision' => (int) $r['published_revision'], 'published_version' => (int) $r['published_revision'], 'published_at' => $r['published_at']], $rows);
    }

    public function publicCount(): int
    {
        $this->access->require('website', 'read');
        [$from,$parameters]=$this->publicFrom();
        return (int) Db::run($this->db, 'SELECT COUNT(*) ' . $from, $parameters)->fetchColumn();
    }

    private function publicFrom(): array
    {
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        return ['FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE ('.$policy.') AND JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,\'$.template\'))=\'news\' AND ' . NewsChannels::where('website'),$parameters];
    }
}
