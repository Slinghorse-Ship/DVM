<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Communication\{DocumentAttachments,Content};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\MediaPolicy;
use Kulturhus\Native\Web\Html;
final class PdfAttachments
{
    public static function fromPost(array $post):?array
    {
        if(!array_key_exists('documents_present',$post))return null;
        if($post['documents_present']!=='1'||!is_array($post['attachment_rows']??[])||count($post['attachment_rows']??[])>10)throw new DomainException('PDF-Auswahl prüfen.');
        $refs=[];foreach($post['attachment_rows']??[]as$row){if(!is_array($row)||array_diff(array_keys($row),['id','remove'])||(isset($row['remove'])&&$row['remove']!=='1'))throw new DomainException('PDF-Auswahl prüfen.');if(!isset($row['remove']))$refs[]=['id'=>Content::id($row['id']??null)];}
        return DocumentAttachments::normalize($refs);
    }
    public static function uploads(array $files):array
    {
        return \Kulturhus\Native\Web\Website\DocumentLinks::uploads(['website_pdfs'=>$files['newsletter_pdfs']??null]);
    }
    public static function rights(array $post):array
    {
        return DocumentAttachments::rights(['confirmed'=>($post['attachment_rights_confirmed']??null)==='1','credit'=>$post['attachment_credit']??'','source_name'=>$post['attachment_source']??'','license_name'=>$post['attachment_license']??'']);
    }
    public static function fields(PDO $db,Access $access,string $mediaRoot,array $item):string
    {
        if(!DocumentAttachments::installed($db))return'';$access->require('newsletter','edit');
        $refs=$item['document_attachments']??[];$rows=[];try{$rows=(new DocumentAttachments($db,$access,$mediaRoot))->capture($refs,'edit',false);}catch(DomainException){foreach($refs as$ref)$rows[]=['id'=>$ref['id'],'name'=>'PDF-Auswahl prüfen','bytes'=>0];}
        $h='<details class="newsletter-pdf-attachments"><summary>PDF-Anlagen'.($rows?' · '.count($rows):'').'</summary>'.Html::hidden('documents_present','1');
        foreach($rows as$i=>$row){if($i===6)$h.='<details><summary>Weitere '.(count($rows)-6).' Anlagen</summary>';$h.='<div class="website-document-row">'.Html::hidden('attachment_rows['.$i.'][id]',$row['id']).'<span>'.Html::e($row['name']).' · '.(int)ceil($row['bytes']/1024).' KB</span><label class="check"><input class="check" type="checkbox" name="attachment_rows['.$i.'][remove]" value="1"> Entfernen</label></div>';}
        if(count($rows)>6)$h.='</details>';
        $h.='<label>PDF hinzufügen<input class="form-control" type="file" name="newsletter_pdfs[]" accept="application/pdf,.pdf" multiple></label>';
        $policy=MediaPolicy::current($db,$access->club());$rights=$item['attachment_rights']??[];
        if($policy['check_rights'])$h.='<label class="check"><input class="check" type="checkbox" name="attachment_rights_confirmed" value="1"'.(($rights['confirmed']??false)?' checked':'').'> Nutzungsrechte der neuen Dateien geprüft</label>';
        if($policy['require_details'])foreach(['credit'=>'Credit','source_name'=>'Quelle','license_name'=>'Lizenz']as$key=>$label)$h.=Html::input(['credit'=>'attachment_credit','source_name'=>'attachment_source','license_name'=>'attachment_license'][$key],$label,$rights[$key]??'');
        return$h.'</details>';
    }
    public static function links(array $item):string
    {
        $refs=$item['document_attachments']??[];if(!$refs)return'';$h='<details class="newsletter-pdf-attachments"><summary>PDF-Anlagen · '.count($refs).'</summary>';
        foreach($refs as$i=>$ref){if($i===6)$h.='<details><summary>Weitere '.(count($refs)-6).' Anlagen</summary>';$url='/edition-document?'.http_build_query(['item'=>$item['id'],'revision'=>$item['version'],'document'=>$ref['id']]);$h.='<p><a class="btn btn-outline theme-secondary" data-edition-document download href="'.Html::e($url).'">'.Html::e($ref['name']).'</a> · '.(int)ceil($ref['bytes']/1024).' KB</p>';}
        if(count($refs)>6)$h.='</details>';return$h.'</details>';
    }
}
