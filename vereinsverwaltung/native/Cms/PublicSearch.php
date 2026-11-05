<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;
use RuntimeException;

/** Scan only current public originals, retaining metadata and one payload at a time. */
final class PublicSearch
{
    public const PAGE_SIZE=20;
    public function __construct(private PDO $db,private Access $access){}

    public function page(string $query,mixed $requested=1,int $limit=self::PAGE_SIZE,?string $locale=null):array
    {
        if(preg_match('//u',$query)!==1||preg_match('/[\x00-\x1f\x7f]/',$query))throw new InvalidArgumentException('Suchtext prüfen.');
        $query=trim($query);
        if(strlen($query)>480||preg_match_all('/./us',$query)>120)throw new InvalidArgumentException('Suchtext auf höchstens 120 Zeichen kürzen.');
        if((!is_int($requested)&&!is_string($requested))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$requested)||(int)$requested>1000000||$limit<1||$limit>50)throw new InvalidArgumentException('Gültige Suchseite angeben.');
        $requested=(int)$requested;$club=$this->access->require('website','read')['club_id'];
        $settings=$locale===null?null:new \Kulturhus\Native\Language\Settings($this->db,$this->access);
        if($settings!==null)$settings->language($locale);
        $resolver=$locale===null?null:new \Kulturhus\Native\Language\PublicResolver($this->db,$this->access);
        if($query==='')return ['query'=>'','rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'limit'=>$limit,'locale'=>$locale];
        $pattern='/'.preg_quote($query,'/').'/iu';
        for($attempt=0;$attempt<4;++$attempt){
            $generation=$this->generation($club);$total=0;$selected=[];$tail=[];$cursor=null;
            do{
                $this->allowed($club);
                if($settings!==null)$settings->language($locale);
                [$policy,$policyParameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
                $after=$cursor?' AND (p.published_at<? OR (p.published_at=? AND p.id COLLATE utf8mb4_bin>?))':'';
                $args=$policyParameters;if($cursor)array_push($args,$cursor['published_at'],$cursor['published_at'],$cursor['id']);
                $batch=Db::run($this->db,'SELECT p.id,p.published_revision,p.published_at FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE ('.$policy.') AND '.NewsChannels::where().$after.' ORDER BY p.published_at DESC,p.id COLLATE utf8mb4_bin LIMIT 100',$args)->fetchAll(PDO::FETCH_ASSOC);
                foreach($batch as $meta){
                    if($resolver!==null){
                        $page=$resolver->project(['id'=>$meta['id'],'published_revision'=>(int)$meta['published_revision']],$locale);
                        if($page===null)continue;
                    }else{
                        $json=Db::run($this->db,'SELECT r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE ('.$policy.') AND p.id=? AND p.published_revision=? AND '.NewsChannels::where(),[...$policyParameters,$meta['id'],$meta['published_revision']])->fetchColumn();
                        if($json===false)continue;
                        $page=json_decode($json,true,512,JSON_THROW_ON_ERROR);unset($json);
                    }
                    $page=FieldPolicy::publicSource($this->db,$club,$page);$texts=self::texts($page);$match=false;foreach($texts as$text)if(preg_match($pattern,$text)){$match=true;break;}
                    if(!$match){unset($page,$texts,$text);continue;}
                    $text=($page['excerpt']??'')!==''?$page['excerpt']:($page['body']??'');
                    if($text==='')foreach(array_slice($texts,3)as$part)if($part!==''){$text=$part;break;}
                    preg_match('/\A.{0,600}/us',$text,$snippet);unset($texts,$part);
                    $row=['id'=>$meta['id'],'slug'=>$page['language_path']??$page['slug'],'title'=>$page['title'],'excerpt'=>($snippet[0]??'').(strlen($text)>strlen($snippet[0]??'')?' …':''),'published_at'=>$meta['published_at']];
                    unset($page,$text,$snippet);++$total;
                    if(intdiv($total-1,$limit)+1===$requested)$selected[]=$row;
                    $tail[]=$row;if(count($tail)>$limit)array_shift($tail);
                }
                if($batch)$cursor=$batch[array_key_last($batch)];
            }while(count($batch)===100);
            $this->allowed($club);
            if($settings!==null)$settings->language($locale);
            if($generation!==$this->generation($club))continue;
            $pages=max(1,(int)ceil($total/$limit));$actual=min($requested,$pages);
            if($actual!==$requested)$selected=$total?array_slice($tail,-($total%$limit?:$limit)):[];
            return ['query'=>$query,'rows'=>$selected,'total'=>$total,'page'=>$actual,'pages'=>$pages,'limit'=>$limit,'locale'=>$locale];
        }
        throw new RuntimeException('Die Website wird gerade veröffentlicht. Bitte erneut laden.');
    }

    private function generation(string $club):int
    {
        return (int)(Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=?',[$club])->fetchColumn()?:0);
    }

    /** Text from the projected presentation, never raw metadata, passwords or private repeater items. */
    private static function texts(array $page):array
    {
        $texts=[$page['title']??'',$page['excerpt']??'',$page['body']??'',ContentSections::plain($page['content_sections']??[]),FieldValues::publicText($page)];
        if(EventDetails::isEvent($page))array_push($texts,$page['event_location']??'',$page['event_address']??'');
        if(($page['slug']??null)==='start'&&isset($page['home_layout'])){
            $layout=$page['home_layout'];foreach(['hero_title','poster_top','poster_main','poster_sub']as$key)$texts[]=$layout[$key]??'';
            $texts[]=\Kulturhus\Native\Shared\RichText::plain($layout['hero_html']??'');
            if(in_array('highlights',$layout['sections'],true))array_push($texts,...$layout['highlights']);
            foreach(['events'=>['events_heading'],'news'=>['news_heading'],'newsletter'=>['newsletter_heading','newsletter_text']]as$section=>$keys)if(in_array($section,$layout['sections'],true))foreach($keys as$key)$texts[]=$layout[$key];
        }
        foreach($page['document_links']??[]as$link)$texts[]=$link['label'];
        return $texts;
    }
    private function allowed(string $club):void
    {
        if($this->access->require('website','read')['club_id']!==$club)throw new DomainException('Öffentlicher Vereinszugang nicht verfügbar.');
    }
}
