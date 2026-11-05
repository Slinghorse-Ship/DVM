<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Delivery\{DeferredDelivery,UncertainDelivery};

/** Prepared phases never repeat an unknown provider write; one public post contains the whole collection. */
final class MetaCarousels
{
    private \Closure $graph;
    public function __construct(private PDO $db,callable $graph){$this->graph=\Closure::fromCallable($graph);}
    private static function id(mixed $value,bool $instagram):string
    {
        if(!is_string($value)||!preg_match($instagram?'/\A[0-9]{1,100}\z/D':'/\A[0-9_]{1,100}\z/D',$value))throw new DomainException('Originale Anbieterkennung fehlt.');return $value;
    }
    private static function reference(array $frame,string $job,array $rows):string
    {
        if(($rows[10]['state']??null)==='known')return $frame['channel'].($frame['channel']==='instagram'?':container:':':post:').$rows[10]['remote_id'];
        return $frame['channel'].':carousel:'.$job;
    }
    private function read(array $account,string $id,string $fields):array
    {
        $result=($this->graph)($account,'GET',$id.'?'.http_build_query(['fields'=>$fields]),null);
        if(($result['id']??null)!==$id)throw new DomainException('Anbieterkennung passt nicht zum ursprünglichen Karussell.');return $result;
    }
    private function ready(array $account,array $frame,array $row,string $reference):void
    {
        $ig=$frame['channel']==='instagram';$id=self::id($row['remote_id'],$ig);
        $result=$this->read($account,$id,$ig?'id,status_code':'id,from');
        if(!$ig){if(($result['from']['id']??null)!==$account['page_id'])throw new DomainException('Vorbereitetes Foto gehört nicht zur eigenen Seite.');return;}
        $state=$result['status_code']??null;
        if($state==='FINISHED')return;
        if($state==='IN_PROGRESS'&&(new \DateTimeImmutable($row['created_at'],new \DateTimeZone('UTC')))->modify('+1 hour')>new \DateTimeImmutable('now',new \DateTimeZone('UTC')))throw new DeferredDelivery($reference,'carousel_processing_waiting');
        throw new UncertainDelivery($reference,'carousel_processing_unconfirmed');
    }
    public function send(string $club,array $account,array $frame,array $job):string
    {
        $id=Content::id($job['id']??'');$current=Db::run($this->db,'SELECT club_id,status,attempts FROM native_delivery_jobs WHERE id=?',[$id])->fetch();
        if(!$current||$current['club_id']!==$club||$current['status']!=='processing'||(int)$current['attempts']!==(int)($job['attempts']??-1)+1)throw new DomainException('Karussell über seinen ursprünglichen Worker veröffentlichen.');
        $rows=CarouselProgress::rows($this->db,$club,$id,$frame);$reference=self::reference($frame,$id,$rows);$ig=$frame['channel']==='instagram';$count=count($frame['images']);
        foreach($rows as$slot=>$row)if(($slot<10&&$slot>=$count)||(!$ig&&$slot===11))throw new DomainException('Ursprüngliche Anzahl der Karussell-Bilder prüfen.');
        $known=0;while(($rows[$known]['state']??null)==='known'&&$known<$count)++$known;
        foreach($rows as$slot=>$row)if(($slot<10&&($slot>$known||($slot===$known&&$row['state']!=='prepared')))||($slot>=10&&$known!==$count)||($slot===11&&($rows[10]['state']??null)!=='known'))throw new DomainException('Ursprüngliche Karussell-Schrittfolge prüfen.');
        for($slot=0;$slot<$count;++$slot){
            if(!isset($rows[$slot]))throw new CarouselProgress($reference,$slot,null);
            if($rows[$slot]['state']==='known')continue;
            try{$body=$ig?['image_url'=>$frame['images'][$slot]['url'],'is_carousel_item'=>true]:['url'=>$frame['images'][$slot]['url'],'published'=>false];$result=($this->graph)($account,'POST',($ig?$account['instagram_id'].'/media':$account['page_id'].'/photos'),$body);$remote=self::id($result['id']??null,$ig);}
            catch(\Throwable){throw new UncertainDelivery($reference,'carousel_child_unknown');}
            throw new CarouselProgress($reference,$slot,$remote);
        }
        if(!$ig&&($rows[10]['state']??null)==='known'){
            try{if(!$this->facebookMatches($account,$frame,$rows[10]['remote_id'],$rows))throw new DomainException('Ganzes Karussell nicht bestätigt.');return 'facebook:post:'.$rows[10]['remote_id'];}catch(\Throwable){throw new UncertainDelivery($reference,'carousel_publication_unconfirmed');}
        }
        if($ig&&($rows[10]['state']??null)==='known'){
            try{$parent=$this->read($account,$rows[10]['remote_id'],'id,status_code');}catch(\Throwable){throw new UncertainDelivery($reference,'carousel_parent_unconfirmed');}
            if(($parent['status_code']??null)==='PUBLISHED')return 'instagram:container:'.$rows[10]['remote_id'];
            $this->ready($account,$frame,$rows[10],$reference);
            if(!isset($rows[11]))throw new CarouselProgress($reference,11,null);
            if($rows[11]['state']==='known'){
                if((new \DateTimeImmutable($rows[11]['created_at'],new \DateTimeZone('UTC')))->modify('+1 hour')>new \DateTimeImmutable('now',new \DateTimeZone('UTC')))throw new DeferredDelivery($reference,'carousel_publication_waiting');
                throw new UncertainDelivery($reference,'carousel_publication_unconfirmed');
            }
            try{$result=($this->graph)($account,'POST',$account['instagram_id'].'/media_publish',['creation_id'=>$rows[10]['remote_id']]);$remote=self::id($result['id']??null,true);}catch(\Throwable){throw new UncertainDelivery($reference,'carousel_publish_unknown');}
            throw new CarouselProgress($reference,11,$remote);
        }
        foreach(range(0,$count-1)as$slot)$this->ready($account,$frame,$rows[$slot],$reference);
        if(!isset($rows[10]))throw new CarouselProgress($reference,10,null);
        try{
            $children=array_map(static fn($slot)=>$rows[$slot]['remote_id'],range(0,$count-1));
            $body=$ig?['media_type'=>'CAROUSEL','children'=>implode(',',$children),'caption'=>$frame['caption']]:['message'=>$frame['caption'],'attached_media'=>array_map(static fn($child)=>['media_fbid'=>$child],$children)];
            $result=($this->graph)($account,'POST',($ig?$account['instagram_id'].'/media':$account['page_id'].'/feed'),$body);$remote=self::id($result['id']??null,$ig);
        }catch(\Throwable){throw new UncertainDelivery($reference,$ig?'carousel_parent_unknown':'carousel_publish_unknown');}
        throw new CarouselProgress($ig?'instagram:container:'.$remote:'facebook:post:'.$remote,10,$remote);
    }

    private static function photoIds(array $post):?array
    {
        $attachments=$post['attachments']['data']??null;if(!is_array($attachments)||!array_is_list($attachments)||!$attachments)return null;$out=[];
        foreach($attachments as$attachment){$children=$attachment['subattachments']['data']??[$attachment];if(!is_array($children)||!array_is_list($children))return null;foreach($children as$child){$id=$child['target']['id']??null;if(!is_string($id)||!preg_match('/\A[0-9_]{1,100}\z/D',$id))return null;$out[]=$id;}}
        return $out;
    }
    private static function matches(array $account,array $frame,array $post,array $rows):bool
    {
        $expected=[];for($i=0;$i<count($frame['images']);++$i){if(($rows[$i]['state']??null)!=='known')return false;$expected[]=$rows[$i]['remote_id'];}
        return ($post['from']['id']??null)===$account['page_id']&&($post['message']??null)===$frame['caption']&&self::photoIds($post)===$expected;
    }
    private const POST_FIELDS='id,from,message,attachments{target,subattachments{target}}';
    private function facebookMatches(array $account,array $frame,string $id,array $rows):bool
    {
        return self::matches($account,$frame,$this->read($account,$id,self::POST_FIELDS),$rows);
    }

    /** Original outcome reads only. A pending publish intention is never permission to repeat a write. */
    public function check(string $club,array $account,array $frame,array $job):array
    {
        $rows=CarouselProgress::rows($this->db,$club,$job['id'],$frame);$reference=self::reference($frame,$job['id'],$rows);$confirmed=false;$status='unconfirmed';$remote=null;
        if(($rows[10]['state']??null)==='known'){
            $remote=$rows[10]['remote_id'];
            if($frame['channel']==='instagram'){$result=$this->read($account,$remote,'id,status_code');$status=$result['status_code']??'unconfirmed';$confirmed=$status==='PUBLISHED';}
            else{$confirmed=$this->facebookMatches($account,$frame,$remote,$rows);$status=$confirmed?'PUBLISHED':'unconfirmed';}
        }elseif($frame['channel']==='facebook'&&($rows[10]['state']??null)==='prepared'){
            // The actual acknowledged child photo IDs make this a precise whole-post lookup.
            $matches=[];$seen=[];$cursors=[];$after='';$complete=false;
            for($page=0;$page<4;++$page){
                $query=['fields'=>self::POST_FIELDS,'limit'=>50];if($after!=='')$query['after']=$after;
                $response=($this->graph)($account,'GET',$account['page_id'].'/feed?'.http_build_query($query),null);$posts=$response['data']??null;if(!is_array($posts)||!array_is_list($posts)||count($posts)>50)throw new DomainException('Vollständigen ursprünglichen Seitenabgleich prüfen.');
                foreach($posts as$post){$postId=self::id($post['id']??null,false);if(isset($seen[$postId]))throw new DomainException('Überlappenden ursprünglichen Seitenabgleich prüfen.');$seen[$postId]=true;if(self::matches($account,$frame,$post,$rows))$matches[]=$postId;}
                if(empty($response['paging']['next'])){$complete=true;break;}
                $next=$response['paging']['cursors']['after']??null;if(!is_string($next)||$next===''||strlen($next)>1000||isset($cursors[$next]))throw new DomainException('Ursprünglichen Seitenabgleich fortsetzen.');$cursors[$next]=true;$after=$next;
            }
            if(count($matches)===1&&$complete){$remote=$matches[0];$reference='facebook:post:'.$remote;$confirmed=true;$status='PUBLISHED';}
            elseif(count($matches)>1)$status='multiple_matches';
            elseif(!$complete)$status='lookup_incomplete';
        }
        $prepared=array_filter($rows,static fn($row)=>$row['state']==='prepared');
        return ['reference'=>$reference,'provider_status'=>$status,'publication_confirmed'=>$confirmed,'resume_safe'=>!$confirmed&&!$prepared,'remote'=>$remote,'rows'=>$rows];
    }
}
