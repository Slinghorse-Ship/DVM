<?php declare(strict_types=1);
namespace Kulturhus\Native\Chat;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Count and select only the current participant's own small conversation page. */
final class Catalogue
{
    public static function state(array $query):array
    {
        $text=$query['chat_query']??'';$filter=$query['chat_filter']??'all';$page=$query['chat_page']??1;$open=$query['chat_rooms']??'0';
        if(!is_string($text)||!preg_match('//u',$text)||mb_strlen($text)>200||preg_match('/[\x00-\x1f\x7f]/',$text))throw new DomainException('Unterhaltungssuche prüfen.');
        if(!in_array($filter,['all','unread'],true)||!in_array($open,['0','1'],true))throw new DomainException('Unterhaltungsfilter prüfen.');
        if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000)throw new DomainException('Unterhaltungsseite prüfen.');
        return ['chat_query'=>trim($text),'chat_filter'=>$filter,'chat_page'=>(int)$page,'chat_rooms'=>$open];
    }

    public static function page(PDO $db,Access $access,array $query=[]):array
    {
        $actor=$access->require('chat','read');$state=self::state($query);
        $from=' FROM native_chat_threads t JOIN native_chat_people p ON p.club_id=t.club_id AND p.thread_id=t.id';
        $scope='t.club_id=? AND p.actor_id=?';$args=[$actor['club_id'],$actor['id']];
        $unread='m.club_id=t.club_id AND m.thread_id=t.id AND m.seq>p.last_read AND m.actor_id<>p.actor_id';
        $unreadTotal=(int)Db::run($db,'SELECT COUNT(*)'.$from.' JOIN native_chat_messages m ON '.$unread.' WHERE '.$scope,$args)->fetchColumn();
        $where=$scope;
        if($state['chat_query']!==''){$where.=" AND t.title COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$state['chat_query']).'%';}
        if($state['chat_filter']==='unread')$where.=' AND EXISTS(SELECT 1 FROM native_chat_messages m WHERE '.$unread.')';
        $total=(int)Db::run($db,'SELECT COUNT(*)'.$from.' WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($state['chat_page'],$pages);
        $rows=Db::run($db,'SELECT t.id,t.title,t.owner_id,t.version,t.updated_at,p.last_read,(SELECT COUNT(*) FROM native_chat_messages m WHERE '.$unread.') unread'.$from.' WHERE '.$where.' ORDER BY t.updated_at DESC,t.id LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll(PDO::FETCH_ASSOC);
        return array_replace($state,['chat_page'=>$page,'total'=>$total,'pages'=>$pages,'rows'=>$rows,'unread_total'=>$unreadTotal]);
    }
}
