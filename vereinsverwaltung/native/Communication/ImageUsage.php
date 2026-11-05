<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Cms\{Conflict,MediaMetadata};
use Kulturhus\Native\Shared\Db;

/** Exact CMS sources of an immutable communication image, without private consent data. */
final class ImageUsage
{
    public static function refs(array $item, string $logo = ''): array
    {
        $refs = $logo === '' ? [] : [$logo];
        foreach ($item['blocks'] ?? [] as $block) if (in_array($block['type'],['image','event'],true)&&!empty($block['media_ref'])) $refs[] = $block['media_ref'];
        foreach($item['carousel']??[]as$slide)if(($slide['media_ref']??'')!=='')$refs[]=$slide['media_ref'];
        return array_values(array_unique([...$refs,...($item['attachment_media_ids']??[])]));
    }

    public static function capture(PDO $db, string $club, array $refs, string $channel): array
    {
        self::channel($channel);
        if (count($refs) > 64) throw new DomainException('Zu viele Bildquellen.');
        foreach ($refs as $ref) Content::id($ref);
        return ['schema'=>1, 'channel'=>$channel, 'media'=>MediaMetadata::capture($db, $club, ['media_ids'=>array_values(array_unique($refs))])];
    }

    public static function guard(PDO $db, string $club, array $usage, string $channel, bool $versions = true): void
    {
        self::channel($channel);
        if (($usage['schema'] ?? null) !== 1 || ($usage['channel'] ?? null) !== $channel || !is_array($usage['media'] ?? null) || count($usage['media']) > 64) {
            throw new DomainException('Bildquellen fehlen. Bilder neu erzeugen, prüfen und freigeben.');
        }
        foreach ($usage['media'] as $id=>$metadata) {
            Content::id($id);
            if (!is_array($metadata) || !is_int($metadata['version'] ?? null) || $metadata['version'] < 0) throw new DomainException('Bildquellenversion prüfen.');
        }
        try {
            if ($versions) MediaMetadata::assertPublication($db, $club, ['media_ids'=>array_keys($usage['media']), 'media_metadata'=>$usage['media']], $channel);
            else MediaMetadata::assertRights($db, $club, array_keys($usage['media']), $channel);
        } catch (Conflict $error) {
            throw new DomainException($error->getMessage(), 0, $error);
        }
    }

    public static function item(PDO $db, string $club, array $item, string $channel): array
    {
        $usage = $item['_media_usage'] ?? self::legacy($db, $club, self::refs($item), $channel);
        self::guard($db, $club, $usage, $channel);
        return $usage;
    }

    /** The only legacy source we know exactly is the original single-source pixel snapshot. */
    public static function image(PDO $db, string $club, array $row, bool $versions = true): void
    {
        if (!in_array($row['kind'] ?? '', ['post','story','carousel','newsletter'], true)) throw new DomainException('Ursprüngliche Bildart prüfen.');
        $metadata = json_decode($row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
        $channel = ($row['kind'] ?? '') === 'newsletter' ? 'newsletter' : 'social';
        $usage = $metadata['sources'] ?? null;
        if ($usage === null && ($metadata['renderer'] ?? '') === 'cms-pixel-snapshot' && isset($metadata['media_ref'])) {
            $usage = self::legacy($db, $club, [$metadata['media_ref']], $channel);
        }
        if (!is_array($usage)) throw new DomainException('Bildquellen fehlen. Bilder neu erzeugen, prüfen und freigeben.');
        self::guard($db, $club, $usage, $channel, $versions);
        if ($versions && $channel==='social') {
            $lineHeight=$metadata['attribution']['line_height']??ImageAttribution::LINE_HEIGHT;
            if(!is_int($lineHeight)||!in_array($lineHeight,[44,48],true))throw new DomainException('Originales Quellenformat prüfen.');
            $plan = ImageAttribution::plan($usage,$row['kind']==='story'?1920:1350,$lineHeight);
            if ($plan['lines'] && !ImageAttribution::matches($metadata['attribution']??null,$plan)) throw new DomainException('Bildquellen fehlen im Original. Bilder neu erzeugen und freigeben.');
        }
    }

    public static function storedImage(PDO $db, string $club, string $id): void
    {
        Content::id($id);
        $row = Db::run($db, 'SELECT metadata_json,kind FROM native_social_images WHERE club_id=? AND id=?'.($db->inTransaction() ? ' FOR SHARE' : ''), [$club,$id])->fetch();
        if (!$row || !in_array($row['kind'], ['post','story','carousel'], true)) throw new DomainException('Ursprüngliches Social-Bild fehlt.');
        self::image($db, $club, $row);
    }

    /** Called before a mail transport starts; old token URLs are resolved to their own originals. */
    public static function message(PDO $db, string $club, array $payload): void
    {
        if (isset($payload['media_usage'])) {
            self::guard($db, $club, $payload['media_usage'], 'newsletter');
            return;
        }
        preg_match_all('~/publication-image/([a-f0-9]{64})(?=["\s<]|&quot;)~', $payload['html'] ?? '', $matches);
        $tokens = array_unique($matches[1]);
        if (count($tokens)>64) throw new DomainException('Zu viele ursprüngliche Bildquellen.');
        foreach ($tokens as $token) {
            $row = Db::run($db, 'SELECT i.kind,i.metadata_json FROM native_image_capabilities c JOIN native_social_images i ON i.club_id=c.club_id AND i.id=c.image_id WHERE c.club_id=? AND c.token_hash=?'.($db->inTransaction() ? ' FOR SHARE' : ''), [$club,hash('sha256',$token)])->fetch();
            if (!$row || $row['kind'] !== 'newsletter') throw new DomainException('Ursprüngliche Newsletterbildquelle fehlt.');
            self::image($db, $club, $row);
        }
    }

    private static function legacy(PDO $db, string $club, array $refs, string $channel): array
    {
        $usage = self::capture($db, $club, $refs, $channel);
        foreach ($usage['media'] as &$metadata) $metadata['version'] = 0;
        return $usage;
    }

    private static function channel(string $channel): void
    {
        if (!in_array($channel, ['social','newsletter','press'], true)) throw new DomainException('Bildkanal prüfen.');
    }
}
