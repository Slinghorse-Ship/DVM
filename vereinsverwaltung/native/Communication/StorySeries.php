<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Event-local artwork references. Pixels remain private files; SQL holds their hashes. */
final class StorySeries
{
    public const SLOTS=['base','initial','d14','d7','d3','d1','d0'];
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}

    /** MySQL JSON objects reorder keys; source values and list order must still agree. */
    public static function same(array $left,array $right):bool
    {
        $ordered=static function(mixed $value)use(&$ordered):mixed{
            if(!is_array($value))return $value;
            if(!array_is_list($value))ksort($value,SORT_STRING);
            return array_map($ordered,$value);
        };
        return json_encode($ordered($left),JSON_THROW_ON_ERROR)===json_encode($ordered($right),JSON_THROW_ON_ERROR);
    }

    public static function labels(string $locale):array
    {
        $en=$locale==='en';$labels=['initial'=>$en?'SAVE THE DATE':'TERMIN MERKEN'];
        foreach([14,7,3,1,0]as$days)$labels['d'.$days]=match($days){0=>$en?'TODAY':'HEUTE',1=>$en?'TOMORROW':'MORGEN',default=>$en?$days.' DAYS TO GO':'NOCH '.$days.' TAGE'};
        return $labels;
    }

    public function context(string $item):?array
    {
        $club=$this->access->require('social','read')['club_id'];
        $lock=$this->db->inTransaction()?' FOR SHARE':'';
        $p=Db::run($this->db,"SELECT p.*,s.config_json,s.version story_config_version FROM native_social_event_preparations p JOIN native_social_event_preparation_settings s ON s.club_id=p.club_id WHERE p.club_id=? AND p.item_id=? AND p.kind='story'".$lock,[$club,$item])->fetch();
        if(!$p)return null;$config=EventPreparations::config(json_decode($p['config_json'],true,32,JSON_THROW_ON_ERROR));
        $first=Db::run($this->db,"SELECT item_id FROM native_social_event_preparations WHERE club_id=? AND page_id=? AND page_revision=? AND kind='story' ORDER BY created_at,item_id LIMIT 1".$lock,[$club,$p['page_id'],(int)$p['page_revision']])->fetchColumn();
        if(!is_string($first))return null;
        if(!$config['story_master'])$first=$item;
        return ['preparation'=>$p,'config'=>$config,'first'=>$first,'master'=>$first===$item,'labels'=>self::labels($config['locale'])];
    }

    /** Every edited frame retains its pixel variants, including custom fonts and strokes. */
    public function editor(string $item):?array
    {
        $context=$this->context($item);if(!$context)return null;
        $seed=['master'=>$context['master'],'first'=>$context['first'],'slot'=>$context['preparation']['slot'],'show_hint'=>$context['config']['story_day_hint'],'labels'=>$context['labels'],'reminder_layers'=>self::reminderLayers()];
        if(!$context['master']){
            $first=(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($context['first']);$image=$this->masterImage($first);
            if($image){$meta=json_decode($image['metadata_json'],true,32,JSON_THROW_ON_ERROR);if(isset($meta['document']))$seed['document']=self::document($meta['document'],$context['preparation']['slot'],$context['config']['locale']);}
        }
        return $seed;
    }

    public static function reminderLayers():array
    {
        return CanvasDocument::validate(['schema'=>1,'width'=>1080,'height'=>1920,'background'=>'#f7f0df','layers'=>[
            ['id'=>'reminder-background','type'=>'rect','x'=>590,'y'=>294,'width'=>380,'height'=>78,'rotation'=>0,'opacity'=>1,'color'=>'#d9583e'],
            ['id'=>'reminder-label','type'=>'text','x'=>610,'y'=>314,'width'=>340,'height'=>55,'rotation'=>0,'opacity'=>1,'text'=>'TERMIN MERKEN','font'=>'Arial','font_size'=>32,'color'=>'#ffffff'],
        ]],'story')['layers'];
    }

    public static function document(array $input,string $slot,string $locale):array
    {
        if(!in_array($slot,self::SLOTS,true))throw new DomainException('Story-Tageshinweis prüfen.');
        $doc=CanvasDocument::validate($input,'story');$labels=self::labels($locale);
        if($slot==='base')$doc['layers']=array_values(array_filter($doc['layers'],static fn(array $l):bool=>!in_array($l['id'],['reminder-background','reminder-label'],true)));
        else{
            if(!array_filter($doc['layers'],static fn(array$l):bool=>$l['id']==='reminder-label')){
                $doc['layers']=array_values(array_filter($doc['layers'],static fn(array$l):bool=>!in_array($l['id'],['reminder-background','reminder-label'],true)));
                $doc['layers']=[...$doc['layers'],...self::reminderLayers()];
            }
            foreach($doc['layers']as&$layer)if($layer['id']==='reminder-label'&&$layer['type']==='text')$layer['text']=$labels[$slot];
        }
        unset($layer);return CanvasDocument::validate($doc,'story');
    }

    /** Exact bounded uploads, before any accepted image or file is written. */
    public static function sources(array $sources):array
    {
        if($sources===[])return [];
        $keys=array_keys($sources);sort($keys);$expected=self::SLOTS;sort($expected);
        if($keys!==$expected)throw new DomainException('Die vollständige Story-Vorlage speichern.');
        $hashes=[];$total=0;
        foreach(self::SLOTS as$slot){
            $path=$sources[$slot];
            if(!is_string($path)||preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||is_link($path)||!is_file($path))throw new DomainException('Story-Bilddateien prüfen.');
            $bytes=filesize($path);$total+=$bytes;
            $info=$bytes>0&&$bytes<=5*1024*1024?@getimagesize($path):false;
            if(!$info||$info[0]!==1080||$info[1]!==1920||$info['mime']!=='image/png'||$total>35*1024*1024)throw new DomainException('Story-Vorlage benötigt vollständige PNG-Bilder im Storyformat.');
            $hashes[$slot]=hash_file('sha256',$path);
        }
        return $hashes;
    }

    public static function uploads(array $files,mixed $complete):array
    {
        $present=array_filter(array_keys($files),static fn($key):bool=>is_string($key)&&str_starts_with($key,'story_template_'));
        if($complete===null&&$present===[])return [];
        if($complete!=='1')throw new DomainException('Die vollständige Story-Vorlage speichern.');
        $sources=[];
        foreach(self::SLOTS as$slot){
            $file=$files['story_template_'.$slot]??[];
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new DomainException('Story-Vorlage fehlt oder Upload fehlgeschlagen.');
            $sources[$slot]=$file['tmp_name'];
        }
        if(count($present)!==count(self::SLOTS))throw new DomainException('Die vollständige Story-Vorlage speichern.');
        self::sources($sources);return $sources;
    }

    public static function paths(string $root,string $id):array
    {
        Content::id($id);$out=[];foreach(self::SLOTS as$slot)$out[$slot]=$root.'/'.$id.'-'.$slot.'.png';return $out;
    }

    public static function retain(array $sources,array $hashes,array $paths):array
    {
        foreach(self::SLOTS as$slot){
            if(file_exists($paths[$slot])||is_link($paths[$slot])||!copy($sources[$slot],$paths[$slot])||!chmod($paths[$slot],0600)||!hash_equals($hashes[$slot],hash_file('sha256',$paths[$slot])))throw new \RuntimeException('Story-Vorlage konnte nicht gespeichert werden.');
        }
        return ['format'=>1,'sha256'=>$hashes];
    }

    public function render(array $item):?array
    {
        $context=$this->context($item['id']);if(!$context||($context['master']&&$this->latest($item)===null))return null;
        return [$this->ensure($item,true)];
    }

    private function latest(array $first):?array
    {
        $history=new ImageHistory($this->db,$this->access);$images=$history->newest($first['id'],$first['version'],Images::fingerprint($first),'story',1);
        if(!$images&&($first['approved_image_ids']??[]))$images=$history->originals($first['id'],[$first['approved_image_ids'][0]]);
        return $images[0]??null;
    }

    public function masterImage(array $first):?array
    {
        $row=$this->latest($first);if(!$row)return null;$meta=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
        if(($meta['story_reference']['override']??false)===true){
            $source=$meta['story_reference']['image']??'';
            if($source===$row['id'])throw new DomainException('Story-Vorlage prüfen.');
            $originals=(new ImageHistory($this->db,$this->access))->originals($first['id'],[$source]);$row=$originals[0]??null;
        }
        return $row;
    }

    /** Refresh machine-made followers while keeping individually edited artwork. */
    public function ensure(array $item,bool $force=false):string
    {
        $store=new Images($this->db,$this->access,$this->mediaRoot);$context=$this->context($item['id']);$current=$this->latest($item);
        if(!$context){if($current){$store->file($current['id']);return $current['id'];}return $store->render($item['id'])[0];}
        $meta=$current?json_decode($current['metadata_json'],true,32,JSON_THROW_ON_ERROR):[];
        if(!$current){
            foreach((new ImageHistory($this->db,$this->access))->documents($item['id'],'story')as$older){
                $old=json_decode($older['metadata_json'],true,32,JSON_THROW_ON_ERROR);
                if(($old['renderer']??'')==='canvas2d-local'||($old['story_reference']['override']??false)===true)throw new DomainException('Das bearbeitete Story-Bild für die aktuelle Fassung speichern.');
            }
        }
        $own=($meta['renderer']??'')==='canvas2d-local'||($meta['story_reference']['override']??false)===true;
        $source=($own||$context['master'])?$item:(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($context['first']);
        $master=$this->masterImage($source);
        if(!$master&&$context['master']){$store->render($item['id']);$master=$this->masterImage($source);$current=$this->latest($item);$meta=$current?json_decode($current['metadata_json'],true,32,JSON_THROW_ON_ERROR):[];}
        if(!$master)throw new DomainException('Das erste Story-Bild als Vorlage speichern.');
        $slot=$context['config']['story_day_hint']?$context['preparation']['slot']:'base';
        if(!in_array($slot,self::SLOTS,true))throw new DomainException('Story-Tageshinweis prüfen.');
        if(!$force&&$current){
            $sameHint=($meta['story_hint']??ClassicEventImage::details($item['story'][0]['text']??'')['label']!=='')===$context['config']['story_day_hint'];
            $sameSlot=($meta['story_slot']??$context['preparation']['slot'])===$context['preparation']['slot'];
            $reference=$meta['story_reference']??null;
            if($sameHint&&$sameSlot&&(($own&&$reference===null)||($context['master']&&$reference===null)||($reference!==null&&($reference['image']??null)===$master['id']&&($reference['slot']??null)===$slot))){$store->file($current['id']);return $current['id'];}
        }
        return $store->renderStoryTemplate($item,$source,$master,$slot,$context['config']['locale'],$own||$context['master']);
    }
}
