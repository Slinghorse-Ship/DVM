<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;

/** Transport an explicitly chosen handwritten image; identity and evidence stay in WorkflowSignatures. */
final class WorkflowSignatureForm
{
    public static function prepare(array $post,array $files=[]):array
    {
        $method=self::text($post,'wf_signature_method');
        if($method===''&&!array_key_exists('wf_signature_complete',$post))return $post;
        if(self::text($post,'wf_signature_complete')!=='1'||!in_array($method,['draw','upload','retained'],true)||array_diff(['wf_sign','wf_document_hash','wf_signature_png'],array_keys($post)))throw new \DomainException('Unterschriftenformular nicht vollständig übertragen. Eingaben prüfen.');
        foreach(['wf_sign','wf_document_hash','wf_signature_png','wf_accepted']as$key)self::text($post,$key);
        $selectedFile=$files['wf_signature_file']??null;if($method!=='upload'&&is_array($selectedFile)&&($selectedFile['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)throw new \DomainException('Eine neue PNG-Datei ist ausgewählt. Zum Verwenden zuerst „Vorhandene handschriftliche PNG-Unterschrift verwenden“ auswählen.');
        if($method==='upload'){
            $file=$files['wf_signature_file']??null;
            if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new \DomainException('Eine handschriftliche Unterschrift als PNG bis16 KiB auswählen.');
            $size=filesize($file['tmp_name']);if($size===false||$size>16384)throw new \DomainException('Die Unterschrift darf höchstens16 KiB groß sein.');
            $bytes=file_get_contents($file['tmp_name']);if(!is_string($bytes))throw new \DomainException('Die Unterschrift konnte nicht gelesen werden.');
            $post['wf_signature_png']='data:image/png;base64,'.base64_encode($bytes);$post['wf_signature_method']='retained';
        }
        return $post;
    }
    /** Only this authenticated attempt for this exact current request may be shown again. */
    public static function retained(array $post,array $request):string
    {
        if(!$request['can_sign']||self::text($post,'wf_sign')!==$request['id']||!hash_equals($request['document_sha256'],self::text($post,'wf_document_hash')))return '';
        $image=self::text($post,'wf_signature_png');
        if(strlen($image)>22000||!preg_match('~\Adata:image/png;base64,([A-Za-z0-9+/=]+)\z~D',$image,$match))return '';
        $bytes=base64_decode($match[1],true);if(!is_string($bytes)||strlen($bytes)>16384)return '';
        $info=@getimagesizefromstring($bytes);
        if(!is_array($info)||($info[2]??null)!==IMAGETYPE_PNG||$info[0]<80||$info[0]>1600||$info[1]<30||$info[1]>500)return '';
        try{\Kulturhus\Verein\DocumentWorkflow\WorkflowPdf::render(['validate_signature'=>base64_encode($bytes)]);}catch(\DomainException){return '';}
        return $image;
    }
    private static function text(array $post,string $key):string
    {
        if(!array_key_exists($key,$post))return '';if(!is_string($post[$key])||preg_match('//u',$post[$key])!==1)throw new \DomainException('Unterschriftenformular prüfen.');return $post[$key];
    }
}
