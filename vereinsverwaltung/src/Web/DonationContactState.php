<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{ContactChoicePicker,Finance,Registry};

/** A deliberate source comparison changes form state, never the donation or its contact. */
final class DonationContactState
{
    public static function target(array $post,array $query):void
    {
        $type=$post['source_type']??null;$id=$post['source_id']??null;
        $matches=is_string($id)&&$id!==''&&in_array($query['view']??null,['finance','donations'],true)
            &&(($type==='entry'&&($query['entry']??null)===$id)
                ||($type==='goods'&&($query['view']??null)==='donations'&&($query['tab']??null)==='goods'&&($query['goods']??null)===$id));
        if(!$matches)throw new DomainException('Ursprünglichen Spendenbeleg mit seiner Kontaktauswahl erneut öffnen.',409);
    }

    public static function fields(array $post):array
    {
        $state=ContactChoicePicker::restore(array_replace($post,['action'=>'donation-contact','contact_browse'=>'page']));
        $data=$state['data']+['contact_query'=>$state['contact_query'],'contact_page'=>(string)$state['contact_page']];
        if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$data['source_version']))throw new DomainException('Ursprüngliche Spendenfassung prüfen.',422);
        return $data;
    }

    public static function review(PDO $db,string $actor,array $post,array $query,bool $adopt=false):array
    {
        self::target($post,$query);$finance=new Finance($db,$actor);$registry=new Registry($db,$actor);
        if(!$finance->canWrite()||!$registry->canRead())throw new DomainException('Finanzschreibrecht und Kontaktleserecht erforderlich.',403);
        $data=self::fields($post);$type=$data['source_type'];$source=$type==='goods'?$finance->goodsEntry($data['source_id']):$finance->entry($data['source_id']);
        if($type==='entry'&&$source['kind']!=='donation')throw new DomainException('Geldspende erforderlich.',422);
        $choices=$registry->contactChoicePage($data);
        $data['contact_query']=$choices['query'];$data['contact_page']=(string)$choices['page'];
        $selected=$data['contact_id']===''?null:$registry->contact($data['contact_id']);
        $linked=$finance->donationContact($type,$data['source_id']);
        $hash=hash('sha256',json_encode([$data,$source,$linked,$selected?[$selected['id'],$selected['name'],$selected['version']]:null],JSON_THROW_ON_ERROR));
        if($adopt&&(!is_string($post['contact_review_hash']??null)||!hash_equals($hash,$post['contact_review_hash'])))throw new DomainException('Spende oder Auswahl seit dem Vergleich geändert. Erneut vergleichen.',409);
        $original=$data['source_version'];if($adopt)$data['source_version']=(string)$source['version'];
        return ['data'=>$data,'source'=>$source,'linked'=>$linked,'selected'=>$selected,'hash'=>$hash,'original_version'=>$original,'adopted'=>$adopt];
    }
}
