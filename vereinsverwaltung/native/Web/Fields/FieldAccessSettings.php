<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Fields;
use InvalidArgumentException;
use Kulturhus\Native\Cms\FieldPolicy;
use Kulturhus\Native\Web\Html;

/** Ordinary optional controls within the manage-only content-model form. */
final class FieldAccessSettings
{
    public static function render(array $field,?callable $interfaceText=null):string
    {
        $t=$interfaceText??static fn(string $source):string=>$source;
        $rules=array_replace(FieldPolicy::DEFAULTS,FieldPolicy::normalize($field['access']??[]));
        $levels=['read'=>$t('Website-Leseberechtigung'),'edit'=>$t('Website-Redaktion'),'publish'=>$t('Website-Veröffentlichung'),'manage'=>$t('Website-Administration')];
        return Html::hidden('field_access_present','1').('<details class="field-access-settings"><summary>' . Html::e($t('Zugriff auf dieses Feld')) . '</summary>').Html::select('field_access_read',$t('Lesen (beschränkt auch öffentliche Anzeige)'),$levels,$rules['read']).Html::select('field_access_edit',$t('Bearbeiten'),array_diff_key($levels,['read'=>true]),$rules['edit']).'</details>';
    }
    public static function submitted(array $post):array
    {
        if(!array_key_exists('field_access_present',$post))return [];
        if($post['field_access_present']!=='1')throw new InvalidArgumentException('Feldrechte ausdrücklich bestätigen.');
        $rules=FieldPolicy::normalize(['read'=>$post['field_access_read']??'read','edit'=>$post['field_access_edit']??'edit']);
        return ['access'=>$rules===FieldPolicy::DEFAULTS?[]:$rules];
    }
}
