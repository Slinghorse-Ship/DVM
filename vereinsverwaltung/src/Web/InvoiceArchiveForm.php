<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
/** Read-only form state for one already authorized original; never performs an archive. */
final class InvoiceArchiveForm
{
    public static function prepare(array $query,array $post,array $original,bool $canEdit):array
    {
        $submitted=($post['action']??null)==='invoice-inbox-archive';
        $read=['view'=>'finance','tab'=>'inbox']+array_intersect_key($query,array_flip(['inbox_doc','status','inbox_q','inbox_year','inbox_page']));
        $out=['state'=>'form','submitted'=>$submitted,'can_edit'=>$canEdit&&($original['entry_links']??[])===[],'id'=>$original['id'],'version'=>(string)$original['version'],'archive'=>$original['state']==='archived'?'0':'1','reason'=>'','reason_error'=>false,'raw'=>[],'read_route'=>$read,'message'=>''];
        if(!$submitted){if(!$out['can_edit'])$out['state']='hidden';return$out;}
        foreach(['doc','document_version','archive','reason']as$field)if(array_key_exists($field,$post))$out['raw'][$field]=$post[$field];
        $out['version']=$post['document_version']??null;$out['archive']=$post['archive']??null;$out['reason']=$post['reason']??null;
        if(($query['inbox_doc']??null)!==$original['id']||($post['doc']??null)!==$original['id']||!is_string($out['version'])||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$out['version'])||(int)$out['version']>2147483647||!in_array($out['archive'],['0','1'],true)||!is_string($out['reason'])||!mb_check_encoding($out['reason'],'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$out['reason'])){$out['state']='invalid';$out['message']='Die übermittelten Archivangaben können nicht vollständig verwendet werden.';}
        elseif(!$out['can_edit']){$out['state']='blocked';$out['message']='Für diesen Archivschritt fehlt das aktuelle Bearbeitungsrecht oder die Originaldatei ist bereits einem Beleg zugeordnet.';}
        elseif($out['version']!==(string)$original['version']||$original['state']!==($out['archive']==='1'?'active':'archived')){$out['state']='conflict';$out['message']='Die ursprüngliche Archiventscheidung passt nicht mehr zum aktuellen Originalstand. Bitte den aktuellen Stand derselben Rechnung gesondert lesen.';}
        else $out['reason_error']=mb_strlen($out['reason'],'UTF-8')>1000;
        return$out;
    }
}
