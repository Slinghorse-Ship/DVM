<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use Kulturhus\Verein\ContactArchive;

final class MemberArchivePage
{
    public static function applies(array $query):bool{return ($query['view']??null)==='members'&&($query['tab']??null)==='archive';}
    public static function marked(array $post):bool
    {
        return array_key_exists('member_archive_intent',$post)||array_key_exists('member_archive_version',$post)||array_key_exists('member_archive_fingerprint',$post)||array_key_exists('member_archive_review_name',$post);
    }
    public static function target(array $query,array $post):string
    {
        if(!self::applies($query)||($post['action']??null)!=='restore-contact'||($post['member_archive_intent']??null)!=='restore'||array_diff(array_keys($query),['view','tab','q','page','archive_focus'])!==[]||!is_string($post['id']??null)||$post['id']===''||strlen($post['id'])>100||($query['archive_focus']??null)!==$post['id'])throw new \DomainException('Wiederherstellung im ursprünglich geöffneten Archivprofil prüfen.',422);
        $queryText=$query['q']??'';$page=$query['page']??'1';
        if(!is_string($queryText)||preg_match('//u',$queryText)!==1||mb_strlen($queryText,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$queryText)||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new \DomainException('Archivsuche und Seite prüfen.',422);
        return$post['id'];
    }
    public static function restore(ContactArchive $archive,array $query,array $post):string
    {
        $id=self::target($query,$post);
        foreach(['member_archive_version','member_archive_fingerprint','member_archive_review_name','member_archive_confirm']as$key)if(!is_string($post[$key]??null))throw new \DomainException('Geprüften Archivstand und eigene Zustimmung vollständig angeben.',422);
        if(!preg_match('/\A[1-9][0-9]{0,18}\z/D',$post['member_archive_version'])||filter_var($post['member_archive_version'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false||!preg_match('/\A[a-f0-9]{64}\z/D',$post['member_archive_fingerprint'])||$post['member_archive_review_name']===''||strlen($post['member_archive_review_name'])>200||$post['member_archive_confirm']!=='1')throw new \DomainException('Geprüften Archivstand und eigene Zustimmung vollständig angeben.',422);
        $archive->restore($id,['version'=>(int)$post['member_archive_version'],'fingerprint'=>$post['member_archive_fingerprint'],'name'=>$post['member_archive_review_name']]);return$id;
    }
    public static function prepare(ContactArchive $archive,array $query,array $post=[]):array
    {
        $state=['query'=>$query['q']??'','number'=>$query['page']??'1','focus'=>$query['archive_focus']??'','page'=>null,'selected'=>null,'error'=>'','status'=>200,'draft'=>null,'stale'=>false];
        if(($post['action']??null)==='restore-contact'){$state['draft']=array_intersect_key($post,array_flip(['id','member_archive_version','member_archive_fingerprint','member_archive_review_name','member_archive_confirm']));}
        try{
            if(!is_string($state['number'])||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$state['number']))throw new \DomainException('Archivsuche und Seite prüfen.',422);
            if(!is_string($state['focus'])||strlen($state['focus'])>100)throw new \DomainException('Archiv nicht verfügbar.',404);
            if($state['focus']!=='')$state['selected']=$archive->archived($state['focus']);
            $state['page']=$archive->page(['q'=>$state['query']],(int)$state['number']);
            if($state['draft']!==null&&$state['selected']!==null){$draft=$state['draft'];$selected=$state['selected'];$state['stale']=($draft['id']??null)!==$selected['contact_id']||($draft['member_archive_version']??null)!==(string)$selected['version']||($draft['member_archive_fingerprint']??null)!==$selected['fingerprint']||($draft['member_archive_review_name']??null)!==$selected['name'];}
        }catch(\DomainException$error){$state['error']=$error->getMessage();$state['status']=in_array($error->getCode(),[403,404,409,422,503],true)?$error->getCode():422;$state['page']=null;}
        return$state;
    }
}
