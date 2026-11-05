<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Communication\{Workspace,Content,SubscriberSelection};

final class EditionPreview
{
    public static function item(PDO $db,Access $access,string $module,string $id,array $query): array
    {
        $w=new Workspace($db,$access,$module);
        return array_key_exists('revision',$query)?$w->revision($id,SubscriberSelection::number($query['revision'],4294967295)):$w->get($id);
    }
    public static function render(array $item,callable $image,array $styles,string $attachments=''): string
    {
        if(($item['module']??'')==='newsletter'&&isset($item['_identity']))return \Kulturhus\Native\Shared\MailDesign::browserNewsletter($item,Content::preview($item,$image,false,false).$attachments);
        $e=\Kulturhus\Native\Web\Html::e(...);$html='<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($item['title']).' · Fassung '.(int)$item['version'].'</title>';
        foreach($styles as$style)$html.='<link rel="stylesheet" href="'.$e($style).'">';
        return$html.'</head><body><main class="panel edition-preview"><p class="edition-preview-version">Fassung '.(int)$item['version'].'</p>'.Content::preview($item,$image).$attachments.'</main></body></html>';
    }
    public static function image(PDO $db,Access $access,string $root,array $query): array
    {
        foreach(['module','item','media']as$key)if(!is_string($query[$key]??null))throw new \DomainException('Originalbild auswählen.');
        $version=SubscriberSelection::number($query['revision']??0,4294967295);$item=(new Workspace($db,$access,$query['module']))->revision($query['item'],$version);
        $scoped=new \Kulturhus\Native\Communication\EditionImageAccess($db,$access,$item,$query['media']);
        $attachment=array_key_exists('attachment',$query);if($attachment&&($query['attachment']!=='1'||$query['module']!=='press'||!in_array($query['media'],$item['attachment_media_ids']??[],true)))throw new \DomainException('Fotoanlage dieser Originalfassung auswählen.');
        $file=(new \Kulturhus\Native\Cms\Media($db,$scoped,$root))->variant($query['media'],$attachment?'original':'medium');if($attachment){$hash=Db::run($db,'SELECT sha256 FROM khcms_media WHERE club_id=? AND id=?',[$access->club(),$query['media']])->fetchColumn();if(!is_string($hash)||!hash_equals($hash,$file['sha256']))throw new \DomainException('Ursprüngliche Fotoanlage verändert.');$file['attachment_name']=\Kulturhus\Native\Communication\PressPhotoAttachments::filename($file);}$scoped->user();return$file;
    }
}
