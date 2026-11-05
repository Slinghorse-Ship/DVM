<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use PDO;
use Kulturhus\Native\Shared\{Access, SecretBox};

/** Shared authenticated form context; business rules remain in module services. */
abstract class Workspace
{
    public function __construct(
        protected PDO $db,
        protected Access $access,
        protected string $mediaRoot,
        protected string $csrf,
    ) {}

    private ?array $mediaInterfaceMessages=null;
    protected function mediaText(string $source):string
    {
        $this->mediaInterfaceMessages??=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();
        return \Kulturhus\Native\Language\MediaMessages::text($source,$this->mediaInterfaceMessages);
    }
    protected function mediaFormat(string $source,array $values):string
    {
        $arguments=[];foreach($values as$key=>$value)$arguments['{'.$key.'}']=(string)$value;
        return strtr($this->mediaText($source),$arguments);
    }
    protected function mediaNumber(int|float $number,int $decimals=0):string
    {
        $english=explode('-', (new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->locale(),2)[0]==='en';
        return number_format($number,$decimals,$english?'.':',',$english?',':'.');
    }
    protected function mediaTime(string $utc):string
    {
        $english=explode('-', (new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->locale(),2)[0]==='en';
        return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format($english?'Y-m-d H:i':'d.m.Y H:i').' Europe/Berlin';
    }
    protected function mediaOptions(array $values):array
    {
        return array_map($this->mediaText(...),$values);
    }

    protected function start(string $operation): string
    {
        return '<form method="post">' .
            Html::hidden("csrf", $this->csrf) .
            Html::hidden("operation", $operation);
    }

    protected function validatePost(array $post): void
    {
        if (!is_string($post["csrf"]??null) || !hash_equals($this->csrf, $post["csrf"])) {
            throw new \DomainException(
                "Formular abgelaufen. Bitte Seite neu laden.",
            );
        }
    }

    protected function can(string $module, string $capability): bool
    {
        try {
            $this->access->require($module, $capability);
            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    protected function box(): SecretBox
    {
        return SecretBox::fromFile(
            getenv("KH_NATIVE_PRIVATE_KEY_FILE") ?:
            dirname(__DIR__, 3) . "/.local/native-cms-integration/private.key",
        );
    }

    protected function localTime(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat(
            "!Y-m-d\TH:i",
            $value,
            new \DateTimeZone("Europe/Berlin"),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$date ||
            $date->format("Y-m-d\TH:i") !== $value ||
            ($errors !== false &&
                ($errors["warning_count"] || $errors["error_count"]))
        ) {
            throw new \DomainException(
                "Gültigen Zeitpunkt wählen; die lokale Uhrzeit muss existieren.",
            );
        }
        return $date->setTimezone(new \DateTimeZone("UTC"))->format(DATE_ATOM);
    }

    protected function displayTime(string $utc): string
    {
        return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i') . ' Uhr';
    }
}
