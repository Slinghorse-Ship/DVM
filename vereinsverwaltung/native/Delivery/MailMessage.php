<?php declare(strict_types=1);
namespace Kulturhus\Native\Delivery;

use DomainException;
use Kulturhus\Native\Communication\Content;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;

/** Build and validate MIME before opening SMTP; usable by local transport tests. */
final class MailMessage
{
    /** SMTP may replace its transport ID with a queue ID; correlate the accepted MIME header. */
    public static function acceptedMessageId(?\Symfony\Component\Mailer\SentMessage $sent): string
    {
        $original=$sent?->getOriginalMessage();
        if(!$original instanceof \Symfony\Component\Mime\Message)throw new DomainException('Mailserver hat die Nachricht nicht bestätigt.');
        $header=$original->getHeaders()->get('Message-ID');
        if(!$header instanceof \Symfony\Component\Mime\Header\IdentificationHeader)throw new DomainException('Bestätigte Nachrichtenkennung fehlt.');
        return '<'.$header->getId().'>';
    }

    private static function subject(mixed $value):string
    {
        $text=Content::text($value,1280,true);
        if(mb_strlen($text,'UTF-8')>320||preg_match('/[\r\n]/',$value))throw new DomainException('Betreff mit höchstens 320 Zeichen ohne Zeilenumbruch eingeben.');
        return$text;
    }
    public static function compose(array $account, array $message): Email
    {
        $from=Address::create($account["from"]);
        if(array_key_exists("name",$account)){
            if(!is_string($account["name"])||preg_match('//u',$account["name"])!==1||preg_match('/[\x00-\x1f\x7f]/',$account["name"]))throw new DomainException("Absendername prüfen.");
            $from=new Address($from->getAddress(),$account["name"]);
        }
        $mail = (new Email())
            ->from($from)
            ->replyTo($account["reply_to"])
            ->to(Content::email($message["to"]))
            ->subject(self::subject($message["subject"]))
            ->html($message["html"])
            ->text($message["text"]);
        if (($message["logo_png"] ?? "") !== "") {
            $logo = base64_decode($message["logo_png"], true);
            $size = is_string($logo) ? @getimagesizefromstring($logo) : false;
            if (
                !$size ||
                strlen($logo) > 500000 ||
                ($size["mime"] ?? "") !== "image/png" ||
                $size[0] > 320 ||
                $size[1] > 320
            ) {
                throw new DomainException(
                    "Gespeichertes Vereinslogo nicht gültig.",
                );
            }
            $mail->addPart(
                (new DataPart($logo, "Vereinslogo.png", "image/png"))
                    ->asInline()
                    ->setContentId("newsletter-logo@kulturhus"),
            );
        }
        $attachments = $message["attachments"] ?? [];
        if(array_key_exists('document_attachments',$message))$attachments=[...$attachments,...\Kulturhus\Native\Communication\DocumentAttachments::open($message['document_attachments'])];
        $inline=$message['inline_images']??[];$inlineBytes=0;
        if(!is_array($inline)||!array_is_list($inline)||count($inline)>64)throw new DomainException('Pressebilder prüfen.');
        foreach($inline as$image){
            if(!is_array($image)||!is_string($image['base64']??null)||!is_string($image['name']??null)||!preg_match('/\APressebild-[a-f0-9]{32}\.(?:jpg|png|webp)\z/D',$image['name'])||!in_array($image['mime']??null,['image/jpeg','image/png','image/webp'],true)||!preg_match('/\Apress-[a-f0-9]{32}@dvm\.local\z/D',$image['cid']??'')||!preg_match('/\A[a-f0-9]{64}\z/D',$image['sha256']??'')||strlen($image['base64'])>14000000)throw new DomainException('Gespeichertes Pressebild prüfen.');
            $raw=base64_decode($image['base64'],true);$size=is_string($raw)?@getimagesizefromstring($raw):false;
            $inlineBytes+=is_string($raw)?strlen($raw):0;
            if(!$size||($size['mime']??null)!==$image['mime']||!hash_equals($image['sha256'],hash('sha256',$raw))||$inlineBytes>10000000)throw new DomainException('Ursprüngliche Pressebilder sind beschädigt oder zu groß.');
            $mail->addPart((new DataPart($raw,$image['name'],$image['mime']))->asInline()->setContentId($image['cid']));
        }
        $total = 0;
        if (!is_array($attachments) || count($attachments) > 10) {
            throw new DomainException("Höchstens zehn Mailanlagen erlaubt.");
        }
        foreach ($attachments as $attachment) {
            if (
                !is_array($attachment) ||
                !is_string($attachment["bytes"] ?? null) ||
                !is_string($attachment["name"] ?? null) ||
                !is_string($attachment["mime"] ?? null) ||
                strlen($attachment["name"]) > 200 ||
                $attachment["name"] === "" ||
                preg_match('~[\x00-\x1f/\\\\]~', $attachment["name"]) ||
                !preg_match(
                    "~\A[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+\z~D",
                    $attachment["mime"],
                )
            ) {
                throw new DomainException(
                    "Mailanlage muss gespeicherte Bytes, Dateiname und MIME-Typ enthalten.",
                );
            }
            $total += strlen($attachment["bytes"]);
            if ($total > 20000000) {
                throw new DomainException("Mailanlagen überschreiten 20 MB.");
            }
            $mail->addPart(
                new DataPart(
                    $attachment["bytes"],
                    $attachment["name"],
                    $attachment["mime"],
                ),
            );
        }
        foreach ($message["headers"] ?? [] as $name => $value) {
            if (
                !in_array(
                    $name,
                    ["List-Unsubscribe", "List-Unsubscribe-Post"],
                    true,
                ) ||
                preg_match('/[\r\n]/', $value)
            ) {
                throw new DomainException("Ungültiger Mailheader.");
            }
            $mail->getHeaders()->addTextHeader($name, $value);
        }
        return $mail;
    }
}
