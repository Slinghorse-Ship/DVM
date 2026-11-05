<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\WebsiteDocuments;
use Kulturhus\Native\Web\Html;

final class DocumentLinks
{
    /** On a rejected native HTML POST, retain editable text without changing saved originals. */
    public static function rejectedPage(array $page,array $post,string $csrf): array
    {
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || http_response_code()<400 || ($post['operation']??null)!=='page-save' || !is_string($post['csrf']??null) || !hash_equals($csrf,$post['csrf']) || ($post['id']??'')!==$page['id']) return $page;
        foreach (['title','slug','excerpt','seo_title','seo_description','body_html','nav_order','news_date','event_start','event_timezone','event_location','event_price'] as $key) {
            if (is_string($post[$key]??null) && strlen($post[$key])<=400000 && preg_match('//u',$post[$key]) && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$post[$key])) $page[$key]=$post[$key];
        }
        $page['nav_visible']=isset($post['nav_visible']);$page['noindex']=isset($post['noindex']);
        if (is_string($post['version']??null) && preg_match('/^(0|[1-9][0-9]{0,9})$/D',$post['version'])) $page['version']=(int)$post['version'];
        return $page;
    }
    public static function fields(array $page): string
    {
        $e=Html::e(...);$h='<details class="website-documents"><summary>PDF-Dokumente</summary>'.Html::hidden('document_links_present','1');
        foreach ($page['document_links']??[] as $i=>$link) {
            $h.='<div class="website-document-row">'.Html::hidden('document_rows['.$i.'][id]',$link['id']).'<a download data-native-document href="/website-document-preview/'.$e($link['id']).'">'.$e($link['name']).'</a>'.Html::input('document_rows['.$i.'][label]','Linktitel',$link['label']).'<label class="check"><input class="check" type="checkbox" name="document_rows['.$i.'][remove]" value="1"> Entfernen</label></div>';
        }
        return $h.'<label for="website-pdf-upload">PDF hinzufügen</label><input class="form-control" id="website-pdf-upload" name="website_pdfs[]" type="file" accept="application/pdf,.pdf" multiple></details>';
    }
    public static function fromPost(array $post): ?array
    {
        if (!array_key_exists('document_links_present',$post)) return null;
        if ($post['document_links_present']!=='1') throw new \InvalidArgumentException('Dokumentenauswahl prüfen.');
        $rows=$post['document_rows']??[];
        if (!is_array($rows) || count($rows)>20) throw new \InvalidArgumentException('Höchstens 20 Dokumente je Seite auswählen.');
        $links=[];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row),['id','label','remove']) || (isset($row['remove']) && $row['remove']!=='1')) throw new \InvalidArgumentException('Dokumentenauswahl prüfen.');
            if (!isset($row['remove'])) $links[]=['id'=>$row['id']??null,'label'=>$row['label']??''];
        }
        return WebsiteDocuments::normalize($links);
    }
    public static function uploads(array $files): array
    {
        $f=$files['website_pdfs']??null;
        if ($f===null) return [];
        if (!is_array($f) || !is_array($f['error']??null) || count($f['error'])>10) throw new \InvalidArgumentException('Höchstens zehn PDF-Dateien gleichzeitig auswählen.');
        $out=[];
        foreach ($f['error'] as $i=>$error) {
            if ($error===UPLOAD_ERR_NO_FILE) continue;
            if ($error!==UPLOAD_ERR_OK || !is_string($f['tmp_name'][$i]??null) || !is_uploaded_file($f['tmp_name'][$i]) || !is_string($f['name'][$i]??null)) throw new \InvalidArgumentException('PDF-Upload fehlgeschlagen. Bitte Dateigröße prüfen.');
            $out[]=['path'=>$f['tmp_name'][$i],'name'=>$f['name'][$i]];
        }
        return $out;
    }
    public static function render(array $page,bool $private=false): string
    {
        $h='';$e=Html::e(...);
        foreach ($page['document_links']??[] as $link) $h.='<li><a download'.($private?' data-native-document':'').' href="'.($private?'/website-document-preview/':'/website-document/').$e($link['id']).'">'.$e($link['label']).'</a> <span>PDF · '.$e(number_format($link['bytes']/1024,0,',','.')).' KB</span></li>';
        return $h!==''?'<div class="website-documents"><ul>'.$h.'</ul></div>':'';
    }
}
