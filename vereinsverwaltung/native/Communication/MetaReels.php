<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use Kulturhus\Native\Delivery\{DeferredDelivery,UncertainDelivery};

/** Separate provider phases; a durable reference always reuses the same object. */
final class MetaReels
{
    private \Closure $graph;
    private \Closure $upload;
    public function __construct(callable $graph,callable $upload)
    {$this->graph=\Closure::fromCallable($graph);$this->upload=\Closure::fromCallable($upload);}

    public function send(array $account,array $frame,array $job): string
    {
        if(($frame['kind']??null)!=='reel'||!in_array($frame['channel']??null,['facebook','instagram'],true))throw new DomainException('Eigenes Reel erneut öffnen.');
        if($frame['channel']==='instagram')return $this->instagram($account,$frame,$job);
        return $this->facebook($account,$frame,$job);
    }

    private function instagram(array $account,array $frame,array $job): string
    {
        $reference=$job['external_id']??'';
        if($reference!==''&&!preg_match('/^instagram:container:([0-9]{1,100})$/D',$reference,$known))throw new DomainException('Gespeicherten Reel-Auftrag prüfen.');
        if($reference===''){
            $response=($this->graph)($account,'POST',$account['instagram_id'].'/media',['media_type'=>'REELS','video_url'=>$frame['video_url'],'caption'=>$frame['caption']]);
            $id=self::id($response['id']??null);
            throw new DeferredDelivery('instagram:container:'.$id,'reel_container_waiting');
        }
        $id=$known[1];
        try{
            $state=($this->graph)($account,'GET',$id.'?fields=status_code',null);
            if(($state['status_code']??null)==='PUBLISHED')return 'instagram:container:'.$id;
            if(($state['status_code']??null)==='IN_PROGRESS'&&(int)($job['attempts']??0)<6)throw new DeferredDelivery('instagram:container:'.$id,'reel_container_waiting');
            if(($state['status_code']??null)!=='FINISHED')throw new UncertainDelivery('instagram:container:'.$id,'reel_container_not_ready');
            $response=($this->graph)($account,'POST',$account['instagram_id'].'/media_publish',['creation_id'=>$id]);
            return 'instagram:media:'.self::id($response['id']??null);
        }catch(DeferredDelivery|UncertainDelivery $error){throw $error;}
        catch(\Throwable){throw new UncertainDelivery('instagram:container:'.$id,'reel_publish_unknown');}
    }

    private function facebook(array $account,array $frame,array $job): string
    {
        $reference=$job['external_id']??'';
        if($reference===''){
            $response=($this->graph)($account,'POST',$account['page_id'].'/video_reels',['upload_phase'=>'start']);
            $id=self::id($response['video_id']??null);
            if(($response['upload_url']??null)!=='https://rupload.facebook.com/video-upload/'.$id)throw new UncertainDelivery('facebook:reel-start:'.$id,'reel_upload_destination_unknown');
            throw new DeferredDelivery('facebook:reel-start:'.$id,'reel_upload_started');
        }
        if(!preg_match('/^facebook:(reel-start|reel-upload|reel-publishing):([0-9]{1,100})$/D',$reference,$known))throw new DomainException('Gespeicherten Reel-Auftrag prüfen.');
        [$all,$phase,$id]=$known;
        if($phase==='reel-start'){
            try{
                $response=($this->upload)('POST','/video-upload/'.$id,['Authorization: OAuth '.$account['token'],'file_url: '.$frame['video_url']],null);
                if(($response['status']??0)<200||($response['status']??0)>=300||($response['body']['success']??null)!==true)throw new \RuntimeException('Upload nicht bestätigt.');
                throw new DeferredDelivery('facebook:reel-upload:'.$id,'reel_upload_complete');
            }catch(DeferredDelivery $error){throw $error;}
            catch(\Throwable){throw new UncertainDelivery('facebook:reel-start:'.$id,'reel_upload_unknown');}
        }
        try{
            $response=($this->graph)($account,'GET',$id.'?fields=id,status',null);if(($response['id']??null)!==$id)throw new \RuntimeException('Anbieterkennung passt nicht zum originalen Reel.');$status=$response['status']??[];
            if(self::published($status))return 'facebook:reel:'.$id;
            if($phase==='reel-publishing'){
                if((int)($job['attempts']??0)<8&&!self::error($status))throw new DeferredDelivery('facebook:reel-publishing:'.$id,'reel_publication_waiting');
                throw new UncertainDelivery('facebook:reel-publishing:'.$id,'reel_publication_unconfirmed');
            }
            if(self::error($status))throw new UncertainDelivery('facebook:reel-upload:'.$id,'reel_processing_failed');
            if(!self::complete($status['uploading_phase']['status']??null)||!self::complete($status['processing_phase']['status']??null)){
                if((int)($job['attempts']??0)<6)throw new DeferredDelivery('facebook:reel-upload:'.$id,'reel_processing_waiting');
                throw new UncertainDelivery('facebook:reel-upload:'.$id,'reel_processing_unconfirmed');
            }
            // A provider that is already publishing only needs confirmation reads.
            if(($status['publishing_phase']['status']??null)==='in_progress')throw new DeferredDelivery('facebook:reel-publishing:'.$id,'reel_publication_waiting');
            if(($status['publishing_phase']['status']??null)!=='not_started')throw new UncertainDelivery('facebook:reel-upload:'.$id,'reel_publication_state_unknown');
            $result=($this->graph)($account,'POST',$account['page_id'].'/video_reels',['upload_phase'=>'finish','video_id'=>$id,'video_state'=>'PUBLISHED','description'=>$frame['caption'],'title'=>$frame['title']]);
            if(($result['success']??null)!==true)throw new \RuntimeException('Veröffentlichung nicht bestätigt.');
            throw new DeferredDelivery('facebook:reel-publishing:'.$id,'reel_publication_waiting');
        }catch(DeferredDelivery|UncertainDelivery $error){throw $error;}
        catch(\Throwable){throw new UncertainDelivery('facebook:'.$phase.':'.$id,$phase==='reel-publishing'?'reel_publication_unconfirmed':'reel_publish_unknown');}
    }

    public static function published(array $status): bool
    {return self::complete($status['publishing_phase']['status']??null)&&($status['publishing_phase']['publish_status']??null)==='published';}
    private static function complete(mixed $status): bool
    {return in_array($status,['complete','completed'],true);}
    private static function error(array $status): bool
    {foreach(['uploading_phase','processing_phase','publishing_phase']as$phase)if(($status[$phase]['status']??null)==='error'||!empty($status[$phase]['errors'])||!empty($status[$phase]['error']))return true;return false;}
    private static function id(mixed $id): string
    {if(!is_string($id)||!preg_match('/^[0-9]{1,100}$/D',$id))throw new \RuntimeException('Anbieterkennung fehlt.');return $id;}
}
