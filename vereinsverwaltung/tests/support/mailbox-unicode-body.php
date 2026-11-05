<?php declare(strict_types=1);
/** Synthetic wire messages only: no database, network or actual mail delivery. */
use Kulturhus\Native\Communication\Mail\{Mime,HtmlDocument,ReplyText};
use Kulturhus\Native\Shared\RichText;
use Kulturhus\Verein\Communication\{MailboxHtml,MailboxTransport};

$unicodeSample='äöü ÄÖÜ ß ẞ € 🎶';
$unicodeExpected=$unicodeSample.' | ß | &szlig; | ß | ß | &#223;';
$unicodeBody='<p id="unicode" title="Straße Grüße">'.$unicodeSample.' | &szlig; | &amp;szlig; | &#223; | &#xDF; | &amp;#223;</p>';
$unicodeDocument=static function(string $html):DOMDocument{$dom=new DOMDocument();$old=libxml_use_internal_errors(true);try{$dom->loadHTML($html,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}return$dom;};
$unicodeWire=static fn(string $body,string $charset,string $transfer):string=>"From: Sender <sender@example.invalid>\r\nTo: club@example.invalid\r\nSubject: =?UTF-8?B?".base64_encode('Grüße Straße ß ẞ € 🎶')."?=\r\nMessage-ID: <unicode-source@example.invalid>\r\nIn-Reply-To: <unicode-parent@example.invalid>\r\nContent-Type: text/html; charset=".$charset."\r\nContent-Transfer-Encoding: ".$transfer."\r\n\r\n".match($transfer){'quoted-printable'=>quoted_printable_encode($body),'base64'=>chunk_split(base64_encode($body),76,"\r\n"),default=>$body};
foreach(['UTF-8','Windows-1252','ISO-8859-1','ISO-8859-15']as$unicodeCharset)foreach(['fragment','charset','http-equiv','xml','bom']as$unicodeMeta)foreach(['8bit','quoted-printable','base64']as$unicodeTransfer){
    $unicodeHtml=$unicodeMeta==='fragment'?$unicodeBody:'<!doctype html><html><head>'.($unicodeMeta==='charset'?'<meta charset="'.$unicodeCharset.'">':'<meta http-equiv="Content-Type" content="text/html; charset='.$unicodeCharset.'">').'<style>p{color:#123456}/* Straße */</style></head><body style="background-color:#abcdef" bgcolor="#c0ffee">'.$unicodeBody.'</body></html>';
    if($unicodeMeta==='xml')$unicodeHtml='<?xml version="1.0" encoding="'.$unicodeCharset.'"?>'.$unicodeHtml;
    // HTML references retain characters which cannot be represented by the declared legacy charset.
    if($unicodeCharset!=='UTF-8')$unicodeHtml=str_replace(['ẞ','🎶'],['&#7838;','&#127926;'],$unicodeHtml);
    if($unicodeCharset==='ISO-8859-1')$unicodeHtml=str_replace('€','&#8364;',$unicodeHtml);
    $unicodeBytes=mb_convert_encoding($unicodeHtml,$unicodeCharset,'UTF-8');
    if($unicodeMeta==='bom'&&$unicodeCharset==='UTF-8')$unicodeBytes="\xef\xbb\xbf".$unicodeBytes;
    $unicodeRaw=$unicodeWire($unicodeBytes,$unicodeCharset,$unicodeTransfer);$unicodeHash=hash('sha256',$unicodeRaw);
    $unicodeShown=MailboxHtml::document($unicodeRaw);$unicodeDom=$unicodeDocument($unicodeShown);$unicodeXpath=new DOMXPath($unicodeDom);$unicodeLabel=$unicodeCharset.'/'.$unicodeMeta.'/'.$unicodeTransfer;
    $check($unicodeXpath->query('//*[@id="unicode"]')->item(0)?->textContent===$unicodeExpected&&$unicodeXpath->query('//*[@id="unicode"]')->item(0)?->getAttribute('title')==='Straße Grüße','received HTML keeps Unicode and decodes named/numeric entities exactly once '.$unicodeLabel);
    $unicodeEnvelope=Mime::mailbox($unicodeRaw);
    $check($unicodeEnvelope['readable']&&str_contains($unicodeEnvelope['text'],$unicodeExpected)&&$unicodeEnvelope['subject']==='Grüße Straße ß ẞ € 🎶'&&hash('sha256',$unicodeRaw)===$unicodeHash,'MIME excerpt subject and immutable original stay consistent '.$unicodeLabel);
    if($unicodeMeta!=='fragment')$check(str_contains($unicodeXpath->query('//head/style')->item(1)?->textContent??'','Straße')&&str_contains($unicodeDom->getElementsByTagName('body')->item(0)->getAttribute('style'),'#abcdef')&&str_contains($unicodeDom->getElementsByTagName('body')->item(0)->getAttribute('style'),'#c0ffee'),'UTF-8 parser retains original head styles and body attributes '.$unicodeLabel);
}
$unicodeFragment='<meta charset="windows-1252">'.$unicodeBody.'<blockquote><p>Old quoted Straße</p></blockquote>';
$unicodeReplyRaw=$unicodeWire($unicodeFragment,'UTF-8','quoted-printable');$unicodeParsedReply=Mime::parse($unicodeReplyRaw);
$check(RichText::plain(ReplyText::withoutHtmlQuotes($unicodeFragment))===$unicodeExpected&&trim($unicodeParsedReply['reply_text'])===$unicodeExpected,'received HTML reply extraction keeps Unicode and entities while removing only the quoted tail');
$check(RichText::plain(MailboxHtml::reply('<meta charset="windows-1252">'.$unicodeBody))===$unicodeExpected,'formatted reply sanitizer retains the same intended Unicode and literal entities');

$unicodeHostile='<!doctype html><!-- literal <meta charset="iso-8859-1"> --><html><head><meta charset="windows-1252"><meta http-equiv="refresh" content="0;url=https://tracking.example.invalid"><style>p{color:#123456;background:url(https://tracking.example.invalid/x)}</style><script>const text="<meta charset=windows-1252>";window.unicodeInjected=true;</script></head><body>'.$unicodeBody.'<img alt="Straße ß" src="https://tracking.example.invalid/pixel" onerror="window.unicodeInjected=true"><a href="java&#x73;cript:alert(1)" onclick="alert(1)">Unsafe</a><iframe src="https://tracking.example.invalid"></iframe><form><input name="secret"></form><svg onload="alert(1)"></svg></body></html>';
$unicodeSafe=$unicodeDocument(MailboxHtml::document($unicodeWire($unicodeHostile,'UTF-8','quoted-printable')));$unicodeSafeXpath=new DOMXPath($unicodeSafe);
$check($unicodeSafeXpath->query('//*[@id="unicode"]')->item(0)->textContent===$unicodeExpected&&$unicodeSafeXpath->query('//script|//iframe|//form|//input|//svg|//*[@onerror or @onclick or @onload]')->length===0&&$unicodeSafeXpath->query('//a[@href]')->length===0&&$unicodeSafeXpath->query('//img[@src]')->length===0,'legacy metas and script/comment text cannot override UTF-8 or reactivate mail content and remote images');
$check($unicodeSafeXpath->query('//meta')->length===2&&!str_contains($unicodeSafe->saveHTML(),'https://tracking.example.invalid'),'sanitization still removes refresh and tracking resources after UTF-8 parsing');

$unicodePng=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WlV7oYAAAAASUVORK5CYII=',true);
foreach(['raw','encoded','extended']as$unicodeNameKind){
    $unicodeFilename='Straße-ẞ-€-🎶.png';$unicodeName=match($unicodeNameKind){'raw'=>'filename="'.$unicodeFilename.'"','encoded'=>'filename="=?UTF-8?B?'.base64_encode($unicodeFilename).'?="','extended'=>"filename*=UTF-8''".rawurlencode($unicodeFilename)};
    $unicodeRelated="From: sender@example.invalid\r\nTo: club@example.invalid\r\nSubject: Image\r\nContent-Type: multipart/related; boundary=unicode-files\r\n\r\n--unicode-files\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<meta charset=windows-1252>".$unicodeBody.'<img src="cid:unicode-photo" alt="Straße ß ẞ € 🎶">'."\r\n--unicode-files\r\nContent-Type: image/png\r\nContent-Disposition: attachment; ".$unicodeName."\r\nContent-ID: <unicode-photo>\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode($unicodePng)."\r\n--unicode-files--\r\n";
    $unicodeFiles=Mime::attachments($unicodeRelated);$unicodeImageDom=$unicodeDocument(MailboxHtml::document($unicodeRelated));
    $check(count($unicodeFiles)===1&&$unicodeFiles[0]['name']===$unicodeFilename&&$unicodeFiles[0]['bytes']===$unicodePng&&$unicodeFiles[0]['embedded'],'incoming Unicode attachment name and exact image bytes survive '.$unicodeNameKind.' parameter encoding');
    $check($unicodeImageDom->getElementsByTagName('img')->item(0)->getAttribute('alt')==='Straße ß ẞ € 🎶'&&str_starts_with($unicodeImageDom->getElementsByTagName('img')->item(0)->getAttribute('src'),'data:image/png;base64,'),'CID image with legacy body meta retains Unicode alt and the existing private image route '.$unicodeNameKind);
}

$unicodeReplyHtml=MailboxHtml::reply($unicodeBody);$unicodeReplyText=RichText::plain($unicodeReplyHtml);
$unicodeOutgoing=MailboxTransport::compose(['from'=>'club@example.invalid','reply_to'=>'club@example.invalid','name'=>'Änne Straße ß ẞ € 🎶'],['to'=>'sender@example.invalid','subject'=>'Antwort äöü ÄÖÜ ß ẞ € 🎶','text'=>$unicodeReplyText,'html'=>$unicodeReplyHtml,'message_id'=>'<unicode-reply@example.invalid>','parent_id'=>'<unicode-source@example.invalid>','references'=>['<unicode-parent@example.invalid>'],'quote'=>['from'=>'Änne Straße ß ẞ € 🎶','subject'=>'Eingang äöü ÄÖÜ ß ẞ € 🎶','received_at'=>'2026-11-05 12:00:00','text'=>$unicodeReplyText]]);
$unicodeWireReply=$unicodeOutgoing->toString();$unicodeRoundtrip=Mime::mailbox($unicodeWireReply);$unicodeRoundtripHtml=$unicodeDocument(MailboxHtml::document($unicodeWireReply));
$check($unicodeRoundtrip['subject']==='Antwort äöü ÄÖÜ ß ẞ € 🎶'&&str_starts_with($unicodeRoundtrip['text'],$unicodeExpected)&&str_contains($unicodeRoundtrip['text'],'> '.$unicodeExpected),'actual serialized RFC reply keeps subject, authored text, quoted text and literal entities without delivery');
$check(str_contains($unicodeRoundtripHtml->getElementsByTagName('body')->item(0)->textContent,$unicodeExpected)&&$unicodeOutgoing->getHeaders()->get('In-Reply-To')->getIds()===['unicode-source@example.invalid'],'formatted RFC reply roundtrip preserves Unicode and its existing conversation identity');
