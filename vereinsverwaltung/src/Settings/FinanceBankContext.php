<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Permissions;

/** Current metadata only; selecting an invoice account never changes a balance. */
final class FinanceBankContext
{
    private static function actor(PDO $db,string $actor):array
    {
        if(!$db->inTransaction())throw new DomainException('Rechnungsbankdaten benötigen einen aktuellen Leseschritt.',503);
        return Permissions::finance($db,$actor);
    }
    public static function read(PDO $db,string $actor,array $data):?array
    {
        $a=self::actor($db,$actor);$id=$data['account_id'];
        if($id==='')return null;
        $row=Db::run($db,'SELECT * FROM finance_accounts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$id])->fetch();
        if(!$row)throw new DomainException('Zugeordnetes eigenes Rechnungskonto ist nicht verfügbar.',422);
        if($row['kind']!=='offline')throw new DomainException('Die Rechnungsbankverbindung muss einem Bank-/Offlinekonto zugeordnet sein.',422);
        $settings=Db::run($db,'SELECT * FROM finance_account_settings WHERE club_id=? AND account_id=? FOR SHARE NOWAIT',[$a['club_id'],$id])->fetch();
        return['account'=>$row,'settings'=>$settings?:['club_id'=>$a['club_id'],'account_id'=>$id,'active'=>1,'tax_account'=>'','fints_read'=>1,'fints_write'=>0,'version'=>0]];
    }
    public static function page(PDO $db,string $actor,mixed $query='',mixed $page='1'):array
    {
        $a=self::actor($db,$actor);
        if(!is_string($query)||preg_match('//u',$query)!==1||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query))throw new DomainException('Rechnungskontensuche mit höchstens 150 Zeichen eingeben.',422);
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Vollständige verfügbare Rechnungskontenseite auswählen.',422);
        $rows=Db::run($db,"SELECT * FROM finance_accounts WHERE club_id=? AND kind='offline' ORDER BY name,id FOR SHARE NOWAIT",[$a['club_id']])->fetchAll();
        $rows=array_values(array_filter($rows,static fn(array $row):bool=>$query===''||str_contains(mb_strtolower($row['id'].' '.$row['name'],'UTF-8'),mb_strtolower($query,'UTF-8'))));
        $total=count($rows);$pages=max(1,(int)ceil($total/6));if((int)$page>$pages)throw new DomainException('Vollständige verfügbare Rechnungskontenseite auswählen.',422);
        $out=[];
        foreach(array_slice($rows,((int)$page-1)*6,6)as$row)$out[]=self::read($db,$actor,['account_id'=>$row['id']]);
        return['rows'=>$out,'total'=>$total,'pages'=>$pages,'page'=>(int)$page,'page_input'=>(string)$page,'query'=>$query];
    }
    public static function select(PDO $db,string $actor,array $input,string $intent):array
    {
        $input=FinanceBankInput::capture($input);
        if($intent==='clear'){$input['data']['account_id']='';return$input;}
        $id=str_starts_with($intent,'choose:')?substr($intent,7):'';
        if($id===''||preg_match('//u',$id)!==1||mb_strlen($id,'UTF-8')>100||preg_match('/[\x00-\x1f\x7f]/',$id))throw new DomainException('Eigenes Rechnungskonto ausdrücklich auswählen.',422);
        $own=!$db->inTransaction();
        try{if($own)$db->beginTransaction();self::read($db,$actor,['account_id'=>$id]);if($own)$db->commit();}
        catch(\Throwable $failure){if($own&&$db->inTransaction())$db->rollBack();throw$failure;}
        $input['data']['account_id']=$id;return$input;
    }
}
