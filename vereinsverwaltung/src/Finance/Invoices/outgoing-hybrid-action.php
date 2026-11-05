<?php declare(strict_types=1);
/** Root dispatch must perform its standard POST, login and CSRF checks first. */
function saveOutgoingHybridInvoice(PDO $db,string $actor,array $post):string
{
    if(!is_string($post['entry_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$post['entry_id'])||!is_string($post['entry_version']??null)||!ctype_digit($post['entry_version']))throw new DomainException('Die Rechnung bitte neu öffnen.');
    require_once __DIR__.'/OutgoingEInvoice.php';
    return (new \Kulturhus\Verein\OutgoingEInvoice($db,$actor))->saveHybrid($post['entry_id'],(int)$post['entry_version'],$post);
}
