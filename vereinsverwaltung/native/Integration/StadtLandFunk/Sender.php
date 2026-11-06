<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\StadtLandFunk;

/** Provider ownership is an organization or a person, never a display name. */
final class Sender
{
    public static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $id)) throw new \DomainException('Absenderkennung prüfen.');
        return $id;
    }

    public static function owns(array $post, array $identity): bool
    {
        $organization = $identity['organization_id'] ?? '';
        if ($organization !== '') return ($post['organizationId'] ?? '') === $organization
            && (in_array($identity['home_area_id'], $post['geoAreaIds'] ?? [], true) || ($post['geoAreaId'] ?? '') === $identity['home_area_id']);
        return empty($post['organizationId']) && ($post['creator']['id'] ?? '') === $identity['account_id'];
    }

    public static function postsPath(array $identity, int $page): string
    {
        $organization = $identity['organization_id'] ?? '';
        return $organization !== ''
            ? '/grapevine/organization/' . self::id($organization) . '/posts?' . http_build_query(['page'=>$page,'count'=>50])
            : '/grapevine/post/own?' . http_build_query(['type'=>'HAPPENING','page'=>$page,'count'=>50]);
    }
}
