<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** One bounded list entry per original, including retained manual replies. */
final class AttentionCatalogue
{
    public function __construct(private PDO $db,private SecretBox $box){}

    /** A currently retained provider original, never a title guessed from a comment. */
    public function origin(Access $access,array $source):?array
    {
        $access->require('social','manage');
        if(!empty($source['_archived'])||!in_array($source['channel']??null,['facebook','instagram','stadtlandfunk'],true)||!is_string($source['parent_id']??null))return null;
        $row=Db::run($this->db,'SELECT id,kind,caption,payload_json FROM native_social_objects WHERE club_id=? AND channel=? AND remote_id=?',[$access->club(),$source['channel'],$source['parent_id']])->fetch();
        if(!$row)return null;
        $data=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
        $binding=$source['_history_connection']??null;
        if($binding!==null&&($data['connection_id']??null)!==$binding)return null;
        $url=$data['permalink']??'';try{if($url!=='')$url=Content::url($url);}catch(DomainException){$url='';}
        return ['id'=>$row['id'],'kind'=>$row['kind'],'text'=>$row['caption'],'url'=>$url];
    }

    public static function query(array $input):array
    {
        $common=InboxCatalogue::query(['conversation_page'=>$input['review_page']??1,'q'=>$input['q']??'']);
        $channel=$input['review_channel']??'all';$kind=$input['review_kind']??'all';$state=$input['state']??'all';
        if(!is_string($channel)||!in_array($channel,['all','facebook','instagram','stadtlandfunk'],true)
            ||!is_string($kind)||!in_array($kind,['all','comment','reaction'],true)
            ||!is_string($state)||!in_array($state,['all','open','new','reply_needed','done'],true))throw new DomainException('Kommentarfilter prüfen.');
        return ['page'=>$common['page'],'q'=>$common['q'],'channel'=>$channel,'kind'=>$kind,'state'=>$state];
    }

    private function candidates(Access $access,array $q):array
    {
        $where="club_id=? AND kind IN ('comment','reaction')";$args=[$access->club()];
        if($q['channel']!=='all'){$where.=' AND channel=?';$args[]=$q['channel'];}
        if($q['kind']!=='all'){$where.=' AND kind=?';$args[]=$q['kind'];}
        if($q['state']==='open')$where.=" AND state IN ('new','reply_needed')";
        elseif($q['state']!=='all'){$where.=' AND state=?';$args[]=$q['state'];}
        $active="SELECT id,id AS source_id,channel,kind,state,observed_at AS list_at,'' AS reply_id,'attention' AS entry_type FROM native_social_attention WHERE ".$where;
        // A retained reply whose provider original disappeared remains reachable.
        // Automatic mail replies stay inside their original conversation.
        $saved="r.club_id=? AND r.reply_slot='' AND NOT EXISTS(SELECT 1 FROM native_social_attention a WHERE a.club_id=r.club_id AND a.id=r.attention_id) AND NOT EXISTS(SELECT 1 FROM native_social_originals o WHERE o.club_id=r.club_id AND o.source_type='attention' AND o.source_id=r.attention_id AND o.kind IN ('comment','reaction')) AND NOT EXISTS(SELECT 1 FROM native_social_replies newer WHERE newer.club_id=r.club_id AND newer.attention_id=r.attention_id AND newer.reply_slot='' AND (newer.updated_at>r.updated_at OR (newer.updated_at=r.updated_at AND newer.id>r.id)))";
        $savedArgs=[$access->club()];
        if($q['channel']!=='all'){$saved.=' AND r.channel=?';$savedArgs[]=$q['channel'];}
        if($q['state']!=='all'||$q['kind']==='reaction')$saved.=' AND 1=0';
        $savedSql="SELECT r.attention_id AS id,r.id AS source_id,r.channel,'comment' AS kind,'saved' AS state,r.updated_at AS list_at,r.id AS reply_id,'reply' AS entry_type FROM native_social_replies r WHERE ".$saved;
        $archived="o.club_id=? AND o.source_type='attention' AND o.kind IN ('comment','reaction') AND NOT EXISTS(SELECT 1 FROM native_social_attention a WHERE a.club_id=o.club_id AND a.id=o.source_id) AND NOT EXISTS(SELECT 1 FROM native_social_originals newer WHERE newer.club_id=o.club_id AND newer.source_type=o.source_type AND newer.source_id=o.source_id AND (newer.archived_at>o.archived_at OR (newer.archived_at=o.archived_at AND newer.id>o.id)))";$archiveArgs=[$access->club()];
        if($q['channel']!=='all'){$archived.=' AND o.channel=?';$archiveArgs[]=$q['channel'];}
        if($q['kind']!=='all'){$archived.=' AND o.kind=?';$archiveArgs[]=$q['kind'];}
        if($q['state']!=='all')$archived.=' AND 1=0';
        $archiveSql="SELECT o.source_id AS id,o.id AS source_id,o.channel,o.kind,'archived' AS state,o.archived_at AS list_at,'' AS reply_id,'archive' AS entry_type FROM native_social_originals o WHERE ".$archived;
        return [$saved,$savedArgs,$active.' UNION ALL '.$savedSql.' UNION ALL '.$archiveSql,[...$args,...$savedArgs,...$archiveArgs]];
    }

    private function summary(Access $access,array $row):?array
    {
        $access->require('social','manage');$reply=$row['entry_type']==='reply';
        if($row['entry_type']==='archive'){
            $archived=(new OriginalArchive($this->db,$this->box))->get($access,$row['source_id']);
            $data=$this->box->open('social-attention:'.$access->club().':'.$row['id'],$archived['original']['ciphertext']);
            return $row+['name'=>$data['name']?:($data['title']?:'Aktivität'),'text'=>$data['text'],'title'=>$data['title']];
        }
        $table=$reply?'native_social_replies':'native_social_attention';$purpose=$reply?'social-reply:':'social-attention:';
        $stored=Db::run($this->db,'SELECT ciphertext,parent_id FROM '.$table.' WHERE club_id=? AND id=?',[$access->club(),$row['source_id']])->fetch();$bytes=$stored['ciphertext']??null;
        if(!is_string($bytes))throw new DomainException('Gespräch inzwischen geändert.');
        $data=$this->box->open($purpose.$access->club().':'.$row['source_id'],$bytes);
        if($reply&&($data['kind']??'comment')!=='comment')return null;
        if($reply)$data=$data['original'];
        return $row+['name'=>$data['name']?:($data['title']?:'Aktivität'),'text'=>$data['text'],'title'=>$data['title'],'_origin_parent'=>$reply?null:$stored['parent_id']];
    }

    public function listing(Access $access,array $query=[]):array
    {
        $access->require('social','manage');$q=self::query(['review_page'=>$query['page']??1,'review_channel'=>$query['channel']??'all','review_kind'=>$query['kind']??'all','state'=>$query['state']??'all','q'=>$query['q']??'']);
        return Db::atomic($this->db,function()use($access,$q):array{
            $access->require('social','manage');[$saved,$savedArgs,$union,$args]=$this->candidates($access,$q);
            $offset=($q['page']-1)*6;$rows=[];
            $hasSaved=(bool)Db::run($this->db,'SELECT 1 FROM native_social_replies r WHERE '.$saved.' LIMIT 1',$savedArgs)->fetchColumn();
            if($q['q']===''&&!$hasSaved){
                $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM ('.$union.') entries',$args)->fetchColumn();
                $metadata=Db::run($this->db,'SELECT * FROM ('.$union.') entries ORDER BY list_at DESC,id DESC LIMIT 6 OFFSET '.$offset,$args)->fetchAll();
                foreach($metadata as$row)$rows[]=$this->summary($access,$row);
            }else{
                $total=0;$last=null;
                do{
                    $cursor=$last?' WHERE (list_at<? OR (list_at=? AND id<?))':'';
                    $metadata=Db::run($this->db,'SELECT * FROM ('.$union.') entries'.$cursor.' ORDER BY list_at DESC,id DESC LIMIT 100',$last?[...$args,$last['list_at'],$last['list_at'],$last['id']]:$args)->fetchAll();
                    foreach($metadata as$row){
                        $summary=$this->summary($access,$row);$last=$row;
                        if(!$summary)continue;
                        $labels=['comment'=>'Kommentar','reaction'=>'Reaktion','new'=>'Neu','reply_needed'=>'Antwort nötig','done'=>'Erledigt','saved'=>'Gespeichert','archived'=>'Archiviert'];
                        $text=$summary['name'].' '.$summary['text'].' '.$summary['title'].' '.$summary['channel'].' '.($labels[$summary['kind']]??'').' '.($labels[$summary['state']]??'');
                        if($q['q']!==''&&mb_stripos($text,$q['q'])===false)continue;
                        if($total>=$offset&&count($rows)<6)$rows[]=$summary;
                        ++$total;
                    }
                }while(count($metadata)===100);
            }
            $pages=max(1,(int)ceil($total/6));if($q['page']>$pages)throw new DomainException('Gesprächsseite nicht verfügbar.');
            foreach($rows as&$summary){$summary['origin']=isset($summary['_origin_parent'])?$this->origin($access,['channel'=>$summary['channel'],'parent_id'=>$summary['_origin_parent']]):null;unset($summary['_origin_parent']);}unset($summary);
            return ['rows'=>$rows,'total'=>$total,'pages'=>$pages]+$q;
        });
    }

    public function attention(Access $access,string $id,string $original=''):array
    {
        $access->require('social','manage');Content::id($id);
        $row=$original===''?Db::run($this->db,"SELECT * FROM native_social_attention WHERE club_id=? AND id=? AND kind IN ('comment','reaction')",[$access->club(),$id])->fetch():false;
        if(!$row){
            if($original==='')$original=Db::run($this->db,"SELECT id FROM native_social_originals WHERE club_id=? AND source_type='attention' AND source_id=? AND kind IN ('comment','reaction') ORDER BY archived_at DESC,id DESC LIMIT 1",[$access->club(),$id])->fetchColumn()?:'';
            if($original==='')throw new DomainException('Gespräch nicht mehr verfügbar.');
            $archive=(new OriginalArchive($this->db,$this->box))->get($access,$original);
            if($archive['source_type']!=='attention'||$archive['source_id']!==$id||!in_array($archive['kind'],['comment','reaction'],true))throw new DomainException('Fassung gehört nicht zu diesem Gespräch.');
            $row=$archive['original']+['_archived'=>true,'_archive_id'=>$original,'_history_connection'=>$archive['source_context']['connection_id']??null];
        }
        $row['payload']=$this->box->open('social-attention:'.$access->club().':'.$id,$row['ciphertext']);unset($row['ciphertext']);return $row;
    }
}
