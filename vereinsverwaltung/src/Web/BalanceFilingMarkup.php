<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DOMDocument;
use DOMElement;
use DOMXPath;
use DomainException;
use PDO;
use Kulturhus\Verein\{BalanceFilingCommands,BalanceFilingActions};

/** Adds personal complete-form metadata to the existing local forms; never to a certificate form. */
final class BalanceFilingMarkup
{
    private static function hidden(DOMDocument $dom,string $name,string $value):DOMElement
    {
        $node=$dom->createElement('input');$node->setAttribute('type','hidden');$node->setAttribute('name',$name);$node->setAttribute('value',$value);return $node;
    }
    private static function restore(DOMElement $node,mixed $value):void
    {
        if(!is_string($value))return;
        if($node->tagName==='textarea'){$node->textContent=$value;return;}
        if($node->tagName==='select'){
            $found=false;foreach($node->getElementsByTagName('option')as $option){$option->removeAttribute('selected');if($option->getAttribute('value')===$value){$option->setAttribute('selected','');$found=true;}}
            if(!$found){$option=$node->ownerDocument->createElement('option');$option->setAttribute('value',$value);$option->setAttribute('selected','');$option->textContent=$value;$node->insertBefore($option,$node->firstChild);}return;
        }
        if(in_array($node->getAttribute('type'),['checkbox','radio'],true)){
            $node->removeAttribute('checked');if($node->getAttribute('value')===$value)$node->setAttribute('checked','');return;
        }
        $node->setAttribute('value',$value);
    }
    public static function protect(PDO $db,string $actor,array $query,array $post,string $html,array &$session,?array $state=null,array $messages=[]):string
    {
        $text=static fn(string $source):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($source,$messages);
        $html=BalanceFilingSpecialists::render($html,$query,$post,$text);
        $route=BalanceFilingForm::source($query);$service=new BalanceFilingCommands($db,$actor);$cache=[];$posted=BalanceFilingForm::requested($post);$currentFields=$state['fields']??$post;
        return preg_replace_callback('~<form\b[^>]*>[\s\S]*?</form>~i',static function(array $match)use($db,$actor,$route,$query,$post,$posted,$currentFields,$service,$state,&$cache,&$session):string{
            $dom=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
            try{$dom->loadHTML('<?xml encoding="UTF-8"><div>'.$match[0].'</div>',LIBXML_NONET);$xp=new DOMXPath($dom);$form=$xp->query('//form')->item(0);if(!$form instanceof DOMElement)return $match[0];
                $main=$form->getAttribute('id')==='balance-local-main';$action=$xp->query('.//input[@name="action"]',$form)->item(0)?->getAttribute('value');
                if(!$main&&!in_array($action,BalanceFilingActions::ACTIONS,true))return $match[0];
                if(!$main&&$action==='tax-filing-validate')return $match[0];
                $controls=$xp->query('.//input[@name]|.//select[@name]|.//textarea[@name]',$form);
                $hidden=[];$required=[];$optional=[];$bound=[];$initial=[];$special=$form->hasAttribute('data-balance-specialist-form');
                $checks=[];$fallbacks=[];
                foreach($controls as$node)if(!$node->hasAttribute('disabled')&&!$xp->query('ancestor::fieldset[@disabled]',$node)->length&&in_array($node->getAttribute('type'),['checkbox','radio'],true))$checks[$node->getAttribute('name')]=true;
                foreach($controls as$node)if(!$node->hasAttribute('disabled')&&!$xp->query('ancestor::fieldset[@disabled]',$node)->length&&$node->getAttribute('type')==='hidden'&&$node->getAttribute('value')==='0'&&isset($checks[$node->getAttribute('name')]))$fallbacks[$node->getAttribute('name')]=true;
                foreach($controls as $node){
                    if($node->hasAttribute('disabled')||$xp->query('ancestor::fieldset[@disabled]',$node)->length)continue;
                    $name=$node->getAttribute('name');$type=$node->getAttribute('type');if(in_array($name,['csrf','action'],true))continue;
                    if(in_array($type,['checkbox','radio'],true))$optional[]=$name;else $required[]=$name;
                    if($special&&$type==='hidden'&&isset($fallbacks[$name]))$initial[$name]='0';
                    if($special&&$type!=='hidden'&&$name!=='balance_local_discard_confirmed'){
                        if(in_array($type,['checkbox','radio'],true)){if($node->hasAttribute('checked'))$initial[$name]=$node->getAttribute('value');elseif(!array_key_exists($name,$initial))$initial[$name]=null;}
                        elseif($node->tagName==='textarea')$initial[$name]=$node->textContent;
                        elseif($node->tagName==='select'){$choices=$xp->query('.//option[@selected]',$node);if(!$choices->length)$choices=$xp->query('.//option[1]',$node);$initial[$name]=$choices->item(0)?->getAttribute('value')??'';}
                        else $initial[$name]=$node->getAttribute('value');
                    }

                    if($type==='hidden'){$hidden[$name]=$node->getAttribute('value');if(!in_array($name,['filing','version','tax_year','period_id'],true)&&!isset($fallbacks[$name]))$bound[$name]=$node->getAttribute('value');}
                }
                $id=$hidden['filing']??($query['filing']??'');$period=$hidden['period_id']??($query['period_id']??'');
                $year=$hidden['tax_year']??($query['tax_year']??$query['balance_year']??(string)((int)date('Y')-1));
                if($period!==''&&!isset($hidden['tax_year']))$year=(string)(new \Kulturhus\Verein\BalancePeriodLedger($db,$actor))->report($period)['year'];
                if(!preg_match('/\A[0-9]{4}\z/D',$year))throw new DomainException('Ursprüngliches E-Bilanz-Jahr vollständig prüfen.',422);
                $cacheKey=$year.':'.$id.':'.$period;
                $workspace=$cache[$cacheKey]??=$service->workspace((int)$year,$id===''?null:$id,$period===''?null:$period);
                $context=$workspace['context'];
                // Derive identity and version from the current source, never from another form's failed POST.
                $identity=['filing'=>$id,'version'=>(string)($context['filing']['version']??0),'tax_year'=>$year,'period_id'=>$context['period_id']??''];
                foreach($identity as $name=>$value){
                    $node=$xp->query('.//input[@name="'.$name.'"]',$form)->item(0);
                    if($node)$node->setAttribute('value',$value);else{$form->insertBefore(self::hidden($dom,$name,$value),$form->firstChild);$required[]=$name;}
                    $bound[$name]=$value;
                }
                if($main)$optional[]='reviewed';
                $actions=$main?BalanceFilingForm::MAIN_ACTIONS:[$action,'balance-local-rebase',...($special?['balance-local-specialist-back','balance-local-specialist-discard']:[])];$marker=hash('sha256',BalanceFilingCommands::json([$main?'main':$action,array_diff_key($bound,array_flip(['version']))]));
                $selected=$posted&&is_string($currentFields['balance_local_token']??null)&&isset($session['balance_local_contexts'][$currentFields['balance_local_token']])&&$session['balance_local_contexts'][$currentFields['balance_local_token']]['marker']===$marker;
                if($selected){
                    $record=$session['balance_local_contexts'][$currentFields['balance_local_token']];
                    if($record['route']!==$route)throw new DomainException('Ursprünglicher E-Bilanz-Bereich passt nicht.',409);
                    $issued=['balance_local_token'=>$currentFields['balance_local_token'],'balance_local_key'=>$currentFields['balance_local_key']];
                    if($special&&($state['compared']??false)){$session['balance_local_contexts'][$currentFields['balance_local_token']]['initial_fields']=array_keys($initial);$session['balance_local_contexts'][$currentFields['balance_local_token']]['initial_sha256']=BalanceFilingForm::navigationHash($initial);}

                    foreach($xp->query('.//input[@name]|.//select[@name]|.//textarea[@name]',$form)as $node){
                        $name=$node->getAttribute('name');if(in_array($name,['csrf','action'],true)||($node->getAttribute('type')==='hidden'&&isset($fallbacks[$name])))continue;
                        $value=BalanceFilingForm::value($currentFields,$name);
                        if($value===null&&in_array($node->getAttribute('type'),['checkbox','radio'],true))$node->removeAttribute('checked');else self::restore($node,$value);
                    }
                    $keyNode=$xp->query('.//input[@name="version"]',$form)->item(0);if($keyNode)$keyNode->setAttribute('value',(string)($record['context']['filing']['version']??0));
                }else $issued=BalanceFilingForm::issue($workspace,$route,array_values(array_unique($required)),array_values(array_unique($optional)),$bound,$actions,$marker,$session,$special?$initial:null);
                // A clicked external rebase button precedes the form in document order.
                // Keep exactly one successful action instead of a later hidden action overriding it.
                if($special){
                    foreach($xp->query('.//button',$form)as$button)if((!$button->hasAttribute('type')||$button->getAttribute('type')==='submit')&&!$button->hasAttribute('name')){$button->setAttribute('name','action');$button->setAttribute('value',$action);}
                    foreach(iterator_to_array($xp->query('.//input[@name="action"]',$form))as$hiddenAction)$hiddenAction->parentNode->removeChild($hiddenAction);
                }
                foreach($issued as $name=>$value)$form->insertBefore(self::hidden($dom,$name,$value),$form->firstChild);
                $form->appendChild(self::hidden($dom,BalanceFilingForm::COMPLETE,'1'));
                $form->setAttribute('data-balance-local-form','');if($selected&&!$main)$form->setAttribute('id','balance-local-command');
                foreach($xp->query('.//button',$form)as $button)if(!$button->hasAttribute('type')||$button->getAttribute('type')==='submit')$button->setAttribute('formnovalidate','');
                return $dom->saveHTML($form);
            }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        },$html)??throw new DomainException('E-Bilanz-Formulare derzeit nicht lesbar.',503);
    }
}
