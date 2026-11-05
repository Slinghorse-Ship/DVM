<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Documents,Permissions};

/** Selecting a notice checks its current original and every actual document scope. */
final class FinanceTaxContext
{
    private static function actor(PDO $db,string $actor):array
    {
        if(!$db->inTransaction())throw new DomainException('Steuerdaten benötigen einen aktuellen Leseschritt.',503);
        return Permissions::finance($db,$actor);
    }
    public static function read(PDO $db,string $actor,array $data):?array
    {
        self::actor($db,$actor);$id=$data['notice_document_id'];
        return $id===''?null:(new Documents($db,$actor))->taxNotice($id);
    }
    public static function originalAccess(PDO $db,string $actor,array $review):void
    {
        self::actor($db,$actor);$source=$review['tax_context']??null;
        if($source!==null)(new Documents($db,$actor))->taxNotice($source['document']['id'],false);
    }
    public static function page(PDO $db,string $actor,mixed $query='',mixed $page='1'):array
    {
        $a=self::actor($db,$actor);
        if(!is_string($query)||preg_match('//u',$query)!==1||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query))throw new DomainException('Bescheidsuche mit höchstens 150 Zeichen eingeben.',422);
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Vollständige verfügbare Bescheidseite auswählen.',422);
        $ids=Db::run($db,"SELECT id FROM documents WHERE club_id=? AND origin='upload' AND state='active' AND JSON_CONTAINS(scopes,'\"finance\"') ORDER BY title,id FOR SHARE NOWAIT",[$a['club_id']])->fetchAll(PDO::FETCH_COLUMN);
        $rows=[];$documents=new Documents($db,$actor);
        foreach($ids as$id){
            try{$source=$documents->taxNotice($id);}
            catch(DomainException $failure){if(in_array($failure->getCode(),[403,404,422],true))continue;throw $failure;}
            if($query!==''&&!str_contains(mb_strtolower($source['document']['id'].' '.$source['document']['title'].' '.$source['file']['name'].' '.$source['path'],'UTF-8'),mb_strtolower($query,'UTF-8')))continue;
            $rows[]=$source;
        }
        $total=count($rows);$pages=max(1,(int)ceil($total/6));
        if((int)$page>$pages)throw new DomainException('Vollständige verfügbare Bescheidseite auswählen.',422);
        return ['rows'=>array_slice($rows,((int)$page-1)*6,6),'total'=>$total,'pages'=>$pages,'page'=>(int)$page,'page_input'=>(string)$page,'query'=>$query];
    }
    public static function select(PDO $db,string $actor,array $input,string $intent):array
    {
        $input=FinanceTaxInput::capture($input);
        if($intent==='clear'){$input['data']['notice_document_id']='';return $input;}
        $id=str_starts_with($intent,'choose:')?substr($intent,7):'';
        if($id===''||preg_match('//u',$id)!==1||mb_strlen($id,'UTF-8')>100||preg_match('/[\x00-\x1f\x7f]/',$id))throw new DomainException('Eigenen zugänglichen Steuerbescheid ausdrücklich auswählen.',422);
        $own=!$db->inTransaction();
        try{if($own)$db->beginTransaction();self::read($db,$actor,['notice_document_id'=>$id]);if($own)$db->commit();}
        catch(\Throwable $failure){if($own&&$db->inTransaction())$db->rollBack();throw $failure;}
        $input['data']['notice_document_id']=$id;return $input;
    }
}
