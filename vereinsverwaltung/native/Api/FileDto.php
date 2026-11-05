<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;

use Kulturhus\Native\Files\Metadata;
use Kulturhus\Native\Shared\MediaDescription;

/** Closed API records distinguish a mutable group from an immutable file reference. */
final class FileDto
{
    private static function metadata(array $value):array
    {return array_intersect_key($value,array_flip(['title','description','tags',...MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS]));}
    public static function asset(array $row):array
    {
        $out=['resource'=>'asset','id'=>$row['id'],'asset_kind'=>$row['kind'],'version'=>(int)$row['version'],'revision'=>(int)$row['revision'],'revision_id'=>$row['revision_id'],'state'=>$row['state'],'name'=>$row['name'],'mime'=>$row['mime'],'bytes'=>(int)$row['bytes'],'sha256'=>$row['sha256'],'metadata'=>self::metadata($row['metadata'])];
        if(isset($row['usage']))$out['usage']=array_intersect_key($row['usage'],array_flip(Metadata::RIGHTS));
        if(isset($row['source_metadata_version'])){$out['media_version']=(int)$row['source_metadata_version'];$out['source_metadata']=self::metadata($row['source_metadata']);}
        return $out;
    }
    public static function revision(array $row):array
    {
        $out=['resource'=>'revision','id'=>$row['revision_id'],'asset_id'=>$row['asset_id'],'asset_kind'=>$row['kind'],'revision'=>(int)$row['revision'],'name'=>$row['name'],'mime'=>$row['mime'],'bytes'=>(int)$row['bytes'],'sha256'=>$row['sha256'],'metadata'=>self::metadata($row['metadata'])];
        if(isset($row['restored_from']))$out['restored_from']=$row['restored_from'];
        return $out;
    }
    public static function removal(array $row):array
    {
        $out=['resource'=>'removal','id'=>$row['id'],'asset_kind'=>$row['kind'],'version'=>(int)$row['version'],'state'=>$row['state'],'operation'=>$row['operation'],'retain_until'=>$row['retain_until'],'retained_bytes'=>(int)$row['retained_bytes'],'purge_eligible'=>(bool)$row['purge_eligible']];
        return $out;
    }
}
