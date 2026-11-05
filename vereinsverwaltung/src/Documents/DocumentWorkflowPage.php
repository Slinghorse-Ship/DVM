<?php declare(strict_types=1);

namespace Kulturhus\Verein;

use DomainException;
use Kulturhus\Verein\DocumentWorkflow\WorkflowStore;
use Kulturhus\Verein\DocumentWorkflow\WorkflowSignatures;

/** Thin HTTP-form adapter. Authentication, CSRF, redirects and downloads belong to the host. */
final class DocumentWorkflowPage
{
    public static function handle(WorkflowStore $store, array $post, array $files = [], ?WorkflowSignatures $signatures = null): array
    {
        $action = self::text($post, 'action');
        $id = self::text($post, 'wf_id');
        $versionText = self::text($post, 'wf_version', '0');
        if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $versionText)) {
            throw new DomainException('Die Formularversion ist ungültig. Bitte die Ansicht neu öffnen.');
        }
        $version = (int)$versionText;
        switch ($action) {
            case 'document-workflow-standard-template':
                $id=\Kulturhus\Verein\DocumentWorkflow\StandardTemplates::install($store,self::text($post,'wf_standard'));
                return ['wf_mode'=>'templates','wf_template'=>$id];
            case 'document-workflow-signature-request':
                if(!$signatures)throw new DomainException('Unterschriften sind nicht verfügbar.');
                $recipients=$post['wf_recipients']??[];
                if(!is_array($recipients))throw new DomainException('Mitglieder auswählen.');
                $signatures->request($id,$version,$recipients);
                return ['wf_doc'=>$id];
            case 'document-workflow-signature-sign':
                if(!$signatures)throw new DomainException('Unterschriften sind nicht verfügbar.');
                $post=\Kulturhus\Verein\Documents\WorkflowSignatureForm::prepare($post,$files);
                $signatures->sign(self::text($post,'wf_sign'),self::text($post,'wf_document_hash'),self::text($post,'wf_accepted')==='1',self::text($post,'wf_signature_png'));
                return ['wf_sign'=>self::text($post,'wf_sign')];
            case 'document-workflow-attachment-add':
                $file = $files['wf_pdf'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || !is_string($file['name'] ?? null)) throw new DomainException('Eine PDF-Datei bis 2 MB auswählen.');
                $size = filesize($file['tmp_name']);
                if ($size === false || $size > 2097152) throw new DomainException('Die PDF darf höchstens 2 MB groß sein.');
                $bytes = file_get_contents($file['tmp_name']);
                if ($bytes === false) throw new DomainException('Die PDF konnte nicht gelesen werden.');
                $store->addAttachment($id, $version, $file['name'], $bytes);
                return ['wf_doc' => $id];
            case 'document-workflow-attachment-remove':
                $store->removeAttachment($id, $version, self::text($post, 'wf_attachment'));
                return ['wf_doc' => $id];
            case 'document-workflow-folder-save':
                if(self::text($post,'wf_folder_complete')!=='1'||array_diff(['wf_name','wf_parent'],array_keys($post)))throw new DomainException('Ordnerformular nicht vollständig übertragen. Eingaben prüfen.');
                $name=self::text($post,'wf_name');$lineText=static fn(string $value):string=>str_replace(["\r\n","\r"],"\n",$value);
                $name=$lineText($name);
                if($id!==''){$originalName=$store->folder($id)['name'];if($lineText($originalName)===$name)$name=$originalName;}
                $id = $store->saveFolder($id, $version, $name, self::text($post, 'wf_parent'),$id===''?self::creationKey($post,['wf_name','wf_parent']):null);
                return ['wf_folder' => $id];
            case 'document-workflow-template-save':
                if(array_key_exists('wf_edit',$post))throw new DomainException('Feldaktionen zuerst als ungespeicherten Arbeitsstand öffnen.');
                $previous = $id !== '' ? $store->template($id) : null;
                $id = $store->saveTemplate($id, $version, \Kulturhus\Verein\Documents\WorkflowTemplateForm::data($post,$previous),$id===''?self::creationKey($post,['wf_title','wf_description']):null);
                return ['wf_mode' => 'templates', 'wf_template' => $id];
            case 'document-workflow-template-publish':
                $store->publishTemplate($id, $version);
                return ['wf_mode' => 'templates', 'wf_template' => $id];
            case 'document-workflow-document-create':
                $request=self::creationKey($post,['wf_title','wf_template','wf_folder']);
                $template=self::text($post,'wf_template');
                if(str_starts_with($template,'standard:'))$template=\Kulturhus\Verein\DocumentWorkflow\StandardTemplates::install($store,substr($template,9));
                $id = $store->createDocument($template, self::text($post, 'wf_folder'), self::text($post, 'wf_title'),$request);
                return ['wf_doc' => $id];
            case 'document-workflow-document-save':
                if(array_key_exists('wf_edit',$post))throw new DomainException('Registeraktionen zuerst als ungespeicherten Arbeitsstand öffnen.');
                $document=$store->document($id);$data=\Kulturhus\Verein\Documents\WorkflowDocumentForm::data($post,$document);
                try{$store->saveDocument($id,$version,$data['values'],$data['title'],$data['folder']);}
                catch(\Kulturhus\Verein\DocumentWorkflow\WorkflowException $failure){throw \Kulturhus\Verein\Documents\WorkflowFormError::from($failure,$document);}
                return ['wf_doc' => $id];
            case 'document-workflow-document-assign':
                $store->assignDocument($id, $version, self::text($post, 'wf_assignee'), self::text($post, 'wf_reviewer'));
                return ['wf_doc' => $id];
            case 'document-workflow-document-transition':
                $transition = self::text($post, 'wf_transition');
                if (!in_array($transition, ['submit', 'approve', 'return', 'reopen'], true)) throw new DomainException('Unbekannter Statuswechsel.');
                try{$store->transition($id, $version, $transition, self::text($post, 'wf_comment'));}
                catch(\Kulturhus\Verein\DocumentWorkflow\WorkflowException $failure){throw \Kulturhus\Verein\Documents\WorkflowFormError::from($failure,$store->document($id));}
                return ['wf_doc' => $id];
            default:
                throw new DomainException('Unbekannte Workflow-Aktion.');
        }
    }

    private static function creationKey(array $post,array $required):string
    {
        if(self::text($post,'wf_creation_complete')!=='1'||array_diff($required,array_keys($post)))throw new DomainException('Anlegeformular nicht vollständig übertragen. Eingaben prüfen.');
        $key=self::text($post,'wf_request');
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Anlegeformular erneut öffnen.');
        return $key;
    }

    private static function text(array $data, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $data)) return $default;
        if (!is_string($data[$key])) throw new DomainException('Ungültiges Formularfeld: '.$key.'.');
        return $data[$key];
    }

}
