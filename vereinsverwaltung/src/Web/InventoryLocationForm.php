<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{Inventory,Permissions};

/** The existing location form keeps its whole original values and exact opened source. */
final class InventoryLocationForm
{
    public const DATA=['name','holder_id','building','room','shelf','active','notes'];
    public const META=['target','version','request_key'];
    public static function original(array $body):array
    {
        $original=array_intersect_key($body,array_flip(self::META));
        if(array_key_exists('data',$body))$original['data']=is_array($body['data'])?array_intersect_key($body['data'],array_flip(self::DATA)):$body['data'];
        return $original;
    }
    public static function key(mixed $key):string
    {
        if(!is_string($key)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Lagerort-Vorgangskennung vollständig prüfen.',422);
        return $key;
    }
    public static function target(array $body,array $query):string
    {
        foreach(self::META as $name)if(!array_key_exists($name,$body)||!is_string($body[$name]))throw new DomainException('Ursprüngliche Lagerortangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        if(!is_array($body['data']??null)||array_diff(array_keys($body['data']),self::DATA))throw new DomainException('Ursprüngliche Lagerortangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        foreach(self::DATA as $name)if(!array_key_exists($name,$body['data'])||!is_string($body['data'][$name]))throw new DomainException('Ursprüngliche Lagerortangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        $id=$body['target'];$version=$body['version'];
        if(strlen($id)>80||preg_match('//u',$id)!==1||preg_match('/[\x00-\x1f\x7f]/',$id)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||(int)$version>2147483647||!in_array($body['data']['active'],['0','1'],true))throw new DomainException('Ursprüngliche Lagerortkennung, Fassung und Status vollständig prüfen.',422);
        $key=self::key($body['request_key']);
        if(($query['view']??null)!=='inventory'||($query['tab']??null)!=='locations')throw new DomainException('Lagerortangaben am ursprünglich geöffneten Lagerort erneut prüfen.',409);
        if(array_key_exists('location_request',$query)&&$query['location_request']!==$key)throw new DomainException('Lagerortangaben am ursprünglich geöffneten Lagerort erneut prüfen.',409);
        if($id===''){
            if(($query['new_location']??null)!=='1'||array_key_exists('location',$query)||$version!=='0')throw new DomainException('Lagerortangaben am ursprünglich geöffneten Lagerort erneut prüfen.',409);
        }elseif(($query['location']??null)!==$id||array_key_exists('new_location',$query))throw new DomainException('Lagerortangaben am ursprünglich geöffneten Lagerort erneut prüfen.',409);
        return $id;
    }
    public static function opened(Inventory $inventory,mixed $id):array
    {
        if(!is_string($id)||$id===''||strlen($id)>80||preg_match('//u',$id)!==1||preg_match('/[\x00-\x1f\x7f]/',$id))throw new DomainException('Lagerortkennung vollständig prüfen.',422);
        if(!$inventory->canRead())throw new DomainException('Inventar-Leserecht für Lagerorte erforderlich.',403);
        try{return $inventory->location($id);}catch(DomainException $failure){throw new DomainException('Lagerort nicht verfügbar.',404,$failure);}
    }
    public static function blocked(array $body,array $query,?string $opened):bool
    {
        if(($body['action']??null)!=='inventory-location')return false;
        try{return self::target($body,$query)!==($opened??'');}catch(DomainException){return true;}
    }
    /** A committed creation already has this same event; no extra operation store is needed. */
    public static function saved(PDO $db,string $actor,mixed $key):?array
    {
        if(!(new Inventory($db,$actor))->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diesen Lagerortvorgang erforderlich.',403);
        $key=self::key($key);$identity=Permissions::actor($db,$actor);
        $q=$db->prepare('SELECT actor_id,action,object_id,details FROM inventory_events WHERE club_id=? AND request_key=?');$q->execute([$identity['club_id'],$key]);$event=$q->fetch(PDO::FETCH_ASSOC);
        if(!$event)return null;
        $details=json_decode($event['details'],true);
        if($event['actor_id']!==$identity['id']||$event['action']!=='location'||!is_array($details)||($details[0]??null)!==0)throw new DomainException('Diese Lagerortkennung gehört zu einem anderen Vorgang.',409);
        return self::opened(new Inventory($db,$actor),$event['object_id']);
    }
    public static function state(PDO $db,string $actor,array $body,array $query):array
    {
        $inventory=new Inventory($db,$actor);$requested=($body['action']??null)==='inventory-location';
        $state=['location'=>null,'blocked'=>false,'saved'=>null,'key'=>null,'error'=>'','status'=>200,'redirect'=>null,'original'=>$requested?self::original($body):null];
        try{
            if(array_key_exists('location_request',$query))$state['key']=self::key($query['location_request']);
            if(array_key_exists('location',$query)){
                $state['location']=self::opened($inventory,$query['location']);
                if($state['location']['id']!==$query['location'])throw new DomainException('Lagerortangaben am ursprünglich geöffneten Lagerort erneut prüfen.',409);
            }
            if(array_key_exists('new_location',$query)&&($query['new_location']!=='1'||array_key_exists('location',$query)))throw new DomainException('Lagerortangaben am ursprünglich geöffneten Lagerort erneut prüfen.',409);
            if($requested)$state['blocked']=self::blocked($body,$query,$state['location']['id']??null);
            if(($query['new_location']??null)==='1'){
                if(!$inventory->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diesen Lagerortvorgang erforderlich.',403);
                $candidate=array_key_exists('location_request',$query)?$query['location_request']:($requested?($body['request_key']??null):null);
                $state['key']=$candidate!==null?self::key($candidate):bin2hex(random_bytes(16));
                $state['saved']=self::saved($db,$actor,$state['key']);
                if($state['saved']){
                    $state['blocked']=true;
                    if(!$requested)$state['redirect']=['view'=>'inventory','tab'=>'locations','location'=>$state['saved']['id']];
                }elseif(!$requested&&!array_key_exists('location_request',$query))$state['redirect']=['view'=>'inventory','tab'=>'locations','new_location'=>'1','location_request'=>$state['key']];
            }
        }catch(\Throwable $failure){
            $state['blocked']=true;$state['status']=self::status($failure);
            $state['error']=$failure instanceof DomainException?$failure->getMessage():'Lagerort konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.';
        }
        return $state;
    }
    public static function status(\Throwable $failure):int
    {
        if(in_array($failure->getCode(),[403,404,409,422,503],true))return $failure->getCode();
        if($failure instanceof \PDOException)return 503;
        if(!$failure instanceof DomainException)return 503;
        return match($failure->getMessage()){
            'Keine Berechtigung für Material, Inventar und Ausleihen.','Zugang nicht aktiv.'=>403,
            'Eintrag nicht verfügbar.'=>404,
            'Der Eintrag wurde inzwischen geändert. Bitte neu laden und erneut prüfen.','Diese Vorgangskennung wurde bereits mit anderen Eingaben verwendet.','Lagerort ist noch belegt. Bestände und offene Ausleihen zuerst auflösen.','Bei belegten Lagerorten den Aufbewahrer über einen neuen Lagerort und eine bestätigte Umlagerung wechseln.'=>409,
            default=>422,
        };
    }
}
