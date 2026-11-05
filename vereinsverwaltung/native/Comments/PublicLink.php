<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Web\Html;
use PDO;

/** Cacheable invitation only: no cookies, author context, CSRF, forms or comment drafts. */
final class PublicLink
{
    public static function page(PDO $db,Access $publicAccess,array $page):string
    {
        if(!is_string($page['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$page['id']))return'';
        if(!Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_comment_settings'")->fetchColumn())return'';
        $links='';
        foreach(Db::run($db,'SELECT stream FROM native_comment_settings WHERE club_id=? AND page_id=? AND enabled=1 ORDER BY stream LIMIT 10',[$publicAccess->club(),$page['id']])->fetchAll(\PDO::FETCH_COLUMN)as$stream)$links.=self::render($db,$publicAccess,$page,$stream);
        return $links===''?'':'<nav class="comment-page-links" aria-label="Kommentare">'.$links.'</nav>';
    }
    public static function render(PDO $db,Access $publicAccess,array $page,string $stream='comments'):string
    {
        Values::stream($stream);$id=$page['id']??null;$revision=$page['published_revision']??$page['published_version']??$page['version']??null;
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_int($revision))return'';
        if(!Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_comment_settings'")->fetchColumn())return'';
        $slug=Db::run($db,'SELECT published_slug FROM khcms_pages WHERE club_id=? AND id=? AND published_revision=?',[$publicAccess->club(),$id,$revision])->fetchColumn();
        $original=is_string($slug)?(new Pages($db,$publicAccess))->publicPage($slug):null;
        if(!$original||$original['id']!==$id)return'';
        try{Bindings::assert($db,$publicAccess,$id,$stream,true);}catch(\DomainException){return'';}
        if(!Db::run($db,'SELECT 1 FROM native_comment_settings WHERE club_id=? AND page_id=? AND stream=? AND enabled=1',[$publicAccess->club(),$id,$stream])->fetchColumn())return'';
        return'<a class="btn btn-outline theme-secondary comment-open-link" href="/_comments?'.Html::e(http_build_query(['id'=>$id,'stream'=>$stream],'','&',PHP_QUERY_RFC3986)).'">'.Html::e($original['field_definitions'][$stream]['label']??'Kommentare').'</a>';
    }
}
