<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use InvalidArgumentException;

/** Supported downloads only. No archive extraction or uploaded script execution. */
final class Formats
{
    public const MAX_BYTES=25*1024*1024;
    public const MAX_BATCH_BYTES=50*1024*1024;
    public const EXTENSIONS=['pdf','txt','csv','json','xml','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','zip','gz','7z','mp3','wav','ogg','m4a','mp4','webm'];
    public static function inspect(string $path,string $name):array
    {
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||is_link($path)||!is_file($path)||!is_readable($path))throw new InvalidArgumentException('Lokale Datei auswählen.');
        if(!preg_match('//u',$name)||preg_match('/[\x00-\x1f\x7f]/',$name))throw new InvalidArgumentException('Dateiname prüfen.');
        $name=basename(str_replace('\\','/',$name));$extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if($name===''||mb_strlen($name)>240||!in_array($extension,self::EXTENSIONS,true))throw new InvalidArgumentException('Unterstütztes Dokument, Archiv oder Audio-/Videoformat auswählen.');
        $bytes=filesize($path);
        if(!is_int($bytes)||$bytes<1||$bytes>self::MAX_BYTES)throw new InvalidArgumentException('Dateien bis 25 MiB auswählen.');
        $handle=fopen($path,'rb');if(!$handle)throw new InvalidArgumentException('Datei nicht lesbar.');
        try{$head=fread($handle,64);fseek($handle,max(0,$bytes-2048));$tail=stream_get_contents($handle);}finally{fclose($handle);}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $valid=false;$canonical='';
        if($extension==='pdf'){$valid=$mime==='application/pdf'&&preg_match('/^%PDF-(?:1\.[0-7]|2\.0)/D',$head)&&preg_match('/%%EOF\s*$/D',$tail);$canonical='application/pdf';}
        elseif(in_array($extension,['txt','csv','json','xml'],true)){
            $text=file_get_contents($path);$valid=is_string($text)&&preg_match('//u',$text)&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text);
            // All text is downloaded as plain text, including JSON/XML; no browser document context.
            $canonical=$extension==='csv'?'text/csv':'text/plain';
        }elseif(in_array($extension,['zip','docx','xlsx','pptx','odt','ods','odp'],true)){
            $valid=str_starts_with($head,"PK\x03\x04")||str_starts_with($head,"PK\x05\x06");$canonical='application/zip';
            if($extension!=='zip'){
                if(!class_exists(\ZipArchive::class))throw new InvalidArgumentException('Office-Dateien benötigen die ZIP-Erweiterung des Servers.');
                $zip=new \ZipArchive();$opened=$zip->open($path,\ZipArchive::RDONLY);$valid=$valid&&$opened===true;
                try{
                    if($valid){
                        if($zip->numFiles>10000)$valid=false;
                        $parts=['docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml'];
                        if(isset($parts[$extension])){$valid=$valid&&$zip->locateName('[Content_Types].xml')!==false&&$zip->locateName($parts[$extension])!==false;$canonical=['docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation'][$extension];}
                        else{$canonical=['odt'=>'application/vnd.oasis.opendocument.text','ods'=>'application/vnd.oasis.opendocument.spreadsheet','odp'=>'application/vnd.oasis.opendocument.presentation'][$extension];$valid=$valid&&$zip->getFromName('mimetype',200)===$canonical;}
                    }
                }finally{if($opened===true)$zip->close();}
            }
        }elseif(in_array($extension,['doc','xls','ppt'],true)){$valid=str_starts_with($head,"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1");$canonical=['doc'=>'application/msword','xls'=>'application/vnd.ms-excel','ppt'=>'application/vnd.ms-powerpoint'][$extension];}
        elseif($extension==='gz'){$valid=str_starts_with($head,"\x1f\x8b\x08");$canonical='application/gzip';}
        elseif($extension==='7z'){$valid=str_starts_with($head,"7z\xbc\xaf\x27\x1c");$canonical='application/x-7z-compressed';}
        elseif($extension==='mp3'){$valid=in_array($mime,['audio/mpeg','audio/mp3'],true)&&(str_starts_with($head,'ID3')||(strlen($head)>1&&ord($head[0])===255&&(ord($head[1])&224)===224));$canonical='audio/mpeg';}
        elseif($extension==='wav'){$valid=str_starts_with($head,'RIFF')&&substr($head,8,4)==='WAVE';$canonical='audio/wav';}
        elseif($extension==='ogg'){$valid=str_starts_with($head,'OggS')&&in_array($mime,['audio/ogg','video/ogg','application/ogg'],true);$canonical='audio/ogg';}
        elseif(in_array($extension,['m4a','mp4'],true)){$valid=substr($head,4,4)==='ftyp'&&in_array($mime,['video/mp4','audio/mp4','application/mp4','audio/x-m4a'],true);$canonical=$extension==='m4a'?'audio/mp4':'video/mp4';}
        elseif($extension==='webm'){$valid=str_starts_with($head,"\x1a\x45\xdf\xa3")&&in_array($mime,['video/webm','audio/webm'],true);$canonical='video/webm';}
        if(!$valid)throw new InvalidArgumentException('Dateiinhalt und Dateiformat stimmen nicht überein.');
        return ['name'=>$name,'mime'=>$canonical,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$path)];
    }
}
