<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use PDO;use DomainException;use Kulturhus\Verein\{MemberImport,Registry};

final class MemberExcelPage
{
    public static function prepare(PDO$db,string$actor,array$query,#[\SensitiveParameter] array$post,string$method,#[\SensitiveParameter] array$session):array
    {
        $state=['stage'=>null,'export'=>null,'receipt'=>null,'controls'=>null,'mapping'=>[],'start'=>'','preview'=>null,'rows'=>[],'page'=>1,'pages'=>1,'filter'=>'all','error'=>'','status'=>200,'raw'=>[],'preview_current'=>false,'bank_confirmation'=>['mode'=>'password','challenge'=>'','factor'=>'']];
        try{
            if(!(new Registry($db,$actor))->canWrite())throw new DomainException('Mitglieder-Schreibrecht erforderlich.',403);
            if(($query['import']??null)!=='1')throw new DomainException('Excel-Import im ursprünglichen Mitgliederbereich öffnen.',422);
            if(array_key_exists('bank_export_id',$query)){
                MemberExcelExportSession::route($query,$method==='POST'?$post:[]);$export=MemberExcelExportSession::get($db,$actor,$query['bank_export_id'],$session);$state['export']=$export;
                $state['bank_confirmation']=\Kulturhus\Verein\MemberImportBankPermit::state($db,$actor,$export['id'],$export['hash'],$session,'export');return$state;
            }
            if(array_key_exists('import_result',$query)){$state['receipt']=MemberImportSession::receipt($db,$actor,$query['import_result'],$session);if($state['receipt']===null)throw new DomainException('Diese Importbestätigung ist für deinen aktuellen Vereinszugang nicht verfügbar.',409);return$state;}
            $related=$method==='POST'&&in_array($post['action']??null,['member-import-preview','member-import-apply','member-import-discard','member-import-bank-begin','member-import-bank-confirm'],true);
            $id=$related?($post['import_id']??null):($query['import_id']??null);
            if($related&&array_key_exists('import_id',$query)&&$query['import_id']!==$id)throw new DomainException('Link und Vorgang gehören nicht zu derselben ursprünglichen Importdatei.',409);
            if(!$related&&!array_key_exists('import_id',$query))return$state;
            $stage=MemberImportSession::get($db,$actor,$id,$session);$state['stage']=$stage;$controls=MemberImportSession::controls($related?$post:$query,$stage);$state['controls']=$controls;
            $same=$controls['sheet']===$stage['sheet']&&$controls['header']===$stage['header'];$suggested=MemberImport::suggest($stage['book'],$controls['sheet'],$controls['header']);$state['mapping']=$same?$stage['mapping']:$suggested['mapping'];$state['start']=$stage['start'];$state['ambiguous']=$suggested['ambiguous'];
            if($related&&($post['action']??null)==='member-import-preview'){
                if(!is_array($post['mapping']??null)||!is_string($post['default_start']??null))throw new DomainException('Spaltenzuordnung und Eintritt vollständig prüfen.',422);
                foreach($post['mapping']as$key=>$value)if(!isset(MemberImport::FIELDS[$key])||!is_string($value))throw new DomainException('Spaltenzuordnung vollständig prüfen. Mehrteilige Angaben werden nicht auf einzelne Werte reduziert.',422);
                $state['mapping']=$post['mapping'];$state['start']=$post['default_start'];
            }
            $wanted=array_key_exists('preview',$query)||($related&&in_array($post['action']??null,['member-import-preview','member-import-apply','member-import-bank-begin','member-import-bank-confirm'],true));
            if($wanted){$preview=(new MemberImport($db,$actor))->preview($stage['book'],$controls['sheet'],$controls['header'],$state['mapping'],$state['start']);$state['preview']=$preview;$state['preview_current']=is_string($stage['hash'])&&hash_equals($stage['hash'],$preview['hash']);if($state['preview_current']&&$preview['bank_count']>0)$state['bank_confirmation']=\Kulturhus\Verein\MemberImportBankPermit::state($db,$actor,$stage['id'],$stage['hash'],$session);
                $filter=$query['preview_filter']??'all';$page=$query['preview_page']??'1';if(!is_string($filter)||!in_array($filter,['all','review','existing','new','convert'],true)||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,3}\z/D',$page))throw new DomainException('Vorschaufilter und Seite vollständig prüfen.',422);
                $rows=array_values(array_filter($preview['rows'],static fn(array$r):bool=>$filter==='all'||$r['state']===$filter));$state['filter']=$filter;$state['pages']=max(1,(int)ceil(count($rows)/25));$state['page']=min((int)$page,$state['pages']);$state['rows']=array_slice($rows,($state['page']-1)*25,25);$state['filtered_count']=count($rows);
            }
        }catch(DomainException$failure){$state['error']=$failure->getMessage();$state['status']=in_array($failure->getCode(),[403,409,422],true)?$failure->getCode():422;$state['raw']=['query'=>array_intersect_key($query,array_flip(['import','import_id','import_result','bank_export_id','sheet','header','preview_filter','preview_page'])),'post'=>array_diff_key($post,['csrf'=>true,'bank_password'=>true,'bank_code'=>true,'bank_challenge'=>true])];$state['rows']=[];$state['preview']=null;$state['preview_current']=false;}
        return$state;
    }
}
