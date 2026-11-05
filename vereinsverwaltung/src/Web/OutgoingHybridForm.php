<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\{Finance,OutgoingEInvoice};

/** Pure recovery controls for the existing source-bound hybrid document writer. */
final class OutgoingHybridForm
{
    public static function requested(array $post):bool
    {
        return array_key_exists('hybrid_current',$post)||array_key_exists('hybrid_adopt',$post);
    }

    public static function retained(array $post,string $id):bool
    {
        return ($post['action']??null)==='outgoing-hybrid-save'&&($post['entry_id']??null)===$id;
    }

    public static function state(string $id,?array $current,array $post):array
    {
        $retained=self::retained($post,$id);
        $version=$retained?(is_string($post['entry_version']??null)?$post['entry_version']:''):(string)($current['source']['entry']['version']??'');
        $hash=$retained?(is_string($post['source_hash']??null)?$post['source_hash']:''):(string)($current['source_hash']??'');
        $country=$retained&&is_string($post['domestic_country']??null)?$post['domestic_country']:'';
        return ['retained'=>$retained,'version'=>$version,'source_hash'=>$hash,'country'=>$country,'changed'=>$current===null||$version!==(string)$current['source']['entry']['version']||!hash_equals($current['source_hash'],$hash)];
    }

    /** Both pure commands and the actual save must refer to this route's document. */
    public static function target(array $post,array $query):string
    {
        $id=$post['entry_id']??null;
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_string($post['entry_version']??null)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$post['entry_version'])||!is_string($post['source_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$post['source_hash']))throw new DomainException('Ursprüngliche Rechnung und geprüften Versionsstand vollständig prüfen.',422);
        if(($query['view']??null)!=='finance'||($query['tab']??'receipts')!=='receipts'||($query['entry']??null)!==$id)throw new DomainException('Dieses Erstellungsformular gehört zu einer anderen Rechnung.',409);
        return $id;
    }

    public static function current(PDO $db,string $actor,string $id):array
    {
        $own=!$db->inTransaction();
        if($own)$db->beginTransaction();
        try{
            $finance=new Finance($db,$actor);
            if(!$finance->canWrite())throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403);
            $entry=$finance->entry($id);
            $source=(new OutgoingEInvoice($db,$actor))->hybridSource($id,(int)$entry['version']);
            $source['comparison_hash']=hash('sha256',json_encode(['entry'=>$source['source']['entry'],'invoice_id'=>$source['invoice']['id'],'invoice_version'=>$source['invoice']['version'],'source_hash'=>$source['source_hash'],'original_sha256'=>$source['original']['sha256']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return $source;
        }finally{
            if($own&&$db->inTransaction())$db->rollBack();
        }
    }

    public static function handle(PDO $db,string $actor,array &$post,array $query):array
    {
        $id=self::target($post,$query);
        $commands=array_intersect(['hybrid_current','hybrid_adopt'],array_keys($post));
        if(count($commands)!==1||$post[$commands[array_key_first($commands)]]!=='1')throw new DomainException('Genau eine angebotene Rechnungsansicht wählen.',422);
        $current=self::current($db,$actor,$id);
        if(array_key_exists('hybrid_current',$post))return ['comparison'=>$current,'notice'=>'Aktuelle Originalrechnung gelesen. Deine Auswahl und ihr ursprünglicher Versionsstand bleiben erhalten.'];
        if(!is_string($post['hybrid_compare_hash']??null)||!hash_equals($current['comparison_hash'],$post['hybrid_compare_hash']))throw new DomainException('Der verglichene Rechnungsstand hat sich geändert. Zuerst erneut vergleichen.',409);
        $post['entry_version']=(string)$current['source']['entry']['version'];
        $post['source_hash']=$current['source_hash'];
        return ['notice'=>'Weiterbearbeitung mit der verglichenen Originalrechnung vorbereitet. Die Datei erst gesondert prüfen und erstellen.'];
    }
}
