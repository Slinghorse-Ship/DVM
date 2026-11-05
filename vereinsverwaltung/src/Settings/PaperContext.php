<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Settings as ClubSettings,SettingsSchema,Permissions,DocumentPreview};

/** Resolve the actual current sources for a new layout; issued documents keep their own snapshots. */
final class PaperContext
{
    public static function read(PDO $db,array $actor,array $data,?array $upload,?string $bytes):array
    {
        $settings=new ClubSettings($db,$actor['id']);$club=$settings->section('club');$bank=$actor['finance_access']!=='none'?$settings->section('bank'):null;
        $sender=array_intersect_key($data,array_flip(['sender_name','sender_street','sender_address2','sender_city']));
        if($data['sender_source']==='club')$sender=['sender_name'=>$club['data']['name'],'sender_street'=>$club['data']['street'],'sender_address2'=>$club['data']['address2'],'sender_city'=>trim($club['data']['postcode'].' '.$club['data']['city'])];
        $template=Db::run($db,"SELECT payload,version FROM club_settings WHERE club_id=? AND section='texts_invoices' FOR SHARE NOWAIT",[$actor['club_id']])->fetch();
        $templates=['version'=>(int)($template['version']??0),'data'=>$template?json_decode($template['payload'],true,64,JSON_THROW_ON_ERROR):SettingsSchema::defaults('texts_invoices')];
        $profile=Db::run($db,'SELECT payload,version FROM club_settings WHERE club_id=? AND section=? FOR SHARE NOWAIT',[$actor['club_id'],'account:'.$actor['id']])->fetch();$account=$profile?json_decode($profile['payload'],true,64,JSON_THROW_ON_ERROR):SettingsSchema::defaults('account');
        $logo=null;
        if($upload!==null)$logo=['id'=>null]+$upload;
        elseif($data['logo']!==''){
            $asset=PaperAssets::load($db,$actor['club_id'],$data['logo']);$image=@getimagesizefromstring($asset['bytes']);
            if(!$image)throw new DomainException('Briefpapierbild konnte nicht gelesen werden.',503);
            $logo=['id'=>$data['logo'],'mime'=>$asset['mime'],'bytes'=>strlen($asset['bytes']),'sha256'=>$asset['sha256'],'width'=>$image[0],'height'=>$image[1]];
        }
        return['club'=>$club,'sender'=>$sender,'bank'=>$bank,'templates'=>$templates,'profile'=>['version'=>(int)($profile['version']??0),'display_name'=>$account['display_name']??''],'logo'=>$logo];
    }
    public static function originalAccess(PDO $db,string $actor,array $review):void
    {
        $a=Permissions::actor($db,$actor);
        if(($review['paper_context']['bank']??null)!==null&&!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Aktuelle Finanzleseberechtigung für die damals geprüfte Bankquelle erforderlich.',403);
    }
    public static function preview(ClubSettings $settings,array $data,array $context,?string $logo):array
    {
        $values=$settings->previewValues();$values['BEARBEITER']=$context['profile']['display_name'];
        $bank=$context['bank']['data']??[];
        foreach(['VEREINS_IBAN'=>'iban','VEREINS_BIC'=>'bic','KONTOINHABER'=>'holder','GLAEUBIGER_ID'=>'creditor','PAYPAL_LINK'=>'paypal']as$key=>$field)$values[$key]=$bank[$field]??'';
        $document=DocumentPreview::build($settings,$data,'texts_invoices','invoice',$context['templates']['data'],$values);
        if($logo!==null)$document['assets']['logo']=['mime'=>'image/png','base64'=>base64_encode($logo)];
        return$document;
    }
    public static function resultMatches(array $result,array $review):bool
    {
        $expected=$review['normalized'];$actual=$result['data'];
        if($review['input']['logo']!==null){if(!is_string($actual['logo']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$actual['logo']))return false;$expected['logo']=$actual['logo'];}
        return $actual===$expected&&(int)$result['version']===(int)$review['current']['version']+1;
    }
}
