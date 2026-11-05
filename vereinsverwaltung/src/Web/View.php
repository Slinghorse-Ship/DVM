<?php declare(strict_types=1);
require_once dirname(__DIR__, 1).'/Infrastructure/Runtime/LocalRuntime.php';
require dirname(__DIR__, 1).'/Documents/DocumentView.php';
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function input(array $data,string $key,string $default=''):string{return isset($data[$key])&&is_scalar($data[$key])?(string)$data[$key]:$default;}
function arrayInput(array $data,string $key):array{return isset($data[$key])&&is_array($data[$key])?array_values(array_filter($data[$key],'is_string')):[];}
function scalarFields(array $data,array $keys):array{$out=[];foreach($keys as $key)if(isset($data[$key])&&is_scalar($data[$key]))$out[$key]=(string)$data[$key];return $out;}
require_once dirname(__DIR__, 1).'/Communication/EditorialModules.php';
function url(array $params=[]):string{$params=\Kulturhus\Verein\EditorialModules::route($params);return \Kulturhus\Verein\LocalRuntime::basePath().($params?'?'.http_build_query($params):'');}
function redirectTo(array $params):never{if(class_exists(\Kulturhus\Verein\LiveUploads::class,false))\Kulturhus\Verein\LiveUploads::finish();header('Location: '.url($params),true,303);exit;}
function bankListContext(array $source):array{return ['view'=>'finance','tab'=>'bank']+scalarFields($source,['account','cash_page','bank_page','bank_q','assignment','bank_from','bank_to','bank_direction','bank_amount','from','to','import_record','import_page','import_q','import_state','import_from','import_to','import_mapping','import_transaction']);}
function bankTransactionContext(string $id,array $source):array{$context=bankListContext($source)+['transaction'=>$id]+scalarFields($source,['match_page','match_q','own_page','own_q','own_account','excess_page','excess_q','excess_history_page'])+\Kulturhus\Verein\Web\InvoiceReceiptReturn::fields($source);if(($source['transaction']??null)===$id)$context+=scalarFields($source,['match_draft']);return $context;}
function bankImportContext(array $source):array{return bankListContext($source)+['bank_import'=>'1'];}
function receiptPaymentContext(string $id,array $source,bool $open=true):array{$context=['view'=>'finance','tab'=>'receipts','entry'=>$id]+($open?['payment_match'=>'1']:[])+scalarFields($source,['kind','status','from','to','q','account','direction','sort','order','per_page','page','bank_page','bank_q','assignment','bank_from','bank_to','bank_direction','bank_amount','payment_match','cash_page','match_q','match_page','match_account','candidate','import_record','import_page','import_q','import_state','import_from','import_to','import_mapping','import_transaction'])+\Kulturhus\Verein\Web\InvoiceReceiptReturn::fields($source);if(($source['entry']??null)===$id)$context+=scalarFields($source,['match_draft']);return $context;}
function csrf():string{return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function hidden(string $name,mixed $value):string{return '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';}
function dateDe(?string $v):string{return $v?implode('.',array_reverse(explode('-',$v))):'–';}
function localDateTime(?string $utc):string{return $utc?(new DateTimeImmutable($utc))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i'):'';}
function timeDe(string $utc):string{return (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i');}
function euro(int $cents):string{$sign=$cents<0?'−':'';$cents=abs($cents);return $sign.number_format(intdiv($cents,100),0,',','.').','.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT).' €';}
function initials(string $name):string{$parts=preg_split('/\s+/u',trim($name));return strtoupper(substr($parts[0]??'K',0,1).substr($parts[1]??'',0,1));}
function icon(string $name):string{
 $paths=[
 'external'=>'<path d="M14 3h7v7m0-7L10 14M10 3H3v18h18v-7"/>',
 'globe'=>'<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
 'star'=>'<path d="m12 3 2.8 5.7 6.3.9-4.6 4.5 1.1 6.3-5.6-3-5.6 3 1.1-6.3L2.9 9.6l6.3-.9z"/>',
 'home'=>'<path d="m3 10 9-7 9 7v10H3z"/><path d="M9 20v-7h6v7"/>',
 'users'=>'<circle cx="9" cy="7" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4M17 4a3 3 0 0 1 0 6m1 4a5 5 0 0 1 3 5v2"/>',
 'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6m10-6v6M3 11h18m-13 5h2m4 0h2"/>',
 'folder'=>'<path d="M3 20V4h7l2 3h9v13z"/>',
 'mail'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
 'chat'=>'<path d="M21 16H9l-6 5V4h18z"/><path d="M7 8h10M7 12h6"/>',
 'bank'=>'<path d="m2 8 10-5 10 5zM3 21h18M6 10v8m6-8v8m6-8v8"/>',
 'settings'=>'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/>',
 'search'=>'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
 'plus'=>'<path d="M12 4v16M4 12h16"/>',
 'arrow'=>'<path d="m10 5-7 7 7 7m-7-7h18"/>',
 'chevron'=>'<path d="m8 4 8 8-8 8"/>',
 'filter'=>'<path d="M3 5h18M6 12h12M9 19h6"/>',
 'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
 'columns'=>'<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M9 4v16m6-16v16"/>',
 'menu'=>'<path d="M3 6h18M3 12h18M3 18h18"/>',
 'check'=>'<path d="m4 12 5 5L20 6"/>',
 'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
 'refresh'=>'<path d="M20 11a8 8 0 1 0 2 5"/><path d="M20 4v7h-7"/>',
 'file'=>'<path d="M5 2h9l5 5v15H5zM14 2v6h5M8 13h8M8 17h6"/>',
 'chart'=>'<path d="M4 21V11h4v10m3 0V3h4v18m3 0V7h4v14"/>'
 ];return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['file']).'</svg>';
}

function paymentLabel(string $state):string{return ['open'=>'Offen','partial'=>'Teilbezahlt','paid'=>'Bezahlt','cancelled'=>'Verworfen','reversed'=>'Gegengebucht'][$state]??$state;}

function relativeDay(int $days):string{return match($days){0=>'Heute',1=>'Morgen',-1=>'Gestern',default=>$days<0?'vor '.abs($days).' Tagen':'in '.$days.' Tagen'};}
