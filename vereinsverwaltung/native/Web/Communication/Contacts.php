<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\Workspace;

/** Contact-specific permissions and original form state stay in their own components. */
final class Contacts extends Workspace
{
    public function manager(string $module,?array $state=null): string
    {
        return match($module){
            'newsletter'=>(new NewsletterContacts($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($state),
            'press'=>(new PressContactManager($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($state),
            default=>throw new \DomainException('Unbekannter Kontaktbereich.'),
        };
    }
}
