<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,LiveUploads,Permissions};

/** The existing receipt form's private upload and pure position controls. No financial writer. */
final class ManualFinanceForm
{
    public static function existing(PDO $db,string $actor,string $key):?array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Belegkennung vollständig prüfen.',422);
        $identity=Permissions::actor($db,$actor);$finance=new Finance($db,$actor);if(!$finance->canRead())throw new DomainException('Finanzberechtigung erforderlich.',403);
        $q=$db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND request_key=?');$q->execute([$identity['club_id'],$key]);$id=$q->fetchColumn();
        if(!$id)return null;$entry=$finance->entry($id);$now=Permissions::actor($db,$actor);if($now['club_id']!==$identity['club_id']||!$finance->canRead())throw new DomainException('Vereinszugang oder Finanzberechtigung geändert.',409);return $entry;
    }
    /** Stage a genuine ordinary multipart original using the same private pipeline as scripted uploads. */
    public static function retainUpload(PDO $db,string $actor,array &$post,array &$files):void
    {
        $file=$files['receipt_file']??null;if($file===null||(is_array($file)&&($file['error']??null)===UPLOAD_ERR_NO_FILE))return;
        if(!is_string($post['request_key']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$post['request_key']))throw new DomainException('Ursprüngliche Belegkennung vollständig prüfen.',422);
        if(isset($post['live_uploads']['receipt_file'])&&$post['live_uploads']['receipt_file']!==[])throw new DomainException('Eine Originaldatei ist bereits ausgewählt. Diese zuerst bewusst entfernen, bevor eine andere Datei gewählt wird.',422);
        if(($post['inbox_doc']??'')!=='')throw new DomainException('Das Original aus dem Rechnungseingang ist bereits ausgewählt. Bitte keine zweite Datei hinzufügen.',422);
        LiveUploads::permit($db,$actor,'create-entry',$post);
        $uploadKey=substr(hash('sha256','manual-receipt-upload:'.$post['request_key']),0,32);
        $saved=LiveUploads::stage($actor,'create-entry','receipt_file',is_array($file)?$file:[],$uploadKey);
        $post['live_uploads']['receipt_file']=[$saved['token']];unset($files['receipt_file']);
    }
    public static function removeUpload(PDO $db,string $actor,array &$post):void
    {
        LiveUploads::permit($db,$actor,'create-entry',$post);$tokens=$post['live_uploads']['receipt_file']??[];
        if(!is_array($tokens)||!array_is_list($tokens)||count($tokens)!==1||!is_string($tokens[0]))throw new DomainException('Genau die eigene ausgewählte Originaldatei prüfen.',422);
        LiveUploads::description($actor,$tokens[0],'create-entry','receipt_file');LiveUploads::remove($actor,$tokens[0]);unset($post['live_uploads']['receipt_file']);
    }
    public static function edit(PDO $db,string $actor,array &$post):void
    {
        $finance=new Finance($db,$actor);if(!$finance->canWrite())throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403);
        $move=$post['manual_move']??null;if(!is_string($move)||!preg_match('/\A(review|add|remove:([0-9]|[1-4][0-9]))\z/D',$move,$parts))throw new DomainException('Angebotene Beleg- oder Positionsansicht ausdrücklich wählen.',422);
        if($move==='review')return;
        $positions=Finance::positionsFromForm($post);$count=count($positions);
        if($move==='add'){
            if($count>=50)throw new DomainException('Bitte 1 bis 50 Positionen erfassen.',422);
            foreach(['position_description'=>'','position_amount'=>'','position_sphere'=>'','position_account'=>'','position_tax'=>'0','position_booking_key'=>'','position_input_tax'=>'unknown']as$field=>$value)if(isset($post[$field]))$post[$field][]=$value;
            return;
        }
        $index=(int)$parts[2];if($count<=1||$index>=$count)throw new DomainException('Mindestens eine eigene Position behalten und die gewählte Zeile prüfen.',422);
        foreach(['position_description','position_amount','position_sphere','position_account','position_tax','position_booking_key','position_input_tax']as$field)if(isset($post[$field]))array_splice($post[$field],$index,1);
    }
    public static function upload(string $actor,array $post):?array
    {
        $tokens=$post['live_uploads']['receipt_file']??[];if($tokens===[])return null;
        if(!is_array($tokens)||!array_is_list($tokens)||count($tokens)!==1||!is_string($tokens[0]))throw new DomainException('Genau die eigene ausgewählte Originaldatei prüfen.',422);
        return LiveUploads::description($actor,$tokens[0],'create-entry','receipt_file')+['token'=>$tokens[0]];
    }
}
