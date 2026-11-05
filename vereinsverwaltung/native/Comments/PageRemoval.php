<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;

use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Owned comment data is removed only inside the explicitly confirmed page transaction. */
final class PageRemoval
{
    private const TABLES=['native_comments','native_comment_history','native_comment_settings','native_comment_setting_history'];

    public static function present(PDO $db,string $club,string $page):bool
    {
        Values::id($page);
        if(!self::installed($db))return false;
        return (bool)Db::run($db,'SELECT 1 FROM native_comments WHERE club_id=? AND page_id=? LIMIT 1',[$club,$page])->fetchColumn()
            || (bool)Db::run($db,'SELECT 1 FROM native_comment_settings WHERE club_id=? AND page_id=? LIMIT 1',[$club,$page])->fetchColumn()
            || (bool)Db::run($db,'SELECT 1 FROM native_comment_setting_history WHERE club_id=? AND page_id=? LIMIT 1',[$club,$page])->fetchColumn();
    }

    private static function installed(PDO $db):bool
    {
        $count=(int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_comments','native_comment_history','native_comment_settings','native_comment_setting_history') AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
        if (!$count) return false;
        if ($count!==count(self::TABLES)) throw new \DomainException('Kommentare benötigen eine vollständige Modulinstallation.');
        return true;
    }

    public static function assertRights(PDO $db,Access $access,string $page):bool
    {
        $present=self::present($db,$access->club(),$page);
        if ($present) {$access->require('website','manage');\Kulturhus\Native\Cms\TemplatePolicy::assertCapability($db,$access,$page,'publish');}
        return $present;
    }

    public static function clear(PDO $db,Access $access,string $page,bool $confirmed):void
    {
        if(!self::installed($db))return;
        $club=$access->club();
        foreach(['native_comments','native_comment_settings','native_comment_setting_history'] as $table)if(Db::run($db,'SELECT 1 FROM '.$table.' WHERE page_id=? AND club_id<>? LIMIT 1',[$page,$club])->fetchColumn())throw new \Kulturhus\Native\Cms\Conflict('Eine fremde Originalbindung benötigt diese Seite noch.');
        if (!self::assertRights($db,$access,$page)) return;
        if (!$confirmed) throw new \DomainException('Das dauerhafte Löschen der zugehörigen Kommentare zusätzlich bestätigen.');
        if (!$db->inTransaction()) throw new \DomainException('Kommentare nur gemeinsam mit der bestätigten Seite entfernen.');
        Db::run($db,'DELETE h FROM native_comment_history h JOIN native_comments c ON c.id=h.comment_id AND c.club_id=h.club_id WHERE c.club_id=? AND c.page_id=?',[$club,$page]);
        Db::run($db,'DELETE FROM native_comments WHERE club_id=? AND page_id=?',[$club,$page]);
        Db::run($db,'DELETE FROM native_comment_setting_history WHERE club_id=? AND page_id=?',[$club,$page]);
        Db::run($db,'DELETE FROM native_comment_settings WHERE club_id=? AND page_id=?',[$club,$page]);
    }
}
