<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;

use Kulturhus\Native\Cms\{Conflict, Media, NewsChannels, Pages};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\Html;
use PDO;

/** The authenticated saved draft, with its own original field definitions and private images. */
final class NewsReadingView
{
    private readonly Media $media;
    public function __construct(private readonly PDO $db, private readonly Access $access, string $mediaRoot)
    {
        $this->media=new Media($db,$access,$mediaRoot);
    }

    public function render(string $id, int $version): string
    {
        return Db::atomic($this->db,function()use($id,$version):string{
            $this->access->require('website','read');
            $page=(new Pages($this->db,$this->access))->get($id);
            if ($page['version']!==$version) throw new Conflict('Die Beitragsfassung wurde inzwischen geändert. Bitte neu laden.');
            if (!NewsChannels::isNews($page)) throw new \InvalidArgumentException('Gespeicherte Beitragsfassung auswählen.');
            $date=$page['news_date']??'';
            $channels=NewsChannels::normalize($page['news_channels']??NewsChannels::ALL);
            $html='<article class="news-reading" aria-label="Gespeicherte Beitragsfassung" data-news-reading data-source-version="'.Html::e($version).'"><p class="hint">Gespeicherte Fassung · Version '.Html::e($version).'</p><h2>'.Html::e($page['title']).'</h2><dl class="news-reading-meta"><dt>Beitragsdatum</dt><dd>'.($date!==''?'<time datetime="'.Html::e($date).'">'.Html::e(substr($date,8,2).'.'.substr($date,5,2).'.'.substr($date,0,4)).'</time>':'Keine Angabe').'</dd><dt>Veröffentlichungskanäle</dt><dd>'.($channels?Html::e(implode(', ',array_map(static fn($c)=>['website'=>'Website','rss'=>'RSS-Feed'][$c],$channels))):'Keine öffentliche Ausgabe').'</dd></dl><p class="news-reading-excerpt">'.Html::text($page['excerpt']).'</p><div class="kh-richtext">'.Html::content($page).'</div>';
            $images=$page['media_ids']??[];
            if($images){
                $html.='<section aria-label="Gespeicherte Bilder"><h3>Bilder</h3>'.$this->image($images[0],1,$page);
                if(count($images)>1){
                    $html.='<details><summary>Weitere Bilder ('.(count($images)-1).')</summary><div class="news-reading-scroll news-reading-gallery" tabindex="0" role="region" aria-label="Weitere gespeicherte Bilder">';
                    foreach(array_slice($images,1)as$i=>$image)$html.=$this->image($image,$i+2,$page);
                    $html.='</div></details>';
                }
                $html.='</section>';
            }
            $values=new VersionValues($this->db,$this->access,false);
            if($fields=$page['custom_fields']??[]){
                $html.='<details><summary>Zusätzliche Angaben ('.count($fields).')</summary><div class="news-reading-scroll" tabindex="0" role="region" aria-label="Gespeicherte zusätzliche Angaben"><dl>';
                foreach($fields as$key=>$value){
                    $definition=$page['field_definitions'][$key]??[];
                    $html.='<div data-news-reading-field="'.Html::e($key).'"><dt>'.Html::e($definition['label']??'Weitere Angabe').'</dt><dd>'.(($definition['type']??'')==='media-reference'&&$value!==null&&$value!==''?$this->image($value,null,$page):$values->render(['type'=>$definition['type']??'text','value'=>$value])).'</dd></div>';
                }
                $html.='</dl></div></details>';
            }
            $html.='<details><summary>Adresse, Menü und Suchmaschinen</summary><div class="news-reading-scroll" tabindex="0" role="region" aria-label="Gespeicherte Adresse und Suchmaschinenangaben"><dl>';
            foreach(['slug'=>['Adresse','text'],'parent_id'=>['Übergeordnete Seite','page-reference'],'nav_visible'=>['Im Menü anzeigen','boolean'],'nav_label'=>['Menütitel','text'],'nav_order'=>['Menüreihenfolge','text'],'seo_title'=>['SEO-Titel','text'],'seo_description'=>['SEO-Beschreibung','text'],'noindex'=>['Suchmaschinen ausschließen','boolean']]as$key=>[$label,$type]){
                $html.='<div><dt>'.Html::e($label).'</dt><dd>'.$values->render(['type'=>$type,'value'=>$page[$key]??null]).'</dd></div>';
            }
            $this->access->require('website','read');
            return $html.'</dl></div></details></article>';
        });
    }

    private function image(string $id, ?int $position=null,array $page=[]): string
    {
        $label=$position===null?'Verknüpftes Bild':($position===1?'Titelbild':'Bild '.$position);
        try{$image=array_replace($this->media->variant($id,'medium'),\Kulturhus\Native\Cms\MediaMetadata::original($this->db,$this->access->club(),$id,$page));}
        catch(\RuntimeException){return '<figure class="news-reading-image"><figcaption>'.Html::e($label).'</figcaption><p>Bild aktuell nicht verfügbar.</p></figure>';}
        return '<figure class="news-reading-image"><a data-native-media href="/media-preview/'.Html::e($id).'/large" target="_blank" rel="noopener" aria-label="'.Html::e($label.': '.($image['alt']?:'Große Ansicht')).'"><img loading="lazy" decoding="async" src="/media-preview/'.Html::e($id).'/medium" width="'.Html::e($image['width']).'" height="'.Html::e($image['height']).'" alt="'.Html::e($image['alt']).'"></a><figcaption><strong>'.Html::e($label).'</strong>'.($image['alt']!==''?' · '.Html::e($image['alt']):'').\Kulturhus\Native\Web\MediaCredit::render($image).'</figcaption></figure>';
    }
}
