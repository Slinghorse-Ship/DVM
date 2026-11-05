<?php declare(strict_types=1);
/** A new independent one-page fixture, with exact xref offsets and no legal content. */
function ownWebsitePdf(string $text): string
{
    $text=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);
    $stream="BT /F1 14 Tf 40 100 Td (".$text.") Tj ET\n";
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 320 180] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream'];
    $pdf="%PDF-1.4\n";$offsets=[];
    foreach ($objects as $i=>$object) {$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n";}
    $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $offset) $pdf.=sprintf('%010d 00000 n ',$offset)."\n";
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}
