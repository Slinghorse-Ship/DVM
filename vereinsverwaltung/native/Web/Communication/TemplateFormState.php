<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Shared\RichText;

/** Browsing and one-block editing retain human input without saving or advancing a version. */
final class TemplateFormState
{
    public const TYPES=['richtext'=>'Formatierter Text','text'=>'Text','heading'=>'Überschrift','image'=>'Bild','button'=>'Schaltfläche','divider'=>'Trennlinie','event'=>'Veranstaltung'];
    public static function plainHtml(string $body): string { return $body===''?'':'<p>'.nl2br(\Kulturhus\Native\Web\Html::e($body),false).'</p>'; }
    public static function number(mixed $value,int $max): int
    {
        if((!is_int($value)&&!is_string($value))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$value)||(int)$value>$max)throw new DomainException('Vorlagenseite und Fassung prüfen.');return(int)$value;
    }
    public static function restore(array $post): array
    {
        $focus=$post['template_focus']??'';if(!is_string($focus)||($focus!==''&&!preg_match('/\A(?:[a-f0-9]{32}|newsletter-standard|press-release)\z/D',$focus)))throw new DomainException('Vorlagenauswahl prüfen.');
        $s=['query'=>Content::text($post['template_query']??'',200),'page'=>self::number($post['template_page']??1,1000000),'focus'=>$focus,'new'=>($post['template_new']??'')==='1','current_version'=>self::number($post['template_current_version']??0,4294967295),'history_page'=>self::number($post['template_history_page']??1,1000000),'revision'=>self::number($post['template_revision']??0,4294967295),'block_index'=>self::number($post['template_block_index']??0,100)];
        if(array_key_exists('template_form_complete',$post)){
            if($post['template_form_complete']!=='1')throw new DomainException('Vorlagenformular nicht vollständig übertragen.');
            $profile=[];foreach(['id'=>32,'version'=>10,'name'=>200,'title'=>300,'body_html'=>1000000,'blocks_json'=>150000]as$key=>$max){if(!is_string($post[$key]??null))throw new DomainException('Vorlagenformular nicht vollständig übertragen.');$profile[$key]=Content::originalText($post[$key],$max);}
            if($profile['id']!==''&&$profile['id']!==$focus)throw new DomainException('Ursprüngliche Vorlage erneut öffnen.');
            if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$profile['version']))throw new DomainException('Vorlagenfassung prüfen.');
            $profile['body_html']=RichText::sanitize($profile['body_html']);
            if(array_key_exists('body',$post)){
                $body=Content::originalText($post['body'],100000);
                if($body!==RichText::plain($profile['body_html']))$profile['body_html']=self::plainHtml($body);
            }
            try{$blocks=json_decode($profile['blocks_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Bausteine nicht vollständig übertragen.');}
            if(!is_array($blocks)||!array_is_list($blocks)||count($blocks)>100)throw new DomainException('Bausteine prüfen.');
            foreach($blocks as$b){if(!is_array($b)||!isset(self::TYPES[$b['type']??'']))throw new DomainException('Bausteine prüfen.');foreach($b as$value)if(!is_string($value))throw new DomainException('Bausteinfeld prüfen.');}
            $index=$s['block_index'];if(($blocks&&$index>=count($blocks))||(!$blocks&&$index!==0))throw new DomainException('Ursprünglichen Baustein erneut öffnen.');if(isset($blocks[$index])){
                $fields=match($blocks[$index]['type']){'richtext'=>['html'=>10000],'text','heading'=>['text'=>10000],'image'=>['media_ref'=>32,'alt'=>500],'button'=>['text'=>100,'url'=>2000],'divider'=>[],'event'=>['title'=>300,'date'=>100,'venue'=>300,'text'=>5000,'media_ref'=>32,'url'=>2000]};
                foreach($fields as$key=>$max){if($blocks[$index]['type']==='event'&&in_array($key,['media_ref','url'],true)&&!array_key_exists('block_'.$key,$post))continue;if(!is_string($post['block_'.$key]??null))throw new DomainException('Bausteinformular nicht vollständig übertragen.');$blocks[$index][$key]=Content::originalText($post['block_'.$key],$max);}
            }
            if(isset($blocks[$index])&&$blocks[$index]['type']==='richtext'&&array_key_exists('block_plain',$post)){$plain=Content::originalText($post['block_plain'],10000);if($plain!==RichText::plain($blocks[$index]['html']))$blocks[$index]['html']=self::plainHtml($plain);}
            $profile['blocks_json']=json_encode($blocks,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$s['profile']=$profile;
        }
        return$s;
    }
    public static function browse(array $s,string $action): array
    {
        if($action==='search'){$s['page']=1;return$s;}
        if($action==='next'||$action==='previous'){$s['page']=max(1,$s['page']+($action==='next'?1:-1));return$s;}
        if($action==='history-next'||$action==='history-previous'){$s['history_page']=max(1,$s['history_page']+($action==='history-next'?1:-1));return$s;}
        if($action==='close'||$action==='new'){$s['focus']='';$s['new']=$action==='new';$s['current_version']=0;$s['revision']=0;$s['block_index']=0;unset($s['profile']);return$s;}
        if($action==='current'){$s['revision']=0;$s['current_version']=0;unset($s['profile']);return$s;}
        if(str_starts_with($action,'revision:')){$s['revision']=self::number(substr($action,9),4294967295);return$s;}
        if(isset($s['profile'])&&(str_starts_with($action,'block:')||str_starts_with($action,'add:'))){
            $blocks=json_decode($s['profile']['blocks_json'],true,64,JSON_THROW_ON_ERROR);$i=$s['block_index'];
            if(str_starts_with($action,'add:')){$type=substr($action,4);if(!isset(self::TYPES[$type])||count($blocks)>=100)throw new DomainException('Bausteintyp oder Anzahl prüfen.');$blocks[]=['type'=>$type];$i=count($blocks)-1;}
            else{switch(substr($action,6)){
                case 'next':$i++;break;case 'previous':$i--;break;
                case 'remove':if(isset($blocks[$i]))array_splice($blocks,$i,1);break;
                case 'up':if($i>0){[$blocks[$i-1],$blocks[$i]]=[$blocks[$i],$blocks[$i-1]];$i--;}break;
                case 'down':if(isset($blocks[$i+1])){[$blocks[$i+1],$blocks[$i]]=[$blocks[$i],$blocks[$i+1]];$i++;}break;
                default:throw new DomainException('Bausteinaktion prüfen.');
            }}
            $s['block_index']=max(0,min(max(0,count($blocks)-1),$i));$s['profile']['blocks_json']=json_encode($blocks,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return$s;
        }
        if(!preg_match('/\A(?:[a-f0-9]{32}|newsletter-standard|press-release)\z/D',$action))throw new DomainException('Vorlagenaktion prüfen.');
        $s['focus']=$action;$s['new']=false;$s['revision']=0;$s['current_version']=0;$s['history_page']=1;$s['block_index']=0;unset($s['profile']);return$s;
    }
}
