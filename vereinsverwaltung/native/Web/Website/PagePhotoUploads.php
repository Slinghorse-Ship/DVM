<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\MediaUploads;

final class PagePhotoUploads
{
    /** Actual multipart bytes only; client names, extensions and MIME declarations confer no trust. */
    public static function fromFiles(array $files,string $field='page_photos'): array
    {
        $f=$files[$field]??null;if($f===null)return [];
        if(!is_array($f)||!is_array($f['error']??null)||count($f['error'])>MediaUploads::MAX_FILES)throw new \InvalidArgumentException('Höchstens zehn Fotos gleichzeitig speichern.');
        $out=[];
        foreach($f['error']as$i=>$error){
            if($error===UPLOAD_ERR_NO_FILE)continue;
            if($error!==UPLOAD_ERR_OK||!is_string($f['tmp_name'][$i]??null)||!is_uploaded_file($f['tmp_name'][$i])||!is_string($f['name'][$i]??null))throw new \InvalidArgumentException('Fotoupload fehlgeschlagen. Bitte Dateigröße prüfen.');
            $out[]=['path'=>$f['tmp_name'][$i],'name'=>$f['name'][$i]];
        }
        return $out;
    }
}
