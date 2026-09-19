<?php
/**
 * Небольшой генератор QR-кода без внешних сервисов и расширений PHP.
 * Алгоритм основан на QR Code for JavaScript Казухико Арасе (MIT License).
 * https://github.com/kazuhikoarase/qrcode-generator
 *
 * Используется QR version 10, error correction M. Этого достаточно для URL
 * проверки билета, который состоит из домена и случайного ticket_id.
 */
class NedofestQrCode
{
    const VERSION = 10;
    const MODULE_COUNT = 57;
    const DATA_CODEWORDS = 216;
    const MODE_8BIT_BYTE = 4;

    /**
     * Возвращает матрицу QR как массив строк из true/false.
     */
    public static function matrix($data)
    {
        $bytes = self::bytes($data);
        $codewords = self::createData($bytes);
        $matrix = self::createEmptyMatrix();

        self::setupPositionProbePattern($matrix, 0, 0);
        self::setupPositionProbePattern($matrix, self::MODULE_COUNT - 7, 0);
        self::setupPositionProbePattern($matrix, 0, self::MODULE_COUNT - 7);
        self::setupPositionAdjustPattern($matrix);
        self::setupTimingPattern($matrix);
        self::setupTypeInfo($matrix, 0);
        self::setupTypeNumber($matrix);
        self::mapData($matrix, $codewords, 0);

        return $matrix;
    }

    private static function bytes($data)
    {
        $bytes = array();
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $bytes[] = ord($data[$i]);
        }
        return $bytes;
    }

    private static function createEmptyMatrix()
    {
        $matrix = array();
        for ($row = 0; $row < self::MODULE_COUNT; $row++) {
            $matrix[$row] = array_fill(0, self::MODULE_COUNT, null);
        }
        return $matrix;
    }

    private static function putBits(&$bits, $number, $length)
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = (($number >> $i) & 1) === 1;
        }
    }

    private static function createData($bytes)
    {
        $bits = array();
        self::putBits($bits, self::MODE_8BIT_BYTE, 4);
        // Для QR version 10 длина byte-mode хранится в 16 битах.
        self::putBits($bits, count($bytes), 16);

        foreach ($bytes as $byte) {
            self::putBits($bits, $byte, 8);
        }

        $capacity = self::DATA_CODEWORDS * 8;
        if (count($bits) > $capacity) {
            throw new RuntimeException('QR verification URL is too long');
        }

        $terminatorLength = min(4, $capacity - count($bits));
        self::putBits($bits, 0, $terminatorLength);
        while (count($bits) % 8 !== 0) {
            $bits[] = false;
        }

        $bytesOut = array();
        for ($i = 0; $i < count($bits); $i += 8) {
            $value = 0;
            for ($j = 0; $j < 8; $j++) {
                $value = ($value << 1) | ($bits[$i + $j] ? 1 : 0);
            }
            $bytesOut[] = $value;
        }

        $pad = array(0xEC, 0x11);
        $padIndex = 0;
        while (count($bytesOut) < self::DATA_CODEWORDS) {
            $bytesOut[] = $pad[$padIndex % 2];
            $padIndex++;
        }

        // Version 10-M: 4 blocks по 69/43 и один блок 70/44.
        $blocks = array(
            array('total' => 69, 'data' => 43),
            array('total' => 69, 'data' => 43),
            array('total' => 69, 'data' => 43),
            array('total' => 69, 'data' => 43),
            array('total' => 70, 'data' => 44),
        );

        $gf = self::gfTables();
        $offset = 0;
        $dataBlocks = array();
        $errorBlocks = array();
        $maxData = 0;
        $maxError = 0;

        foreach ($blocks as $block) {
            $dataBlock = array_slice($bytesOut, $offset, $block['data']);
            $offset += $block['data'];
            $errorCount = $block['total'] - $block['data'];

            $dataBlocks[] = $dataBlock;
            $errorBlocks[] = self::reedSolomon($dataBlock, $errorCount, $gf);
            $maxData = max($maxData, count($dataBlock));
            $maxError = max($maxError, $errorCount);
        }

        $result = array();
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $maxError; $i++) {
            foreach ($errorBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    private static function gfTables()
    {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $exp[0] = 1;
        for ($i = 1; $i < 256; $i++) {
            $value = $exp[$i - 1] << 1;
            if (($value & 0x100) !== 0) {
                $value ^= 0x11D;
            }
            $exp[$i] = $value & 0xFF;
        }
        for ($i = 0; $i < 255; $i++) {
            $log[$exp[$i]] = $i;
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        return array('exp' => $exp, 'log' => $log);
    }

    private static function gfMultiply($left, $right, $gf)
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }
        return $gf['exp'][$gf['log'][$left] + $gf['log'][$right]];
    }

    private static function polynomialMultiply($left, $right, $gf)
    {
        $result = array_fill(0, count($left) + count($right) - 1, 0);
        foreach ($left as $i => $leftValue) {
            foreach ($right as $j => $rightValue) {
                $result[$i + $j] ^= self::gfMultiply($leftValue, $rightValue, $gf);
            }
        }
        return $result;
    }

    private static function reedSolomon($data, $errorCount, $gf)
    {
        $generator = array(1);
        for ($i = 0; $i < $errorCount; $i++) {
            $generator = self::polynomialMultiply(
                $generator,
                array(1, $gf['exp'][$i]),
                $gf
            );
        }

        $remainder = array_merge($data, array_fill(0, $errorCount, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($generator as $j => $value) {
                $remainder[$i + $j] ^= self::gfMultiply($value, $factor, $gf);
            }
        }

        return array_slice($remainder, count($data), $errorCount);
    }

    private static function setupPositionProbePattern(&$matrix, $row, $col)
    {
        for ($r = -1; $r <= 7; $r++) {
            if ($row + $r < 0 || $row + $r >= self::MODULE_COUNT) {
                continue;
            }
            for ($c = -1; $c <= 7; $c++) {
                if ($col + $c < 0 || $col + $c >= self::MODULE_COUNT) {
                    continue;
                }

                $dark = (
                    ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6)) ||
                    ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6)) ||
                    ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)
                );
                $matrix[$row + $r][$col + $c] = $dark;
            }
        }
    }

    private static function setupPositionAdjustPattern(&$matrix)
    {
        // Alignment positions for QR version 10.
        $positions = array(6, 28, 50);
        foreach ($positions as $row) {
            foreach ($positions as $col) {
                if ($matrix[$row][$col] !== null) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $matrix[$row + $r][$col + $c] = (
                            abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0)
                        );
                    }
                }
            }
        }
    }

    private static function setupTimingPattern(&$matrix)
    {
        for ($i = 8; $i < self::MODULE_COUNT - 8; $i++) {
            if ($matrix[$i][6] === null) {
                $matrix[$i][6] = ($i % 2 === 0);
            }
            if ($matrix[6][$i] === null) {
                $matrix[6][$i] = ($i % 2 === 0);
            }
        }
    }

    private static function bchDigit($data)
    {
        $digit = 0;
        while ($data !== 0) {
            $digit++;
            $data = $data >> 1;
        }
        return $digit;
    }

    private static function bchTypeInfo($data)
    {
        $g15 = 0x537;
        $g15Mask = 0x5412;
        $value = $data << 10;
        while (self::bchDigit($value) - self::bchDigit($g15) >= 0) {
            $value ^= $g15 << (self::bchDigit($value) - self::bchDigit($g15));
        }
        return (($data << 10) | $value) ^ $g15Mask;
    }

    private static function bchTypeNumber($data)
    {
        $g18 = 0x1F25;
        $value = $data << 12;
        while (self::bchDigit($value) - self::bchDigit($g18) >= 0) {
            $value ^= $g18 << (self::bchDigit($value) - self::bchDigit($g18));
        }
        return ($data << 12) | $value;
    }

    private static function setupTypeInfo(&$matrix, $maskPattern)
    {
        // Error correction level M is encoded as 00 in the QR format bits.
        $bits = self::bchTypeInfo(($maskPattern & 7));
        for ($i = 0; $i < 15; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            if ($i < 6) {
                $matrix[$i][8] = $dark;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = $dark;
            } else {
                $matrix[self::MODULE_COUNT - 15 + $i][8] = $dark;
            }
        }

        for ($i = 0; $i < 15; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            if ($i < 8) {
                $matrix[8][self::MODULE_COUNT - $i - 1] = $dark;
            } elseif ($i < 9) {
                $matrix[8][15 - $i - 1 + 1] = $dark;
            } else {
                $matrix[8][15 - $i - 1] = $dark;
            }
        }

        $matrix[self::MODULE_COUNT - 8][8] = true;
    }

    private static function setupTypeNumber(&$matrix)
    {
        $bits = self::bchTypeNumber(self::VERSION);
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $matrix[(int) floor($i / 3)][($i % 3) + self::MODULE_COUNT - 8 - 3] = $dark;
            $matrix[($i % 3) + self::MODULE_COUNT - 8 - 3][(int) floor($i / 3)] = $dark;
        }
    }

    private static function mask($row, $col, $pattern)
    {
        switch ($pattern) {
            case 0: return (($row + $col) % 2) === 0;
            case 1: return ($row % 2) === 0;
            case 2: return ($col % 3) === 0;
            case 3: return (($row + $col) % 3) === 0;
            case 4: return (((int) floor($row / 2) + (int) floor($col / 3)) % 2) === 0;
            case 5: return (($row * $col) % 2 + ($row * $col) % 3) === 0;
            case 6: return (((($row * $col) % 2) + (($row * $col) % 3)) % 2) === 0;
            case 7: return (((($row * $col) % 3) + (($row + $col) % 2)) % 2) === 0;
            default: return false;
        }
    }

    private static function mapData(&$matrix, $data, $maskPattern)
    {
        $inc = -1;
        $row = self::MODULE_COUNT - 1;
        $bitIndex = 7;
        $byteIndex = 0;

        for ($col = self::MODULE_COUNT - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            while (true) {
                for ($c = 0; $c < 2; $c++) {
                    $targetCol = $col - $c;
                    if ($matrix[$row][$targetCol] !== null) {
                        continue;
                    }

                    $dark = false;
                    if ($byteIndex < count($data)) {
                        $dark = (($data[$byteIndex] >> $bitIndex) & 1) === 1;
                    }
                    if (self::mask($row, $targetCol, $maskPattern)) {
                        $dark = !$dark;
                    }
                    $matrix[$row][$targetCol] = $dark;

                    $bitIndex--;
                    if ($bitIndex === -1) {
                        $byteIndex++;
                        $bitIndex = 7;
                    }
                }

                $row += $inc;
                if ($row < 0 || $row >= self::MODULE_COUNT) {
                    $row -= $inc;
                    $inc = -$inc;
                    break;
                }
            }
        }
    }
}