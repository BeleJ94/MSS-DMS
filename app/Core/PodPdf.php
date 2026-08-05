<?php

declare(strict_types=1);

namespace App\Core;

final class PodPdf
{
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;

    public static function render(array $pod): string
    {
        $objects = [];
        $add = static function (string $body) use (&$objects): int { $objects[] = $body; return count($objects); };
        $catalogId = $add(''); $pagesId = $add(''); $pageId = $add('');
        $fontRegular = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $fontBold = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $images = [];
        foreach (['signature_data' => 'Signature', 'delivery_photo_data' => 'Photo', 'signed_note_data' => 'Bon'] as $field => $name) {
            if (empty($pod[$field])) { continue; }
            $size = @getimagesizefromstring($pod[$field]); if (!$size) { continue; }
            $imageId = $add('<< /Type /XObject /Subtype /Image /Width '.$size[0].' /Height '.$size[1].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($pod[$field])." >>\nstream\n".$pod[$field]."\nendstream");
            $images[$name] = ['id' => $imageId, 'width' => $size[0], 'height' => $size[1]];
        }

        $stream = self::background();
        $stream .= self::text(42, 48, 21, 'MSS-DMS', true, [22, 58, 103]);
        $stream .= self::text(42, 70, 9, 'DELIVERY MANAGEMENT SYSTEM', true, [80, 101, 127]);
        $stream .= self::text(553, 49, 15, 'PREUVE DE LIVRAISON', true, [23, 37, 58], 'right');
        $stream .= self::text(553, 70, 9, (string) $pod['reference'], true, [36, 100, 168], 'right');
        $stream .= self::line(42, 88, 553, 88, [216, 226, 237]);

        $stream .= self::labelValue(42, 112, 'CLIENT', (string) $pod['company_name'], 245);
        $stream .= self::labelValue(310, 112, 'DATE ET HEURE DE LIVRAISON', date('d/m/Y à H:i:s', strtotime((string) $pod['captured_at'])), 243);
        $stream .= self::labelValue(42, 157, 'DESTINATION', $pod['site_name'].' · '.$pod['site_address'].', '.$pod['site_city'], 511);
        $stream .= self::labelValue(42, 202, 'CHAUFFEUR', trim($pod['driver_first_name'].' '.$pod['driver_last_name']), 245);
        $stream .= self::labelValue(310, 202, 'VÉHICULE', $pod['registration_number'].' · '.trim($pod['vehicle_brand'].' '.$pod['vehicle_model']), 243);
        $stream .= self::labelValue(42, 247, 'RÉCEPTIONNAIRE', (string) $pod['recipient_name'], 245);
        $stream .= self::labelValue(310, 247, 'POSITION GPS', number_format((float) $pod['latitude'], 6, '.', '').', '.number_format((float) $pod['longitude'], 6, '.', '').' (±'.number_format((float) $pod['accuracy_m'], 0).' m)', 243);

        $stream .= self::text(42, 309, 9, 'MARCHANDISES LIVRÉES', true, [80, 101, 127]);
        $stream .= self::rect(42, 320, 511, 25, [234, 240, 247], true);
        $stream .= self::text(54, 337, 9, 'DÉSIGNATION', true, [23, 37, 58]);
        $stream .= self::text(540, 337, 9, 'QUANTITÉ', true, [23, 37, 58], 'right');
        $y = 359;
        foreach (array_slice($pod['goods'], 0, 7) as $goods) {
            $stream .= self::text(54, $y, 9, self::shorten((string) $goods['description_snapshot'], 64), false, [35, 50, 72]);
            $stream .= self::text(540, $y, 9, $goods['quantity'].' '.$goods['unit'], true, [35, 50, 72], 'right');
            $stream .= self::line(42, $y + 10, 553, $y + 10, [230, 235, 241]); $y += 25;
        }
        if (count($pod['goods']) > 7) { $stream .= self::text(54, $y, 8, '+ '.(count($pod['goods']) - 7).' autre(s) ligne(s)', false, [80, 101, 127]); }

        $imageTop = 540;
        if (isset($images['Bon'])) {
            foreach ([['Signature',42,'SIGNATURE'],['Photo',219,'PHOTO LIVRAISON'],['Bon',396,'BON SIGNÉ']] as $column) {
                $stream .= self::text($column[1], $imageTop - 13, 8, $column[2], true, [80, 101, 127]);
                $stream .= self::rect($column[1], $imageTop, 157, 126, [225, 232, 239], false);
                $stream .= self::image($column[0], $images[$column[0]], $column[1] + 8, $imageTop + 8, 141, 106);
            }
        } else {
            $stream .= self::text(42, $imageTop - 13, 9, 'SIGNATURE DU RÉCEPTIONNAIRE', true, [80, 101, 127]);
            $stream .= self::rect(42, $imageTop, 245, 126, [225, 232, 239], false);
            if (isset($images['Signature'])) { $stream .= self::image('Signature', $images['Signature'], 52, $imageTop + 10, 225, 96); }
            $stream .= self::text(54, $imageTop + 118, 8, (string) $pod['recipient_name'], false, [80, 101, 127]);
            $stream .= self::text(310, $imageTop - 13, 9, 'PHOTO À LA LIVRAISON', true, [80, 101, 127]);
            $stream .= self::rect(310, $imageTop, 243, 126, [225, 232, 239], false);
            if (isset($images['Photo'])) { $stream .= self::image('Photo', $images['Photo'], 320, $imageTop + 10, 223, 106); }
        }

        $stream .= self::text(42, 695, 9, 'OBSERVATIONS', true, [80, 101, 127]);
        $stream .= self::rect(42, 705, 511, 65, [225, 232, 239], false);
        $observations = trim((string) ($pod['observations'] ?? '')) ?: 'Aucune observation.';
        foreach (self::wrap($observations, 95, 3) as $index => $line) { $stream .= self::text(54, 724 + ($index * 14), 9, $line, false, [35, 50, 72]); }
        $stream .= self::text(42, 801, 8, 'Document généré automatiquement par MSS-DMS · Identifiant POD #'.$pod['id'], false, [116, 128, 149]);
        $stream .= self::text(553, 801, 8, 'Authentifié par GPS, chauffeur et véhicule', false, [36, 119, 95], 'right');

        $contentId = $add('<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream");
        $xObjects = '';
        foreach ($images as $name => $image) { $xObjects .= '/'.$name.' '.$image['id'].' 0 R '; }
        $objects[$pageId - 1] = '<< /Type /Page /Parent '.$pagesId.' 0 R /MediaBox [0 0 '.self::WIDTH.' '.self::HEIGHT.'] /Resources << /Font << /F1 '.$fontRegular.' 0 R /F2 '.$fontBold.' 0 R >> /XObject << '.$xObjects.'>> >> /Contents '.$contentId.' 0 R >>';
        $objects[$pagesId - 1] = '<< /Type /Pages /Kids ['.$pageId.' 0 R] /Count 1 >>';
        $objects[$catalogId - 1] = '<< /Type /Catalog /Pages '.$pagesId.' 0 R >>';
        return self::assemble($objects, $catalogId);
    }

    private static function background(): string { return self::rect(0, 0, self::WIDTH, self::HEIGHT, [255,255,255], true).self::rect(0, 0, 10, self::HEIGHT, [22,58,103], true); }
    private static function labelValue(float $x, float $y, string $label, string $value, float $width): string { return self::text($x, $y, 8, $label, true, [116,128,149]).self::text($x, $y + 18, 10, self::shorten($value, (int) ($width / 5.4)), true, [23,37,58]); }
    private static function text(float $x, float $top, float $size, string $text, bool $bold, array $color, string $align='left'): string { $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text; $encoded = str_replace(['\\','(',')'], ['\\\\','\(','\)'], $encoded); if ($align === 'right') { $x -= strlen($encoded) * $size * .49; } return sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $bold?'F2':'F1', $size, $color[0]/255, $color[1]/255, $color[2]/255, $x, self::HEIGHT-$top, $encoded); }
    private static function rect(float $x,float $top,float $width,float $height,array $color,bool $fill): string { return sprintf("%.3F %.3F %.3F %s %.2F %.2F %.2F %.2F re %s\n",$color[0]/255,$color[1]/255,$color[2]/255,$fill?'rg':'RG',$x,self::HEIGHT-$top-$height,$width,$height,$fill?'f':'S'); }
    private static function line(float $x1,float $y1,float $x2,float $y2,array $color): string { return sprintf("%.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S\n",$color[0]/255,$color[1]/255,$color[2]/255,$x1,self::HEIGHT-$y1,$x2,self::HEIGHT-$y2); }
    private static function image(string $name,array $image,float $x,float $top,float $boxWidth,float $boxHeight): string { $ratio=min($boxWidth/$image['width'],$boxHeight/$image['height']);$width=$image['width']*$ratio;$height=$image['height']*$ratio;$left=$x+($boxWidth-$width)/2;$bottom=self::HEIGHT-$top-$boxHeight+($boxHeight-$height)/2;return sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",$width,$height,$left,$bottom,$name); }
    private static function shorten(string $value,int $length): string { return mb_strlen($value)>$length?mb_substr($value,0,$length-1).'…':$value; }
    private static function wrap(string $value,int $length,int $max): array { $lines=[];$words=preg_split('/\s+/u',trim($value))?:[];$line='';foreach($words as $word){$candidate=$line===''?$word:$line.' '.$word;if(mb_strlen($candidate)>$length&&$line!==''){$lines[]=$line;$line=$word;if(count($lines)>=$max)break;}else{$line=$candidate;}}if(count($lines)<$max&&$line!=='')$lines[]=$line;return $lines; }
    private static function assemble(array $objects,int $root): string { $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $index=>$body){$offsets[]=strlen($pdf);$number=$index+1;$pdf.=$number." 0 obj\n".$body."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);$pdf.="trailer\n<< /Size ".(count($objects)+1).' /Root '.$root." 0 R >>\nstartxref\n".$xref."\n%%EOF";return $pdf; }
}
