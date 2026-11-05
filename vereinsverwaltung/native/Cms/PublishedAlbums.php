<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Only small keys from current published originals; private payloads are never selected. */
final class PublishedAlbums
{
    public const PAGE_SIZE=6;
    public function __construct(private PDO $db,private Access $access) {}
    public function listing(int $requested=1): array
    {
        if($requested<1||$requested>83334)throw new InvalidArgumentException('Ungültige Seitennummer.');
        return Db::atomic($this->db,function()use($requested):array{
            $actor=$this->access->require('website','read');$state=(new AlbumOrder($this->db,$this->access))->state();
            [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
            $keys=Db::run($this->db,"SELECT p.id,p.published_slug AS slug,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.title')) AS title,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.nav_order')) AS nav_order FROM khcms_pages p JOIN khcms_revisions r ON r.club_id=p.club_id AND r.page_id=p.id AND r.revision=p.published_revision WHERE (".$policy.") AND p.published_slug<>'' AND ".NewsChannels::where()." AND JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.slug'))<>'galerie' AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template_definition.renderer')),'null'),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template')))='gallery' ORDER BY p.updated_at DESC,p.id",$parameters)->fetchAll(PDO::FETCH_ASSOC);
            $keys=AlbumOrder::sortKeys($keys,$state['ids']);$total=count($keys);$last=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($requested,$last);
            return ['items'=>array_slice($keys,($page-1)*self::PAGE_SIZE,self::PAGE_SIZE),'total'=>$total,'page'=>$page,'last'=>$last];
        });
    }
}
