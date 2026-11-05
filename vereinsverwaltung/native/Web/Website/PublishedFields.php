<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{Pages,Media,FieldValues};
use Kulturhus\Native\Web\{Html,MediaCredit};

/** Original published definitions control values; secrets and private items have no public output. */
final class PublishedFields
{
    public function __construct(private Pages $pages,private Media $media,private ?\Closure $privateMediaUrl=null,private ?\Closure $privateFileUrl=null){}
    public function render(array$p):string
    {return $this->fields($p['field_definitions']??[],$p['custom_fields']??[],$p);}
    private function fields(array$definitions,array$values,array$page):string
    {
        $h='';
        foreach($definitions as$key=>$field){
            $value=$values[$key]??null;
            if($value===null||$value===[]||$field['type']==='password'||($field['display']??'visible')==='hidden')continue;
            $formatted='';
            if($field['type']==='repeater'){
                foreach($value as$item){if(!($item['public']??true))continue;$body=$this->fields($field['item_definitions'],$item['values'],$page);if($body!=='')$formatted.='<li>'.$body.'</li>';}
                if($formatted!=='')$formatted='<ol class="list-unstyled">'.$formatted.'</ol>';
            }elseif(FieldValues::collection($field)){
                foreach($value as$item){$body=$this->value($field,$item,$page);if($body!=='')$formatted.='<li>'.$body.'</li>';}
                if($formatted!=='')$formatted='<ul>'.$formatted.'</ul>';
            }else $formatted=$this->value($field,$value,$page);
            if($formatted!=='')$h.='<div><dt>'.Html::e($field['label']).'</dt><dd>'.$formatted.'</dd></div>';
        }
        return $h!==''?'<dl class="custom-fields-public">'.$h.'</dl>':'';
    }
    private function value(array$field,mixed$value,array$page):string
    {
        $e=Html::e(...);
        if($field['type']==='richtext')return '<div class="kh-richtext">'.\Kulturhus\Native\Shared\RichText::sanitize((string)$value).'</div>';
        if(in_array($field['type'],['page-reference','pagetable'],true)){
            try{$target=$this->pages->get((string)$value);}catch(\RuntimeException|\DomainException){return '';}
            $published=($target['published_slug']??'')!==''?$this->pages->publicPage($target['published_slug']):null;
            return $published?'<a href="/site/'.$e($published['slug']).'">'.$e($published['title']).'</a>':'';
        }
        if($field['type']==='media-reference'){
            $variant=$this->privateMediaUrl? $this->media->variant((string)$value,'medium'):$this->media->publicVariant((string)$value,'medium',$page);
            $url=$this->privateMediaUrl?($this->privateMediaUrl)((string)$value,'medium'):'/media/'.$value.'/medium';
            return $variant?'<img class="custom-field-image" loading="lazy" src="'.$e($url).'" alt="'.$e($variant['alt']).'">'.MediaCredit::render($variant):'';
        }
        if($field['type']==='file-reference'){
            foreach($page['custom_file_metadata']??[]as$file)if($file['id']===$value)return '<a download href="'.$e($this->privateFileUrl?($this->privateFileUrl)((string)$value):(($file['source']??null)==='asset'?'/file/':'/website-document/').$value).'">'.$e($file['label']).'</a>';
            return '';
        }
        return $e(is_bool($value)?($value?'Ja':'Nein'):(string)$value);
    }
}
