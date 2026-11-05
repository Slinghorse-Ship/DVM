<?php declare(strict_types=1);
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$previewUrl='?synthetic_pdf=1';$imagePreviewUrl='?synthetic_image=1';$imagePreviewAlt='Synthetic image';
ob_start();require __DIR__.'/../views/Documents/pdf-viewer.php';require __DIR__.'/../views/Communication/image-preview.php';$html=ob_get_clean();
$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);$n=0;
function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);++$n;}
foreach(['pdf-smaller','pdf-larger','pdf-fit','pdf-prev','pdf-next','image-smaller','image-larger','image-fit'] as $attribute){$buttons=$xp->query('//button[@data-'.$attribute.']');check($buttons->length===1&&mb_strlen(trim($buttons->item(0)->textContent))>3,'Visible descriptive label for '.$attribute);check($buttons->item(0)->getAttribute('type')==='button','Preview control never submits containing form');}
$pdfScripts=$xp->query('//script[starts-with(@src,"application-pdf.js?")]');
check($pdfScripts->length===1&&$pdfScripts->item(0)->getAttribute('type')==='module','One shared PDF module for invoice and admission previews');
check($xp->query('//script[starts-with(@src,"image-preview.js?")]')->length===1,'One image preview controller');
check($xp->query('//select[@data-pdf-zoom]/option[@value="2"]')->length===1,'PDF exposes 200 percent zoom alongside fit-width');
check($xp->query('//a[@target="_blank" and @rel="noopener" and contains(normalize-space(.),"In neuem Tab öffnen") and @href="?synthetic_pdf=1"]')->length===1,'PDF preview offers safe separate-tab opening');
check($xp->query('//link[contains(@href,"preview-pan.css")]')->length===2,'Preview styles loaded externally for CSP');
echo "$n preview control checks passed\n";
