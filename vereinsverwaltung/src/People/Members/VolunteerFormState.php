<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Prepare the existing private form before headers, retaining the complete attempted input. */
final class VolunteerFormState
{
    public static function prepare(\PDO $db, string $actor, array $query, array $post, array $session): array
    {
        $state = ['service'=>null,'kind'=>'hygiene','board'=>false,'person'=>'','prefill'=>null,
            'values'=>[],'draft'=>null,'previous_draft'=>null,'receipt'=>null,'error'=>'','picker_error'=>'',
            'draft_error'=>'','picker'=>null,'query'=>'','page'=>'1','http_status'=>0];
        $action = is_string($post['action'] ?? null) ? $post['action'] : '';
        $isPost = in_array($action, ['volunteer-preview','volunteer-save'], true);
        $kind = array_key_exists('volunteer_kind', $post) ? $post['volunteer_kind'] : ($query['volunteer_kind'] ?? 'hygiene');
        if (!is_string($kind) || !in_array($kind, ['hygiene','general'], true)) {
            $state['error']='Art der Ehrenamtsbestätigung prüfen.'; $state['http_status']=422; return $state;
        }
        $state['kind']=$kind;
        $service=$state['service']=new VolunteerForm($db,$actor,$kind);
        try {
            $state['board']=$service->canChoosePerson();
            $requested=$isPost ? ($post['contact'] ?? '') : ($query['person'] ?? '');
            if (!is_string($requested)) throw new DomainException('Bitte eine verfügbare Person auswählen.',422);
            $state['person']=$requested;
            if (!$state['board'] || $requested !== '') {
                $state['prefill']=$service->prefill($requested);
                $state['person']=$state['prefill']['person']['id'];
                $state['values']=$state['prefill']['values'];
            }
        } catch (DomainException $failure) {
            $state['error']=$failure->getMessage();
            $state['http_status']=$failure->getCode()===503?503:($failure->getCode()===422?422:403);
        } catch (\Throwable) {
            $state['error']='Bestätigung konnte nicht geladen werden.'; $state['http_status']=500;
        }
        if ($state['board']) {
            $search=array_key_exists('volunteer_person_query',$query)?$query['volunteer_person_query']:'';
            $page=array_key_exists('volunteer_person_page',$query)?$query['volunteer_person_page']:'1';
            $state['query']=is_string($search)&&preg_match('//u',$search)===1?$search:'';
            $state['page']=is_scalar($page)?(string)$page:'';
            try { $state['picker']=$service->peoplePage(['query'=>$search,'page'=>$page]); }
            catch (DomainException $failure) {
                $state['picker_error']=$failure->getMessage();
                $state['http_status']=max($state['http_status'],$failure->getCode()===503?503:422);
            } catch (\Throwable) {
                $state['picker_error']='Personenauswahl konnte nicht geladen werden.'; $state['http_status']=500;
            }
        }
        $draft=$session['volunteer_preview']??null;
        if (is_array($draft) && ($draft['actor']??'')===$actor &&
            ($draft['contact']??'')===$state['person'] && ($draft['kind']??'hygiene')===$kind &&
            is_array($draft['input']??null) && is_string($draft['request_key']??null)) {
            $state['previous_draft']=$draft;
            $state['values']=$draft['input'];
            try {
                if (($draft['expires']??0)<time()) throw new DomainException('Vorschau abgelaufen. Bitte erneut erstellen.');
                if (!is_string($draft['bytes']??null) || !is_string($draft['pdf_sha256']??null) || !hash_equals($draft['pdf_sha256'],hash('sha256',$draft['bytes']))) throw new DomainException('Vorschau nicht mehr verfügbar. Bitte erneut erstellen.');
                if (!is_string($draft['hash']??null) || !hash_equals($draft['hash'],VolunteerForm::digest($service->build($state['person'],$draft['input'],(bool)$draft['signed'])))) {
                    throw new DomainException('Angaben oder Unterschrift wurden geändert. Bitte die vollständige Vorschau erneut öffnen.');
                }
                $state['draft']=$draft;
            } catch (DomainException $failure) { $state['draft_error']=$failure->getMessage(); }
        }
        if ($action==='volunteer-preview') {
            $state['values']=[];
            foreach ($service->fields() as $field) $state['values'][$field]=is_string($post[$field]??null)?$post[$field]:'';
        }
        if (array_key_exists('volunteer_receipt',$query)) {
            try {
                if (!is_string($query['volunteer_receipt'])) throw new DomainException('Anlegebestätigung nicht verfügbar.',404);
                $state['receipt']=$service->confirmation($query['volunteer_receipt']);
                $state['draft']=null;
            } catch (DomainException $failure) {
                $state['error']=$failure->getMessage(); $state['http_status']=$failure->getCode()===503?503:404;
            } catch (\Throwable) { $state['error']='Bestätigung konnte nicht geladen werden.'; $state['http_status']=500; }
        }
        return $state;
    }
}
