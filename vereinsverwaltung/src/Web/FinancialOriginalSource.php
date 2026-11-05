<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,Permissions};

/** A requested financial original never becomes a substitute catalogue. Read requests only. */
final class FinancialOriginalSource
{
    public static function request(string $view,array $query,string $method='GET'):array
    {
        $none=['state'=>'none','kind'=>null,'field'=>null,'raw'=>null,'row'=>null,'message'=>'','status'=>200];
        if(!in_array($method,['GET','HEAD'],true)||!in_array($view,['finance','donations'],true))return $none;
        $keys=array_values(array_intersect(['entry','transaction'],array_keys($query)));
        if($keys===[])return $none;
        if(count($keys)!==1)return array_replace($none,['state'=>'invalid','kind'=>'ambiguous','message'=>'Genau einen ursprünglichen Beleg oder Kontoumsatz öffnen.','status'=>422]);
        $field=$keys[0];$raw=is_string($query[$field])?$query[$field]:null;
        $result=array_replace($none,['state'=>'invalid','kind'=>$field==='entry'?'receipt':'bank','field'=>$field,'raw'=>$raw,'message'=>'Die ursprüngliche Quellenkennung vollständig prüfen. Diese Eingabeform wird nicht übernommen.','status'=>422]);
        if($raw!==null&&preg_match('/\A[a-f0-9]{32}\z/D',$raw))$result=array_replace($result,['state'=>'requested','message'=>'','status'=>200]);
        return $result;
    }

    public function __construct(private PDO $db,private string $actor){}

    /** Existing financial services supply current locked originals; no writes or new monetary rules. */
    public function read(array $request):array
    {
        if(($request['state']??null)!=='requested')return $request;
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->beginTransaction();$actor=Permissions::finance($this->db,$this->actor);
            $finance=new Finance($this->db,$this->actor);
            $row=$request['kind']==='receipt'?$finance->currentEntry($request['raw']):$finance->transactionDetail($request['raw'],true);
            if($row['id']!==$request['raw'])throw new DomainException('Ursprüngliche Quelle nicht verfügbar.',404);
            if($own)$this->db->commit();
            try{$current=Permissions::finance($this->db,$this->actor);}catch(DomainException $failure){throw new DomainException('Vereinszugang oder Finanzberechtigung geändert.',403,$failure);}
            if($current['club_id']!==$actor['club_id'])throw new DomainException('Vereinszugang geändert.',403);
            return array_replace($request,['state'=>'ready','row'=>$row]);
        }catch(\Throwable $failure){
            if($own&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException){
                if(!in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw $failure;
                return array_replace($request,['state'=>'unavailable','status'=>503,'message'=>'Die ursprüngliche Finanzquelle wird gerade bearbeitet. Bitte erneut prüfen.']);
            }
            if(!$failure instanceof DomainException)throw $failure;
            return array_replace($request,['state'=>'unavailable','status'=>in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():404,'message'=>$failure->getCode()===503?'Die ursprüngliche Finanzquelle wird gerade bearbeitet. Bitte erneut prüfen.':($request['kind']==='receipt'?'Der ursprünglich angeforderte Beleg ist derzeit nicht verfügbar.':'Der ursprünglich angeforderte Kontoumsatz ist derzeit nicht verfügbar.')]);
        }
    }
}
