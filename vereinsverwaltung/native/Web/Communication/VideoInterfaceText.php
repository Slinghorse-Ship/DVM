<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Language\{Catalog,VideoCatalogueMessages};
trait VideoInterfaceText
{
    private ?array $videoInterfaceMessages=null;
    protected function videoText(string $source,array $values=[]):string
    {
        $this->videoInterfaceMessages??=(new Catalog($this->db,$this->access))->messages();return VideoCatalogueMessages::text($source,$this->videoInterfaceMessages,$values);
    }
    protected function videoTime(string $utc):string
    {
        return '<time datetime="'.Html::e(str_replace(' ','T',$utc).'Z').'">'.Html::e($this->mediaTime($utc)).'</time>';
    }
}
