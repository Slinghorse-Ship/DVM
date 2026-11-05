<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\Shared\RichText;
use Kulturhus\Native\Web\RichTextEditor;
use Kulturhus\Native\Communication\Content;

$checks = 0;
function check(bool $value,string $label): void {global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";}
function rejects(callable $operation,string $label): void {try{$operation();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('FAIL '.$label);}

$markup = '<h2>Kultur 🎶</h2><p>Grüße <strong>fett</strong> <em>kursiv</em> <u>Linie</u> <s>gestrichen</s> ❤️</p><blockquote>Zitat</blockquote><ul><li>Eins</li></ul><ol><li>Zwei</li></ol>';
$safe = RichText::sanitize($markup);
check(str_contains($safe,'🎶') && str_contains($safe,'Grüße') && str_contains($safe,'❤️'),'Unicode and emoji preserved');
foreach (['h2','strong','em','u','s','blockquote','ul','ol','li'] as $tag) check(str_contains($safe,'<'.$tag.'>'),'format preserved '.$tag);
check(RichText::sanitize($safe) === $safe,'canonical markup idempotent');
check(RichText::sanitize('<b>Fett</b><i>Kursiv</i><strike>Alt</strike>') === '<strong>Fett</strong><em>Kursiv</em><s>Alt</s>','browser formatting canonicalized');
check(str_contains(RichText::sanitize('<font size="5" color="#ABC">Groß</font>'),'class="kh-rt-size-xlarge" style="color:#aabbcc"'),'native browser font size and color canonicalized');
$nested = RichText::sanitize('<div><div>Ein Absatz</div><p>Zweiter Absatz</p></div>');
check($nested === RichText::sanitize($nested),'nested browser div wrappers remain idempotent');
$colored = RichText::sanitize('<p style="color:rgb(12, 34, 255);background-color:#AbC;text-align:center;font-family:evil;position:fixed">Farbe</p>');
check(str_contains($colored,'style="background-color:#aabbcc;color:#0c22ff"'),'free RGB and short hex colors normalize');
check(str_contains($colored,'class="kh-rt-align-center"') && !str_contains($colored,'font-family') && !str_contains($colored,'position'),'alignment class retained and unrelated CSS removed');
check(RichText::sanitize($colored) === $colored,'colored markup idempotent');
foreach (['red','transparent','var(--x)','url(https://example.invalid/a)','expression(alert(1))','rgb(256,0,0)','#abcd','rgba(1,2,3,.5)','#000!important'] as $bad) {
    $html = RichText::sanitize('<span style="color:'.$bad.';background-image:url(https://example.invalid/leak)">Text</span>');
    check(!str_contains($html,'style='),'unsafe or noncanonical CSS rejected '.$bad);
}
$active = '<p onclick="evil()">Safe<img src="https://example.invalid/pixel" onerror="evil()"><script>alert("private")</script><iframe src="https://example.invalid/"></iframe><svg onload="evil()"><foreignObject>bad</foreignObject></svg><math><mtext>bad</mtext></math><style>@import "https://example.invalid/a";</style><object data="x">bad</object><form><input value="bad"></form></p>';
$clean = RichText::sanitize($active);
check($clean === '<p>Safe</p>','active tags, event handlers and remote media removed');
$links = RichText::sanitize('<a href="https://example.invalid/path?q=1&amp;a=2" target="_blank" onclick="evil()">HTTPS</a><a href="/site/kultur">Lokal</a>');
check(str_contains($links,'href="https://example.invalid/path?q=1&amp;a=2"') && str_contains($links,'href="/site/kultur"'),'safe HTTPS and local links retained');
check(str_contains($links,'rel="noopener noreferrer"') && !str_contains($links,'target=') && !str_contains($links,'onclick'),'link attributes restricted');
foreach (['javascript:alert(1)','&#106;avascript:alert(1)','java&#x09;script:alert(1)','data:text/html,bad','//remote.invalid/a','/\\remote.invalid/a','/%2fremote.invalid/a','https://user:password@example.invalid/','http://example.invalid/','https://example.invalid/%0aInjected'] as $bad) {
    check(!str_contains(RichText::sanitize('<a href="'.$bad.'">Text</a>'),'href='),'unsafe link scheme or origin rejected');
}
foreach(['mailto:own+news@example.invalid','MAILTO:own%23news@example.invalid','mailto:own%25news@example.invalid','tel:+49(0)4454-12345','tel:04454.12345;ext=12']as$uri){
    $clean=RichText::sanitize('<p><a href="'.$uri.'">Eigene Adresse Ä</a></p>');
    check(str_contains($clean,'href="'.$uri.'"')&&str_contains($clean,'Eigene Adresse Ä'),'exact safe own mail or telephone link is retained');
    check(RichText::sanitize($clean)===$clean,'safe own contact link is canonical and idempotent');
}
foreach(['mailto:invalid','mailto:own@example.invalid?subject=Prefilled','mailto:own@example.invalid#fragment','mailto://own@example.invalid','mailto:own%0d%0aBcc:foreign@example.invalid','mailto:own%5c@example.invalid','mailto:own%GG@example.invalid','mailto:%22quoted%22@example.invalid','mailto:own@@example.invalid','tel:javascript:alert(1)','tel:+1','tel:123?body=bad','tel:123;ext=abc','tel:123%0d456','tel:'.str_repeat('1',41)]as$uri){
    check(!str_contains(RichText::sanitize('<a href="'.$uri.'">Adresse</a>'),'href='),'unsafe or malformed contact link has no active target');
}
check(!str_contains(RichText::sanitize('<span class="evil kh-rt-size-large kh-rt-align-right" id="steal" data-x="bad">T</span>'),'evil'),'unknown classes and identifiers removed');
$table = RichText::sanitize('<table><thead><tr><th colspan="2" style="background-color:#123456">Übersicht 🎭</th></tr></thead><tbody><tr><td rowspan="2"><strong>Grüße</strong></td><td>€ 15</td></tr><tr><td>✨</td></tr></tbody></table>');
check(str_contains($table,'<table>') && str_contains($table,'<thead>') && str_contains($table,'colspan="2"') && str_contains($table,'rowspan="2"'),'table structure and bounded merged cells preserved');
check(str_contains($table,'style="background-color:#123456"'),'table cell colors preserved');
check(RichText::sanitize($table) === $table,'table idempotent');
$badspan = RichText::sanitize('<table><tr><td colspan="999999" rowspan="0" width="2000">X</td></tr></table>');
check(!str_contains($badspan,'colspan') && !str_contains($badspan,'rowspan') && !str_contains($badspan,'width'),'table attributes bounded');
$plain = RichText::plain($table);
check(str_contains($plain,'Übersicht 🎭') && str_contains($plain,"Grüße\t€ 15") && str_contains($plain,'✨') && !str_contains($plain,'<table>'),'plain projection preserves table text and Unicode');
check(RichText::plain('<p>A &amp; B<br>C</p><p>D</p>') === "A & B\nC\nD",'plain paragraphs and line breaks preserved');
check(RichText::plain('<p>&lt;script&gt;literal&lt;/script&gt;</p>') === '<script>literal</script>','literal escaped markup remains plain text');
check(!str_contains(RichText::sanitize('<!DOCTYPE x [<!ENTITY leak SYSTEM "http://example.invalid/secret">]><p>&leak;</p>'),'SYSTEM'),'external entity declaration never appears in output');
rejects(fn()=>RichText::sanitize("\xff"),'invalid UTF-8 rejected');
rejects(fn()=>RichText::sanitize("Text\0"),'control byte rejected');
rejects(fn()=>RichText::sanitize(str_repeat('x',100001)),'text content limit enforced');
rejects(fn()=>RichText::sanitize(str_repeat('<span>',70).'x'.str_repeat('</span>',70)),'nested complexity limit enforced');
check(RichText::sanitize('') === '' && RichText::plain('') === '','empty body supported');

$content = Content::document('newsletter',['title'=>'Newsletter','body'=>'Obsolete','body_html'=>$markup.$table]);
check(isset($content['body_html']) && str_contains($content['body_html'],'<table>'),'newsletter rich HTML preserved');
check($content['body'] === RichText::plain($markup.$table) && !str_contains($content['body'],'Obsolete'),'newsletter plain body derived from rich source');
check(str_contains(Content::preview($content),'class="kh-richtext"') && str_contains(Content::preview($content),'<strong>'),'newsletter preview renders sanitized formatting');
check(Content::document('newsletter',['title'=>'Clear','body'=>'Old','body_html'=>''])['body'] === '','explicit empty richtext clears old plain body');
check(Content::document('newsletter',['title'=>'Legacy','body'=>'Plain legacy'])['body'] === 'Plain legacy','legacy plain body retained without rich field');
$renderer = RichTextEditor::render('body_html','<p>Hallo 🎶</p><script>evil()</script>','Inhalt <unsicher>');
$dom = new DOMDocument();
@$dom->loadHTML('<meta charset="UTF-8">'.$renderer,LIBXML_NONET);
$xpath = new DOMXPath($dom);
check($xpath->query('//input[@name="body_html" and @type="hidden"]')->length === 1,'editor serializes one named hidden body field');
check($xpath->query('//*[@data-richtext-surface]')->length === 1 && $xpath->query('//script')->length === 0,'editor surface sanitized before display');
check($xpath->query('//*[@data-rt-table="insert"]')->length === 1 && $xpath->query('//input[@type="color"]')->length === 3,'own table and text/background/cell color controls present');
check($xpath->query('//*[@data-rt-emoji]')->length >= 8,'emoji picker present');
check(str_contains($renderer,'Inhalt &lt;unsicher&gt;'),'editor label escaped');
rejects(fn()=>RichTextEditor::render('bad"field',''),'editor field name injection rejected');

echo "$checks native richtext checks passed; no database, external calls or copied editor library.\n";
