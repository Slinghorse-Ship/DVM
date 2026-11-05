<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,Identity};
use Kulturhus\Native\Cms\Media;
/** Frozen local image pixels and exact source metadata; private consent never leaves storage. */
final class Assets
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}
    public function freeze(array $item,bool $test):array
    {
        $files=[];
        try {
            return Db::atomic($this->db,function()use($item,$test,&$files):array{
                $actor=$this->access->require('newsletter',$test?'edit':'publish');$club=$actor['club_id'];
                $identity=Identity::forClub($this->db,$club);$refs=ImageUsage::refs($item,$identity['logo_id']);
                $usage=ImageUsage::item($this->db,$club,$item,'newsletter');
                $expected=array_keys($usage['media']);sort($expected);$actual=$refs;sort($actual);
                if($expected!==$actual)throw new \DomainException('Bildquellen oder Logo geändert. Ausgabe prüfen und erneut speichern.');
                $item['_identity']=$identity;$item['_media_usage']=$usage;
                $root=dirname($this->mediaRoot).'/communication-images';
                if(is_link($root)||(!is_dir($root)&&!mkdir($root,0700,true)))throw new \RuntimeException('Privater Bildspeicher fehlt.');
                $item['_image_urls']=[];
                foreach($refs as $ref){
                    $variant=(new Media($this->db,$this->access,$this->mediaRoot))->variant($ref,'medium');
                    $source=imagecreatefromstring(file_get_contents($variant['path']));
                    if(!$source)throw new \DomainException('Newsletterbild nicht lesbar.');
                    $id=bin2hex(random_bytes(16));$file=$root.'/'.$id.'.png';$files[]=$file;
                    try{
                        if(!imagepng($source,$file,6))throw new \RuntimeException('Bildspeicherung fehlgeschlagen.');chmod($file,0600);
                        $token=bin2hex(random_bytes(32));
                        $sources=['schema'=>1,'channel'=>'newsletter','media'=>[$ref=>$usage['media'][$ref]]];
                        Db::run($this->db,'INSERT INTO native_social_images(id,club_id,item_id,item_version,content_hash,slide,kind,sha256,metadata_json,created_at) VALUES(?,?,?,?,?,0,\'newsletter\',?,?,UTC_TIMESTAMP())',[$id,$club,$item['id'],$item['version'],Images::fingerprint($item),hash_file('sha256',$file),json_encode(['renderer'=>'cms-pixel-snapshot','media_ref'=>$ref,'sources'=>$sources],JSON_THROW_ON_ERROR)]);
                        Db::run($this->db,'INSERT INTO native_image_capabilities(token_hash,club_id,image_id,expires_at) VALUES(?,?,?,?)',[hash('sha256',$token),$club,$id,$test?gmdate('Y-m-d H:i:s',time()+7*86400):null]);
                        $item['_image_urls'][$ref]=$identity['origin'].'/publication-image/'.$token;
                    }finally{unset($source);}
                }
                if($this->access->require('newsletter',$test?'edit':'publish')['club_id']!==$club)throw new \DomainException('Vereinszuordnung geändert.');
                ImageUsage::guard($this->db,$club,$usage,'newsletter');
                return $item;
            });
        }catch(\Throwable $error){foreach($files as$file)if(!is_link($file)&&is_file($file))@unlink($file);throw $error;}
    }
}
