<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
final class Download
{
    private string $path;
    public readonly string $name,$mime,$sha256;
    public readonly int $bytes;
    public function __construct(array $file,private readonly Context $context,private readonly ?\Closure $authorize=null,private readonly bool $general=false){$this->path=$file['path'];$this->name=$file['name'];$this->mime=$file['mime'];$this->bytes=(int)$file['bytes'];$this->sha256=$file['sha256'];}
    public function send(bool $head=false):void
    {
        $this->context->require('files.read');
        if($this->authorize!==null){$current=($this->authorize)();if(($current['path']??null)!==$this->path||($current['bytes']??null)!==$this->bytes||!hash_equals($this->sha256,$current['sha256']??''))throw Problem::missing();}
        if(is_link($this->path)||!is_file($this->path)||filesize($this->path)!==$this->bytes||!hash_equals($this->sha256,hash_file('sha256',$this->path)))throw Problem::missing();
        $handle=fopen($this->path,'rb');if(!$handle)throw Problem::missing();
        try{$this->context->require('files.read');if($this->authorize!==null)($this->authorize)();if($this->general){foreach(\Kulturhus\Native\Files\Download::headers(['name'=>$this->name,'mime'=>$this->mime,'bytes'=>$this->bytes,'sha256'=>$this->sha256])as$name=>$value)header($name.': '.$value);}else{header('Content-Type: '.$this->mime);header('Content-Length: '.$this->bytes);header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($this->name));}if(!$head)while(!feof($handle)&&!connection_aborted()){echo fread($handle,65536);}}finally{fclose($handle);}
    }
}
