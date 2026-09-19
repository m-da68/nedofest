<?php
require_once __DIR__ . '/ticket_qr.php';

/**
 * Минимальный PDF-генератор билета.
 *
 * PDF формируется без внешнего сервиса: в документ встраивается DejaVu Sans,
 * поэтому ФИО и подписи на русском отображаются на любом PDF-просмотрщике.
 */
class NedofestTicketPdf
{
    private $ticket;
    private $verificationUrl;
    private $fontPath;
    private $characters = array();
    private $nextCharacterCode = 1;

    public function __construct(array $ticket, $verificationUrl, $fontPath)
    {
        $this->ticket = $ticket;
        $this->verificationUrl = $verificationUrl;
        $this->fontPath = $fontPath;
    }

    /**
     * Возвращает готовый бинарный PDF.
     */
    public function output()
    {
        if (!is_readable($this->fontPath)) {
            throw new RuntimeException('DejaVuSans.ttf is missing');
        }

        $content = $this->buildPageContent();
        $fontFile = file_get_contents($this->fontPath);
        if ($fontFile === false) {
            throw new RuntimeException('Cannot read PDF font');
        }

        $objects = array();
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R '
            . '/MediaBox [0 0 595 842] '
            . '/Resources << /Font << /F1 5 0 R >> >> '
            . '/Contents 4 0 R >>';
        $objects[4] = '<< /Length ' . strlen($content) . " >>\nstream\n"
            . $content . "\nendstream";
        $objects[5] = $this->buildFontObject();
        $objects[6] = $this->buildFontDescriptor();
        $objects[7] = $this->buildEncodingObject();
        $objects[8] = '<< /Length ' . strlen($fontFile)
            . ' /Length1 ' . strlen($fontFile) . " >>\nstream\n"
            . $fontFile . "\nendstream";
        $objects[9] = $this->buildToUnicodeObject();

        // Объект 6 ссылается на шрифт 8, поэтому он должен быть собран после
        // чтения шрифта, но номера объектов остаются стабильными.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array(0);
        for ($i = 1; $i <= count($objects); $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1)
            . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    private function buildPageContent()
    {
        $commands = array();
        $commands[] = 'q';
        $commands[] = '0.93 0.89 0.80 rg';
        $commands[] = '0 0 595 842 re f';
        $commands[] = 'Q';

        // Рамка в стиле афиши.
        $commands[] = 'q';
        $commands[] = '0.10 0.08 0.06 RG';
        $commands[] = '2 w';
        $commands[] = '35 35 525 772 re S';
        $commands[] = 'Q';

        $this->addText($commands, 65, 750, 30, 'НедоFEST', array(0.55, 0.08, 0.05));
        $this->addText($commands, 67, 720, 12, 'ПИЛОТНЫЙ КОНЦЕРТ', array(0.10, 0.08, 0.06));
        $this->addText($commands, 67, 685, 20, 'ЭЛЕКТРОННЫЙ БИЛЕТ', array(0.10, 0.08, 0.06));

        $this->addText($commands, 67, 635, 11, 'ФАМИЛИЯ И ИМЯ', array(0.35, 0.30, 0.24));
        $this->addText($commands, 67, 608, 18, (string) $this->ticket['name'], array(0.10, 0.08, 0.06));

        $this->addText($commands, 67, 568, 11, 'E-MAIL', array(0.35, 0.30, 0.24));
        $this->addText($commands, 67, 543, 14, (string) $this->ticket['email'], array(0.10, 0.08, 0.06));

        $this->addText($commands, 67, 498, 11, 'ID БИЛЕТА', array(0.35, 0.30, 0.24));
        $this->addText($commands, 67, 472, 16, (string) $this->ticket['ticket_id'], array(0.55, 0.08, 0.05));

        $this->addText($commands, 67, 427, 11, 'ID ПЛАТЕЖА ЮKASSA', array(0.35, 0.30, 0.24));
        $this->addText($commands, 67, 402, 10, (string) $this->ticket['payment_id'], array(0.10, 0.08, 0.06));

        $this->addText($commands, 67, 350, 12, '26 СЕНТЯБРЯ 2026 / СУББОТА / 19:00', array(0.10, 0.08, 0.06));
        $this->addText($commands, 67, 323, 11, 'ТАМБОВ, АСТРАХАНСКАЯ 2В', array(0.10, 0.08, 0.06));
        $this->addText($commands, 67, 297, 10, 'Площадки баров «Кафедра» и «Спутник»', array(0.10, 0.08, 0.06));

        $this->addQrCode($commands, 385, 125, 2.35);
        $this->addText($commands, 395, 105, 9, 'ПРОВЕРИТЬ БИЛЕТ', array(0.10, 0.08, 0.06));
        $this->addText($commands, 67, 105, 9, 'Предъявите QR-код при входе', array(0.35, 0.30, 0.24));
        $this->addText($commands, 67, 83, 9, 'Билет действителен только после успешной оплаты', array(0.35, 0.30, 0.24));

        return implode("\n", $commands) . "\n";
    }

    private function addText(&$commands, $x, $y, $size, $text, array $color)
    {
        $encoded = $this->encodeText((string) $text);
        $commands[] = 'BT';
        $commands[] = '/F1 ' . (float) $size . ' Tf';
        $commands[] = sprintf('%.3f %.3f %.3f rg', $color[0], $color[1], $color[2]);
        $commands[] = '1 0 0 1 ' . (float) $x . ' ' . (float) $y . ' Tm';
        $commands[] = '(' . $encoded . ') Tj';
        $commands[] = 'ET';
    }

    private function addQrCode(&$commands, $x, $y, $moduleSize)
    {
        $matrix = NedofestQrCode::matrix($this->verificationUrl);
        $size = count($matrix);
        $quiet = 4 * $moduleSize;
        $total = ($size * $moduleSize) + ($quiet * 2);

        $commands[] = 'q';
        $commands[] = '1 1 1 rg';
        $commands[] = ($x - $quiet) . ' ' . ($y - $quiet) . ' ' . $total . ' ' . $total . ' re f';
        $commands[] = '0 0 0 rg';

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if (!$matrix[$row][$col]) {
                    continue;
                }
                $rectX = $x + ($col * $moduleSize);
                $rectY = $y + (($size - $row - 1) * $moduleSize);
                $commands[] = $rectX . ' ' . $rectY . ' '
                    . $moduleSize . ' ' . $moduleSize . ' re f';
            }
        }
        $commands[] = 'Q';
    }

    private function encodeText($text)
    {
        $result = '';
        foreach ($this->utf8Codepoints($text) as $codepoint) {
            if (!isset($this->characters[$codepoint])) {
                if ($this->nextCharacterCode > 250) {
                    throw new RuntimeException('Too many different characters in ticket');
                }
                $this->characters[$codepoint] = $this->nextCharacterCode++;
            }
            // Octal escapes safely encode the custom one-byte PDF character code.
            $result .= '\\' . str_pad(decoct($this->characters[$codepoint]), 3, '0', STR_PAD_LEFT);
        }
        return $result;
    }

    private function utf8Codepoints($text)
    {
        $result = array();
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $first = ord($text[$i]);
            if ($first < 0x80) {
                $result[] = $first;
            } elseif (($first & 0xE0) === 0xC0 && $i + 1 < $length) {
                $result[] = (($first & 0x1F) << 6) | (ord($text[++$i]) & 0x3F);
            } elseif (($first & 0xF0) === 0xE0 && $i + 2 < $length) {
                $result[] = (($first & 0x0F) << 12)
                    | ((ord($text[++$i]) & 0x3F) << 6)
                    | (ord($text[++$i]) & 0x3F);
            } elseif (($first & 0xF8) === 0xF0 && $i + 3 < $length) {
                $result[] = (($first & 0x07) << 18)
                    | ((ord($text[++$i]) & 0x3F) << 12)
                    | ((ord($text[++$i]) & 0x3F) << 6)
                    | (ord($text[++$i]) & 0x3F);
            } else {
                $result[] = 0x3F;
            }
        }
        return $result;
    }

    private function glyphName($codepoint)
    {
        $names = array(
            32 => 'space', 33 => 'exclam', 34 => 'quotedbl', 35 => 'numbersign',
            36 => 'dollar', 37 => 'percent', 38 => 'ampersand', 39 => 'quotesingle',
            40 => 'parenleft', 41 => 'parenright', 42 => 'asterisk', 43 => 'plus',
            44 => 'comma', 45 => 'hyphen', 46 => 'period', 47 => 'slash',
            58 => 'colon', 59 => 'semicolon', 60 => 'less', 61 => 'equal',
            62 => 'greater', 63 => 'question', 64 => 'at', 91 => 'bracketleft',
            92 => 'backslash', 93 => 'bracketright', 94 => 'asciicircum',
            95 => 'underscore', 96 => 'grave', 123 => 'braceleft',
            124 => 'bar', 125 => 'braceright', 126 => 'asciitilde',
        );

        if (isset($names[$codepoint])) {
            return $names[$codepoint];
        }
        if (($codepoint >= 48 && $codepoint <= 57) ||
            ($codepoint >= 65 && $codepoint <= 90) ||
            ($codepoint >= 97 && $codepoint <= 122)) {
            return chr($codepoint);
        }
        if ($codepoint <= 0xFFFF) {
            return 'uni' . strtoupper(str_pad(dechex($codepoint), 4, '0', STR_PAD_LEFT));
        }
        return 'u' . strtoupper(dechex($codepoint));
    }

    private function characterWidth($codepoint)
    {
        if ($codepoint === 32) {
            return 280;
        }
        if ($codepoint < 128 && in_array($codepoint, array(33, 44, 45, 46, 58, 59), true)) {
            return 350;
        }
        return 600;
    }

    private function buildFontObject()
    {
        $lastCode = max(1, $this->nextCharacterCode - 1);
        $widths = array();
        $codepointsByCode = array_flip($this->characters);
        for ($code = 1; $code <= $lastCode; $code++) {
            $codepoint = isset($codepointsByCode[$code]) ? (int) $codepointsByCode[$code] : 32;
            $widths[] = $this->characterWidth($codepoint);
        }

        return '<< /Type /Font /Subtype /TrueType /BaseFont /DejaVuSans '
            . '/FirstChar 1 /LastChar ' . $lastCode
            . ' /Widths [' . implode(' ', $widths) . '] '
            . '/Encoding 7 0 R /FontDescriptor 6 0 R /ToUnicode 9 0 R >>';
    }

    private function buildFontDescriptor()
    {
        return '<< /Type /FontDescriptor /FontName /DejaVuSans /Flags 32 '
            . '/FontBBox [-1024 -500 3000 1500] /ItalicAngle 0 '
            . '/Ascent 928 /Descent -236 /CapHeight 733 /StemV 80 '
            . '/FontFile2 8 0 R >>';
    }

    private function buildEncodingObject()
    {
        $parts = array();
        foreach ($this->characters as $codepoint => $code) {
            $parts[] = $code . ' /' . $this->glyphName((int) $codepoint);
        }
        return '<< /Type /Encoding /Differences [' . implode(' ', $parts) . '] >>';
    }

    private function buildToUnicodeObject()
    {
        $entries = array();
        foreach ($this->characters as $codepoint => $code) {
            $unicode = strtoupper(str_pad(dechex((int) $codepoint), 4, '0', STR_PAD_LEFT));
            $entries[] = '<' . sprintf('%02X', $code) . '> <' . $unicode . '>';
        }

        $cmap = "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<01> <FA>\nendcodespacerange\n"
            . count($entries) . " beginbfchar\n"
            . implode("\n", $entries) . "\nendbfchar\n"
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";

        return '<< /Length ' . strlen($cmap) . " >>\nstream\n" . $cmap . "\nendstream";
    }
}