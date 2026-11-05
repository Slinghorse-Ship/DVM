<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use PDO;
use DomainException;
use Throwable;
use Kulturhus\Verein\{Finance,InvoiceInbox,InvoiceIntakeCatalogue};

/** Keep the requested incoming original and its whole controls; never substitute a list or another file. */
final class InvoiceInboxSource
{
    public static function commandRequested(array $post):bool
    {
        return in_array($post['action']??null,['invoice-inbox-archive','invoice-inbox-assign','invoice-payment-report','invoice-payment-withdraw','invoice-payment-allocate'],true)
            || (($post['action']??null)==='create-entry'&&array_key_exists('inbox_guided',$post));
    }
    private static function identity(mixed $value):bool
    {
        return is_string($value)&&$value!==''&&strlen($value)<=128&&mb_check_encoding($value,'UTF-8')&&!preg_match('/[\x00-\x1f\x7f]/',$value);
    }
    private static function version(mixed $value):bool
    {
        return is_string($value)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)===1&&(int)$value<=2147483647;
    }
    public static function command(array $query,array $post):void
    {
        if(!self::commandRequested($post))return;
        if(($query['view']??null)!=='finance'||($query['tab']??null)!=='inbox'||array_key_exists('new_entry',$query))throw new DomainException('Diesen Vorgang im ursprünglichen Rechnungseingang öffnen.',409);
        $guided=($post['action']??null)==='create-entry';
        $payment=in_array($post['action']??null,['invoice-payment-report','invoice-payment-withdraw','invoice-payment-allocate'],true);
        if($guided&&($post['inbox_guided']??null)!=='1')throw new DomainException('Die vollständige ursprüngliche Rechnungserfassung prüfen.',422);
        $field=$guided?'inbox_doc':'doc';$version=$guided?'inbox_version':'document_version';
        if(!self::identity($query['inbox_doc']??null)||!self::identity($post[$field]??null))throw new DomainException('Die vollständige ursprüngliche Rechnungskennung prüfen.',422);
        if($query['inbox_doc']!==$post[$field])throw new DomainException('Link und Formular gehören nicht zu derselben ursprünglichen Rechnung.',409);
        if(!$payment&&!self::version($post[$version]??null))throw new DomainException('Die vollständige ursprüngliche Dateiversion prüfen.',422);
        if($payment){
            if(!self::identity($post['entry_id']??null)||!self::version($post['entry_version']??null))throw new DomainException('Die vollständige ursprüngliche Rechnung und Belegversion prüfen.',422);
            if(!is_string($post['request_key']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$post['request_key']))throw new DomainException('Die ursprüngliche Vorgangskennung der Zahlungsaktion prüfen.',422);
            if($post['action']==='invoice-payment-allocate'){
                if(!self::identity($post['transaction_id']??null)||!self::version($post['transaction_version']??null)||!is_string($post['amount']??null))throw new DomainException('Den ursprünglichen Kontoumsatz mit Version und Betrag vollständig prüfen.',422);
            }else{
                $report=$post['report_version']??null;$note=$post[$post['action']==='invoice-payment-report'?'note':'reason']??null;
                if(!is_string($report)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$report)||(int)$report>2147483647||!is_string($note)||($post['action']==='invoice-payment-report'&&!is_string($post['paid_on']??null)))throw new DomainException('Den ursprünglichen Meldungsstand und vollständigen Zahlungstext prüfen.',422);
            }
        }
        if(($post['action']??null)==='invoice-inbox-assign'){
            $selection=$post['expense']??null;$parts=is_string($selection)?explode(':',$selection):[];
            if(count($parts)!==2||!self::identity($parts[0])||!self::version($parts[1]))throw new DomainException('Die vollständige ursprüngliche Ausgabe mit ihrer Version auswählen.',422);
        }
    }
    public static function prepare(PDO $db,string $actor,array $query,array $post,string $method):array
    {
        $state=['state'=>'invalid','message'=>'','status'=>422,'id'=>'','selected'=>null,'catalogue'=>null,'filter'=>'open','raw'=>[],
            'retry'=>null,'submitted'=>null,'list_route'=>['view'=>'finance','tab'=>'inbox'],'list_keeps_filters'=>false];
        foreach(['inbox_doc','status','inbox_q','inbox_year','inbox_page']as$key)if(array_key_exists($key,$query))$state['raw'][]=['field'=>$key,'value'=>$query[$key],'from'=>'link'];
        foreach(['assign_mode','assign_q','assign_page','assign_expense']as$key)if(array_key_exists($key,$query))$state['raw'][]=['field'=>$key,'value'=>$query[$key],'from'=>'link'];
        if($method==='POST')foreach(['doc','inbox_doc','document_version','inbox_version','expense','inbox_guided']as$key)if(array_key_exists($key,$post))$state['raw'][]=['field'=>$key,'value'=>$post[$key],'from'=>'form'];
        if($method==='POST'&&in_array($post['action']??null,['invoice-payment-report','invoice-payment-withdraw','invoice-payment-allocate'],true))foreach(['entry_id','entry_version','report_version','request_key','paid_on','note','reason','transaction_id','transaction_version','amount']as$key)if(array_key_exists($key,$post))$state['raw'][]=['field'=>$key,'value'=>$post[$key],'from'=>'form'];
        if($method==='POST'&&($post['action']??null)==='invoice-inbox-archive')foreach(['archive','reason']as$key)if(array_key_exists($key,$post))$state['raw'][]=['field'=>$key,'value'=>$post[$key],'from'=>'form'];
        $original=array_intersect_key($query,array_flip(['inbox_doc','status','inbox_q','inbox_year','inbox_page','assign_mode','assign_q','assign_page','assign_expense']));
        if($original)$state['retry']=['view'=>'finance','tab'=>'inbox']+$original;
        $submitted=($post['action']??null)==='create-entry'?($post['inbox_doc']??null):($post['doc']??null);
        if($method==='POST'&&self::identity($submitted)&&$submitted!==($query['inbox_doc']??null))$state['submitted']=['view'=>'finance','tab'=>'inbox','inbox_doc'=>$submitted];
        $own=false;
        try{
            if(!(new Finance($db,$actor))->canRead())throw new DomainException('Für den Rechnungseingang ist aktueller Finanzzugriff erforderlich.',403);
            if($method==='POST')self::command($query,$post);
            if(array_intersect(['assign_mode','assign_q','assign_page','assign_expense','assign_read'],array_keys($query))&&!array_key_exists('inbox_doc',$query))throw new DomainException('Die vollständige ursprüngliche Rechnungskennung prüfen.',422);
            $filter=$query['status']??'open';$term=$query['inbox_q']??'';$year=$query['inbox_year']??'';$page=$query['inbox_page']??'1';
            if(!is_string($filter)||!in_array($filter,['open','assigned','archived'],true)||!is_string($term)||!mb_check_encoding($term,'UTF-8')||mb_strlen($term)>150||preg_match('/[\x00-\x1f\x7f]/',$term)||!is_string($year)||($year!==''&&!preg_match('/\A[0-9]{4}\z/D',$year))||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,5}\z/D',$page))throw new DomainException('Rechnungssuche, Jahr, Stand und Seite vollständig prüfen.',422);
            $state['filter']=$filter;$state['list_route']+=['status'=>$filter,'inbox_q'=>$term,'inbox_year'=>$year,'inbox_page'=>$page];$state['list_keeps_filters']=true;
            if($state['retry']!==null)$state['retry']+=$state['list_route'];
            if(array_key_exists('inbox_doc',$query)&&!self::identity($query['inbox_doc']))throw new DomainException('Die vollständige ursprüngliche Rechnungskennung prüfen.',422);
            $own=!$db->inTransaction();if($own)$db->exec('START TRANSACTION READ ONLY');
            if(array_key_exists('inbox_doc',$query)){
                $state['id']=$query['inbox_doc'];$state['selected']=(new InvoiceInbox($db,$actor))->document($state['id']);if($method==='POST'&&in_array($post['action']??null,['invoice-payment-report','invoice-payment-withdraw','invoice-payment-allocate'],true)&&!in_array($post['entry_id']??null,array_column($state['selected']['entry_links'],'source_id'),true))throw new DomainException('Der ursprüngliche Beleg passt nicht zu dieser Originalrechnung.',409);$state['state']='selected';
            }else{
                $state['catalogue']=(new InvoiceIntakeCatalogue($db,$actor))->page($query);
                if($state['catalogue']['page']!==(int)$page){unset($state['list_route']['inbox_page']);throw new DomainException('Die ursprünglich angeforderte Rechnungsseite ist nicht mehr verfügbar. Die Liste ausdrücklich neu öffnen.',422);}
                $state['state']='list';
            }
            if($own)$db->commit();$state['status']=200;return$state;
        }catch(DomainException $failure){
            if($own&&$db->inTransaction())$db->rollBack();
            $code=$failure->getCode();$state['status']=in_array($code,[403,409,422,503],true)?$code:404;
            $state['state']=$state['status']===403?'blocked':($state['status']===404?'unavailable':'invalid');
            $state['message']=$state['status']===404?'Die ursprünglich angeforderte Rechnung ist derzeit nicht verfügbar.':$failure->getMessage();
        }catch(Throwable){
            if($own&&$db->inTransaction())$db->rollBack();$state['status']=503;$state['state']='unavailable';$state['message']='Der ursprüngliche Rechnungseingang konnte gerade nicht geladen werden. Bitte erneut prüfen.';
        }
        $state['selected']=null;$state['catalogue']=null;return$state;
    }
}
