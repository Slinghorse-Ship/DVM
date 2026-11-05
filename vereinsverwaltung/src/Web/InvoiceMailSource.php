<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use PDO;
use DomainException;
use Throwable;
use Kulturhus\Verein\{InvoiceMailOnly,Permissions};

/** Original correspondence selection; reading never creates an invoice, expense or payment. */
final class InvoiceMailSource
{
    private const FIELDS=['mail_only','mail_state','mail_q','mail_page'];
    public static function commandRequested(array $post):bool{return($post['action']??null)==='invoice-mail-only-archive';}
    private static function identity(mixed $id):bool{return is_string($id)&&$id!==''&&strlen($id)<=128&&mb_check_encoding($id,'UTF-8')&&!preg_match('/[\x00-\x1f\x7f]/',$id);}
    public static function command(PDO $db,string $actor,array $query,array $post):void
    {
        if(!self::commandRequested($post))return;
        $permission=Permissions::finance($db,$actor);if($permission['finance_access']!=='write')throw new DomainException('Zum Ablegen oder Wiederherstellen ist aktuelles Finanz-Bearbeitungsrecht erforderlich.',403);
        if(($query['view']??null)!=='finance'||($query['tab']??null)!=='inbox'||array_intersect(['inbox_doc','new_entry'],array_keys($query)))throw new DomainException('Die Nachricht im ursprünglichen Bereich E-Mails ohne Beleg öffnen.',409);
        if(!self::identity($query['mail_only']??null)||!self::identity($post['mail_only']??null))throw new DomainException('Die vollständige ursprüngliche Nachrichtenkennung prüfen.',422);
        if($query['mail_only']!==$post['mail_only'])throw new DomainException('Link und Formular gehören nicht zu derselben ursprünglichen Nachricht.',409);
        $version=$post['version']??null;
        if(!is_string($version)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$version)||(int)$version>2147483647)throw new DomainException('Die vollständige ursprüngliche Nachrichtenversion prüfen.',422);
        if(!in_array($post['archive']??null,['0','1'],true))throw new DomainException('Ablegen oder Wiederherstellen ausdrücklich auswählen.',422);
        $service=new InvoiceMailOnly($db,$actor);$current=$service->item($post['mail_only']);$service->original($post['mail_only']);
        if((string)$current['version']!==$version||$current['state']!==($post['archive']==='1'?'active':'archived'))throw new DomainException('Die E-Mail wurde inzwischen geändert. Bitte neu öffnen.',409);
    }
    public static function prepare(PDO $db,string $actor,array $query,array $post,string $method):array
    {
        $state=['state'=>'invalid','status'=>422,'message'=>'','selected'=>null,'original'=>null,'page'=>null,'filter'=>'active','id'=>'','can_write'=>false,'raw'=>[],'retry'=>null,'submitted'=>null,'list_route'=>['view'=>'finance','tab'=>'inbox','mail_state'=>'active'],'list_keeps_query'=>false,'open'=>array_intersect(self::FIELDS,array_keys($query))!==[]||self::commandRequested($post)];
        foreach(self::FIELDS as$key)if(array_key_exists($key,$query))$state['raw'][]=['field'=>$key,'value'=>$query[$key],'from'=>'link'];
        if($method==='POST'&&self::commandRequested($post))foreach(['mail_only','version','archive','reason']as$key)if(array_key_exists($key,$post))$state['raw'][]=['field'=>$key,'value'=>$post[$key],'from'=>'form'];
        $parent=array_intersect_key($query,array_flip(['status','inbox_q','inbox_year','inbox_page']));$state['list_route']+=$parent;
        $original=array_intersect_key($query,array_flip(self::FIELDS));if($original)$state['retry']=['view'=>'finance','tab'=>'inbox']+$original+$parent;
        if($method==='POST'&&self::commandRequested($post)&&self::identity($post['mail_only']??null)&&$post['mail_only']!==($query['mail_only']??null))$state['submitted']=['view'=>'finance','tab'=>'inbox','mail_only'=>$post['mail_only']]+$parent;
        $authorized=false;$own=false;
        try{
            $a=Permissions::finance($db,$actor);$authorized=true;$state['can_write']=$a['finance_access']==='write';
            if(($query['view']??null)!=='finance'||($query['tab']??null)!=='inbox'||array_intersect(['inbox_doc','new_entry'],array_keys($query)))throw new DomainException('Die Nachricht im ursprünglichen Bereich E-Mails ohne Beleg öffnen.',422);
            if($method==='POST')self::command($db,$actor,$query,$post);
            $filter=$query['mail_state']??'active';$term=$query['mail_q']??'';$page=$query['mail_page']??'1';
            if(!is_string($filter)||!in_array($filter,['active','archived'],true)||!is_string($term)||!mb_check_encoding($term,'UTF-8')||mb_strlen($term)>150||preg_match('/[\x00-\x1f\x7f]/',$term)||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$page))throw new DomainException('Nachrichtensuche, Stand und Seite vollständig prüfen.',422);
            $state['filter']=$filter;$state['list_route']=['view'=>'finance','tab'=>'inbox','mail_state'=>$filter,'mail_q'=>$term]+$parent;$state['list_keeps_query']=true;
            if(array_key_exists('mail_only',$query)&&!self::identity($query['mail_only']))throw new DomainException('Die vollständige ursprüngliche Nachrichtenkennung prüfen.',422);
            $own=!$db->inTransaction();if($own)$db->exec('START TRANSACTION READ ONLY');$service=new InvoiceMailOnly($db,$actor);
            if(array_key_exists('mail_only',$query)){$state['id']=$query['mail_only'];$state['selected']=$service->item($state['id']);$state['original']=$service->original($state['id']);}
            $state['page']=$service->page($filter,$term,$page);
            if($state['page']['page']!==(int)$page)throw new DomainException('Die ursprünglich angeforderte Nachrichtenseite ist nicht mehr verfügbar. Die Liste ausdrücklich neu öffnen.',422);
            if($own)$db->commit();$state['state']=$state['selected']===null?'list':'selected';$state['status']=200;return$state;
        }catch(DomainException $failure){
            if($own&&$db->inTransaction())$db->rollBack();$code=$failure->getCode();
            $state['status']=!$authorized?403:(in_array($code,[403,409,422,503],true)?$code:404);$state['state']=$state['status']===403?'blocked':($state['status']===404?'unavailable':'invalid');
            $state['message']=!$authorized?'Für diese Nachrichten ist aktueller Finanzzugriff erforderlich.':($state['status']===404?'Die ursprüngliche Nachricht ist derzeit nicht verfügbar oder kann nicht vollständig geprüft werden.':$failure->getMessage());
        }catch(Throwable){if($own&&$db->inTransaction())$db->rollBack();$state['status']=503;$state['state']='unavailable';$state['message']='Die ursprünglichen Nachrichten konnten gerade nicht geladen werden. Bitte erneut prüfen.';}
        $state['selected']=null;$state['original']=null;$state['page']=null;return$state;
    }
}
