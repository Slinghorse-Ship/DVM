<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Settings as ClubSettings,SettingsSchema,Permissions,DocumentPreview};

/** Only actual sources used by these new sample documents enter their review. */
final class TextContext
{
    public static function read(PDO $db,array $actor):array
    {
        $settings=new ClubSettings($db,$actor['id']);$club=$settings->section('club');$paper=$settings->section('paper');$bank=$actor['finance_access']!=='none'?$settings->section('bank'):null;
        $profile=Db::run($db,'SELECT payload,version FROM club_settings WHERE club_id=? AND section=? FOR SHARE NOWAIT',[$actor['club_id'],'account:'.$actor['id']])->fetch();$account=$profile?json_decode($profile['payload'],true,64,JSON_THROW_ON_ERROR):SettingsSchema::defaults('account');
        $resolved=$settings->paperForDocument($paper['data']);$logo=null;
        if($resolved['logo']!==''){
            $asset=PaperAssets::load($db,$actor['club_id'],$resolved['logo']);$image=@getimagesizefromstring($asset['bytes']);
            if(!$image)throw new DomainException('Briefpapierbild konnte nicht gelesen werden.',503);
            $logo=['id'=>$resolved['logo'],'mime'=>$asset['mime'],'bytes'=>strlen($asset['bytes']),'sha256'=>$asset['sha256'],'width'=>$image[0],'height'=>$image[1]];
        }
        return['club'=>$club,'bank'=>$bank,'paper'=>$paper,'sender'=>array_intersect_key($resolved,array_flip(['sender_name','sender_street','sender_address2','sender_city'])),'profile'=>['version'=>(int)($profile['version']??0),'display_name'=>$account['display_name']??''],'logo'=>$logo];
    }
    public static function preview(ClubSettings $settings,string $section,array $data,string $template,array $context):array
    {
        TextInput::template($section,$template);$values=$settings->previewValues();$values['BEARBEITER']=$context['profile']['display_name'];$bank=$context['bank']['data']??[];
        foreach(['VEREINS_IBAN'=>'iban','VEREINS_BIC'=>'bic','KONTOINHABER'=>'holder','GLAEUBIGER_ID'=>'creditor','PAYPAL_LINK'=>'paypal']as$key=>$field)$values[$key]=$bank[$field]??'';
        return DocumentPreview::build($settings,$context['paper']['data'],$section,$template,$data,$values);
    }
    public static function originalAccess(PDO $db,string $actor,array $review):void
    {
        $a=Permissions::actor($db,$actor);
        if(($review['text_context']['bank']??null)!==null&&!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Aktuelle Finanzleseberechtigung für die damals geprüfte Bankquelle erforderlich.',403);
    }
}
