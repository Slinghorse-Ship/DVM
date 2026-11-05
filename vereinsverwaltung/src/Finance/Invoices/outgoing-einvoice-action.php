<?php declare(strict_types=1);
/** Root dispatch performs normal POST/CSRF validation before invoking this action. */
function saveOutgoingEInvoice(PDO $db,string $actor,array $post):string
{
 if(!isset($post['xinvoice'])||!is_array($post['xinvoice'])||!is_string($post['entry_id']??null)||!is_string($post['entry_version']??null)||!ctype_digit($post['entry_version']))throw new DomainException('Rechnungsformular unvollständig.');
 require_once __DIR__.'/OutgoingEInvoice.php';return (new \Kulturhus\Verein\OutgoingEInvoice($db,$actor))->save($post['entry_id'],(int)$post['entry_version'],$post['xinvoice']);
}
