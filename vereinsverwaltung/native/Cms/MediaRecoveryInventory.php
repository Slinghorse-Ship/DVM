<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Read-only bounded pending metadata; exact source hashes are verified before offering a link. */
final class MediaRecoveryInventory
{
    private const MAX_ENTRIES=50000;
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    public function listing(string $after=''):array
    {
        if($this->db->inTransaction())throw new DomainException('Fortsetzungen benötigen eine aktuelle eigene Prüfung.');
        if($after!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$after))throw new InvalidArgumentException('Fortsetzungen erneut öffnen.');
        $actor=$this->actor();$club=$actor['club_id'];
        $result=['rows'=>[],'after'=>$after,'next_after'=>'','has_more'=>false,'complete'=>true,'scanned_entries'=>0];
        $directory=MediaRecoveryLease::directory($this->mediaRoot,$club);
        if($directory===null)return $this->finish($actor,$result);
        $candidates=[];$batch=[];
        $flush=function()use(&$candidates,&$batch):void{
            if(!$batch)return;
            $known=Db::run($this->db,'SELECT id FROM khcms_media WHERE id IN ('.implode(',',array_fill(0,count($batch),'?')).')',$batch)->fetchAll(PDO::FETCH_COLUMN);
            foreach(array_diff($batch,$known)as$id){$candidates[$id]=true;ksort($candidates,SORT_STRING);if(count($candidates)>13)array_pop($candidates);}
            $batch=[];
        };
        foreach(new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS)as$entry){
            if(++$result['scanned_entries']>self::MAX_ENTRIES){$result['complete']=false;break;}
            $id=$entry->getFilename();
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||$entry->isLink()||!$entry->isDir()){ $result['complete']=false;continue; }
            if($after!==''&&strcmp($id,$after)<=0)continue;
            $batch[]=$id;if(count($batch)===64)$flush();
        }
        $flush();$source=new MediaStorageRecovery($this->db,$this->access,$this->mediaRoot);
        foreach(array_keys($candidates)as$id){
            try{
                $plan=MediaRecoveryLease::intention($this->mediaRoot,$club,$id);
                $prepared=$source->prepare($plan['source_id'],false);
                if($prepared['existing'])continue;
                if($prepared['target_id']!==$id||!hash_equals($plan['fingerprint'],$prepared['fingerprint'])||!in_array($prepared['recovery_state'],['pending','running'],true))throw new DomainException('Ursprünglichen Quellenstand prüfen.');
                $result['rows'][]=$prepared;
            }catch(DomainException|InvalidArgumentException|Conflict){$result['complete']=false;}
            if(count($result['rows'])===7)break;
        }
        $result['has_more']=count($result['rows'])>6;$result['rows']=array_slice($result['rows'],0,6);
        if($result['has_more'])$result['next_after']=$result['rows'][array_key_last($result['rows'])]['target_id'];
        elseif(count($candidates)===13){$result['complete']=false;$result['has_more']=true;$result['next_after']=array_key_last($candidates);}
        return $this->finish($actor,$result);
    }
    private function actor():array
    {
        $actor=$this->access->require('website','manage');
        foreach(['read','edit']as$cap)$this->same($actor,$this->access->require('website',$cap));
        return $actor;
    }
    private function same(array $first,array $current):void
    {
        if($first['id']!==$current['id']||$first['club_id']!==$current['club_id'])throw new DomainException('Person oder Verein inzwischen geändert. Fortsetzungen erneut öffnen.');
    }
    private function finish(array $actor,array $result):array{$this->same($actor,$this->actor());return $result;}
}
