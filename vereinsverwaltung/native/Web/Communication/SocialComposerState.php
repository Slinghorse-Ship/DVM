<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Kulturhus\Native\Shared\RichText;
/** Retain human input and its submitted concurrency version without writing a draft. */
final class SocialComposerState
{
    public static function slides(array $post,bool $saving=false):array
    {
        $rows=array_key_exists('story_slides',$post)?self::cards(['carousel_cards'=>$post['story_slides']]):self::cards(['carousel_json'=>$post['story_json']??'[]']);
        // Empty native-form placeholders contain no authored content. Incomplete
        // nonempty slides still reach the ordinary storage validation.
        if($saving&&array_key_exists('story_slides',$post))$rows=array_values(array_filter($rows,static fn(array $row):bool=>$row!==['title'=>'','text'=>'','media_ref'=>'']));
        return $rows;
    }
    public static function cards(array $post):array
    {
        $cards=$post['carousel_cards']??null;
        if($cards===null){$raw=$post['carousel_json']??'[]';if(!is_string($raw)||strlen($raw)>50000)throw new DomainException('Karussell-Bilder prüfen.');try{$cards=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Karussell-Bilder prüfen.');}}
        if(!is_array($cards)||!array_is_list($cards)||count($cards)>10)throw new DomainException('Höchstens zehn Karussell-Bilder auswählen.');
        $result=[];foreach($cards as$card){if(!is_array($card))throw new DomainException('Karussell-Bild prüfen.');$row=[];foreach(['title'=>200,'text'=>1200,'media_ref'=>32]as$key=>$max){$value=$card[$key]??'';if(!is_string($value)||strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new DomainException('Karussell-Bild prüfen.');$row[$key]=$value;}if($row['media_ref']!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$row['media_ref']))throw new DomainException('Eigenes Foto auswählen.');$result[]=$row;}return$result;
    }
    public static function restore(array $post):array
    {
        $document=[];foreach(['id'=>32,'version'=>10,'title'=>300,'summary'=>2000,'body'=>100000,'body_html'=>1000000,'kind'=>20,'template'=>64,'blocks_json'=>150000,'destination_url'=>2000]as$key=>$max){$value=$post[$key]??($key==='blocks_json'?'[]':'');if(!is_string($value)||strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new DomainException('Redaktionsfeld prüfen.');$document[$key]=$value;}
        if(!preg_match('/\A(0|[1-9][0-9]{0,9})\z/D',$document['version'])||!in_array($document['kind'],['post','story','reel','carousel'],true)||($document['id']!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$document['id'])))throw new DomainException('Redaktionsformular erneut öffnen.');
        if(array_key_exists('save_request_key',$post)){if(!is_string($post['save_request_key'])||!preg_match('/\A[a-f0-9]{32}\z/D',$post['save_request_key']))throw new DomainException('Ursprüngliches Social-Formular öffnen.');$document['save_request_key']=$post['save_request_key'];}
        if(array_key_exists('body_html',$post)){$document['body_html']=RichText::sanitize($document['body_html']);$document['body']=RichText::plain($document['body_html']);}else unset($document['body_html']);
        $document['carousel']=self::cards($post);$document['channels']=array_values(array_intersect((array)($post['channels']??[]),['facebook','instagram']));
        $document['image_design']=\Kulturhus\Native\Communication\ImageDesign::options(['layout'=>$post['image_layout']??'editorial','palette'=>$post['image_palette']??'forest','focal_x'=>$post['image_focal_x']??50,'focal_y'=>$post['image_focal_y']??50]);
        $document['story']=self::slides($post);$document['story_link_requested']=isset($post['story_link_requested']);return['document'=>$document];
    }
    public static function browseStory(array $post):array
    {
        $state=self::restore($post);if($state['document']['kind']!=='story')throw new DomainException('Story in der Redaktion öffnen.');$slides=$state['document']['story'];$action=$post['story_browse']??null;
        if($action==='add'){if(count($slides)>=10)throw new DomainException('Höchstens zehn Story-Folien auswählen.');$slides[]=['title'=>'','text'=>'','media_ref'=>''];}
        elseif(is_string($action)&&preg_match('/\A(up|down|remove):([0-9])\z/D',$action,$m)){$index=(int)$m[2];if(!isset($slides[$index]))throw new DomainException('Story-Folie auswählen.');if($m[1]==='remove')array_splice($slides,$index,1);else{$next=$index+($m[1]==='up'?-1:1);if(isset($slides[$next]))[$slides[$index],$slides[$next]]=[$slides[$next],$slides[$index]];}}
        else throw new DomainException('Story-Aktion prüfen.');
        $state['document']['story']=$slides;return $state;
    }
    public static function browse(array $post):array
    {
        $state=self::restore($post);if($state['document']['kind']!=='carousel')throw new DomainException('Karussell öffnen.');$cards=$state['document']['carousel'];$action=$post['carousel_browse']??null;
        if($action==='add'){if(count($cards)>=10)throw new DomainException('Höchstens zehn Bilder auswählen.');$cards[]=['title'=>'','text'=>'','media_ref'=>''];}
        elseif(is_string($action)&&preg_match('/\A(up|down|remove):([0-9])\z/D',$action,$m)){ $index=(int)$m[2];if(!isset($cards[$index]))throw new DomainException('Karussell-Bild auswählen.');if($m[1]==='remove'){if(count($cards)<=2)throw new DomainException('Mindestens zwei Bilder behalten.');array_splice($cards,$index,1);}else{$next=$index+($m[1]==='up'?-1:1);if(isset($cards[$next]))[$cards[$index],$cards[$next]]=[$cards[$next],$cards[$index]];}}
        else throw new DomainException('Karussell-Aktion prüfen.');
        $state['document']['carousel']=$cards;return$state;
    }
}
