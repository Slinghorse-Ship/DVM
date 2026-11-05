<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;use PDO;use Kulturhus\Verein\Finance;
/** Existing versioned position writer stays separate from pure draft controls. */
final class PositionEditForm
{
    public static function requested(array$post):bool{return array_key_exists('manual_move',$post)||array_key_exists('position_current',$post)||array_key_exists('position_adopt',$post);}
    public static function handle(PDO$db,string$actor,array&$post,array$query):array
    {
        $finance=new Finance($db,$actor);if(!$finance->canWrite())throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403);
        [$type,$id,$version]=self::target($post,$query);
        $flags=array_intersect(['manual_move','position_current','position_adopt'],array_keys($post));if(count($flags)!==1)throw new DomainException('Genau eine angebotene Positionsansicht wählen.',422);
        $state=$finance->currentPositionState($type,$id);
        if(isset($post['position_current'])){if($post['position_current']!=='1')throw new DomainException('Genau eine angebotene Positionsansicht wählen.',422);return['comparison'=>$state,'notice'=>'Aktuellen gespeicherten Stand gelesen. Deine Eingaben und ihr Versionsstand bleiben erhalten.'];}
        if(isset($post['position_adopt'])){
            if($post['position_adopt']!=='1'||!is_string($post['position_compare_hash']??null)||!hash_equals($state['hash'],$post['position_compare_hash']))throw new DomainException('Der verglichene Belegstand wurde inzwischen geändert. Zuerst erneut vergleichen.',409);
            if($type==='entry'&&in_array($state['source']['payment_state'],['cancelled','reversed'],true))throw new DomainException('Verworfene oder gegengebuchte Belege sind unveränderlich.',409);
            if(!$finance->canWrite())throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403);
            $post['version']=(string)$state['source']['version'];return['notice'=>'Weiterbearbeitung mit dem verglichenen Stand vorbereitet. Änderungen erst gesondert speichern.'];
        }
        ManualFinanceForm::edit($db,$actor,$post);return['notice'=>'Positionen angezeigt. Noch keine Änderung gespeichert und keine Zahlung erfasst.'];
    }
    public static function target(array$post,array$query):array
    {
        $type=$post['position_type']??null;$id=$post['id']??null;$version=$post['version']??null;
        if(!in_array($type,['entry','goods'],true)||!is_string($id)||$id===''||!is_string($version)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$version))throw new DomainException('Ursprünglichen Beleg und Versionsstand vollständig prüfen.',422);
        if(($type==='entry'&&(($query['view']??'')!=='finance'||($query['entry']??null)!==$id))||($type==='goods'&&(($query['view']??'')!=='donations'||($query['tab']??'')!=='goods'||($query['goods']??null)!==$id)))throw new DomainException('Positionsformular gehört zu einem anderen Beleg.',409);
        return[$type,$id,(int)$version];
    }

}
