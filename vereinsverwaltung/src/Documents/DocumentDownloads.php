<?php declare(strict_types=1);
/** Archive exact server output before sending it; a failed archive stops the download. */
function documentDownload(\Kulturhus\Verein\Documents $documents,string $name,string $bytes,array $data,array $snapshot=[]):never
{
 $doc=$documents->generated($name,$bytes,$data,bin2hex(random_bytes(16)),$snapshot);
 $stored=$documents->file($doc);header('X-Kulturhus-Document-Id: '.$doc);
 header('Content-Type: '.$stored['mime'].(str_starts_with($stored['mime'],'text/')?'; charset=UTF-8':''));
 header('Content-Disposition: attachment; filename="kulturhus-dokument"; filename*=UTF-8\'\''.rawurlencode($stored['name']));echo $stored['bytes'];exit;
}
