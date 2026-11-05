<?php declare(strict_types=1);
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function url(array $v):string{return '?'.http_build_query($v);}
$finance=new class{public bool $read=true;public bool $write=false;public function canRead():bool{return $this->read;}public function canWrite():bool{return $this->write;}};
ob_start();require __DIR__.'/../views/Finance/Accounting/finance-help.php';$html=ob_get_clean();
$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($doc);$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;}
foreach($xp->query('//nav[@aria-label="Inhalt der Finanzhilfe"]//a') as $a){$id=substr($a->getAttribute('href'),1);check($xp->query('//*[@id="'.$id.'"]')->length===1,'Help anchor resolves: '.$id);}
$allowed=json_decode(file_get_contents(__DIR__.'/../resources/handbook-images.json'),true,512,JSON_THROW_ON_ERROR);
foreach($xp->query('//figure//img') as $img){parse_str(parse_url($img->getAttribute('src'),PHP_URL_QUERY),$query);check(in_array($query['handbook_image'],$allowed,true),'Image belongs to authenticated handbook allowlist');check(is_file(__DIR__.'/../resources/handbook/'.$query['handbook_image']),'Existing reviewed figure exists');}
check($xp->query('//form')->length===0,'Help contains no mutation forms');
check(!str_contains($html,'tab=bank&amp;bank_import=1'),'read-only help has no private bank-review shortcut');
$finance->write=true;ob_start();require __DIR__.'/../views/Finance/Accounting/finance-help.php';$writerHelp=ob_get_clean();check(str_contains($writerHelp,'tab=bank&amp;bank_import=1'),'finance writer has the current reachable original-review shortcut');
foreach(['Eingang','Prüfen','Bezahlen'] as $step)check($xp->query('//ol[contains(@class,"finance-simple-steps")]//strong[text()="'.$step.'"]')->length===1,'Simple finance step retained: '.$step);
foreach(['Geführter Testlauf mit Demodaten','Vor dem fertigen Export müssen die gemeldeten offenen Punkte geklärt sein','Mit Leserecht können Sie diese Anleitung'] as $internalText)check(!str_contains($html,$internalText),'Internal help text removed: '.$internalText);
$finance->read=false;ob_start();require __DIR__.'/../views/Finance/Accounting/finance-help.php';$denied=ob_get_clean();check($denied===''&&http_response_code()===403,'Financial read permission enforced by view');
echo "$n finance help checks passed\n";
