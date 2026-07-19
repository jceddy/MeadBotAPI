<?php

declare(strict_types=1);

namespace MeadBotApi\Calculator;

/**
 * Ported from MeadBot's src/calculator/BlendCalculator.js — blending math for combining two
 * liquids of different gravity/ABV/etc into a target value. Already fully separated from
 * MeadBot's Discord command in the original codebase (blend.js is a thin argument-parsing
 * wrapper around calculateBlend()), so this is a direct, behavior-preserving port.
 */
final class BlendCalculator
{
    private static function right(string $str, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $len = strlen($str);
        if ($n > $len) {
            return $str;
        }
        return substr($str, $len - $n);
    }

    // round val to sigFigures decimal places, trimming trailing zeros in the fractional part
    public static function displayNumber(float $val, int $sigFigures): float
    {
        $neg = $val < 0 ? '-' : '';
        $scale = 10 ** $sigFigures;
        $i = (int) floor(round($scale * abs($val)) / $scale);
        $x = self::right('000' . (string) (int) round($scale * (abs($val) - abs($i))), $sigFigures);
        return (float) ($neg . $i . '.' . $x);
    }

    public static function displayNrTrim(float $val, int $sigFigures): float
    {
        $v = self::displayNumber($val, $sigFigures) - floor($val);
        return floor($val) + $v;
    }

    /**
     * calculateBlend(fieldToCalculate, values) - given any 4 of
     * {value1, value2, blendedValue, volume1, volume2, totalVolume}, solve for
     * fieldToCalculate. Returns {error: true, errorMessage} on failure, or the resolved fields
     * on success.
     *
     * @return array<string, mixed>
     */
    public static function calculateBlend(
        int $fieldToCalculate,
        ?float $value1,
        ?float $value2,
        ?float $blendedValue,
        ?float $volume1,
        ?float $volume2,
        ?float $totalVolume
    ): array {
        $vA = $value1;
        $vB = $value2;
        $vC = $volume1;
        $vD = $volume2;
        $vM = $blendedValue;
        $vCD = $totalVolume;

        if ($fieldToCalculate === Constants::BLEND_FIELD_VALUE1) {
            if ($vB !== null && $vC !== null && $vD !== null && $vM !== null) {
                $value1 = self::displayNrTrim(($vM * $vC + $vM * $vD - $vB * $vD) / $vC, 3);
            } else {
                return ['error' => true, 'errorMessage' => 'More fields are required to calculate Value #1.'];
            }
        } elseif ($fieldToCalculate === Constants::BLEND_FIELD_VOLUME1) {
            if ($vA !== null && $vB !== null && $vD !== null && $vM !== null) {
                $vC = self::displayNrTrim(($vD * (1 - $vB / $vM)) / ($vA / $vM - 1), 3);
                $volume1 = $vC;
            } elseif ($vA !== null && $vB !== null && $vCD !== null && $vM !== null) {
                $vC = self::displayNrTrim(($vCD * ($vB - $vM)) / ($vB - $vA), 3);
                $volume1 = $vC;
                $vD = self::displayNrTrim(($vCD * ($vM - $vA)) / ($vB - $vA), 3);
                $volume2 = $vD;
            } else {
                return ['error' => true, 'errorMessage' => 'Please specify Volume #2 or total volume.'];
            }
        } elseif ($fieldToCalculate === Constants::BLEND_FIELD_BLENDED_VALUE) {
            if ($vA !== null && $vB !== null && $vC !== null && $vD !== null) {
                $vM = self::displayNrTrim(($vA * $vC + $vB * $vD) / ($vC + $vD), 3);
                $blendedValue = $vM;
            } else {
                return ['error' => true, 'errorMessage' => 'More fields are required to calculate blended value.'];
            }
        } elseif ($fieldToCalculate === Constants::BLEND_FIELD_VALUE2) {
            if ($vA !== null && $vC !== null && $vD !== null && $vM !== null) {
                $value2 = self::displayNrTrim(($vM * $vC + $vM * $vD - $vA * $vC) / $vD, 3);
            } else {
                return ['error' => true, 'errorMessage' => 'More fields are required to calculate Value #2.'];
            }
        } elseif ($fieldToCalculate === Constants::BLEND_FIELD_VOLUME2) {
            if ($vA !== null && $vB !== null && $vC !== null && $vM !== null) {
                $vD = self::displayNrTrim(($vC * ($vA / $vM - 1)) / (1 - $vB / $vM), 3);
                $volume2 = $vD;
            } elseif ($vA !== null && $vB !== null && $vCD !== null && $vM !== null) {
                $vC = self::displayNrTrim(($vCD * ($vB - $vM)) / ($vB - $vA), 3);
                $volume1 = $vC;
                $vD = self::displayNrTrim(($vCD * ($vM - $vA)) / ($vB - $vA), 3);
                $volume2 = $vD;
            } else {
                return ['error' => true, 'errorMessage' => 'Please specify Volume #1 or total volume.'];
            }
        } elseif ($fieldToCalculate === Constants::BLEND_FIELD_TOTAL_VOLUME) {
            if ($vA !== null && $vB !== null && $vM !== null) {
                if ($vC !== null && $vD === null) {
                    $vD = self::displayNrTrim(($vC * ($vA / $vM - 1)) / (1 - $vB / $vM), 3);
                    $volume2 = $vD;
                    $vCD = self::displayNrTrim($vC + $vD, 3);
                    $totalVolume = $vCD;
                } elseif ($vC === null && $vD !== null) {
                    $vC = self::displayNrTrim(($vD * (1 - $vB / $vM)) / ($vA / $vM - 1), 3);
                    $volume1 = $vC;
                    $vCD = self::displayNrTrim($vC + $vD, 3);
                    $totalVolume = $vCD;
                } elseif ($vC === null && $vD === null && $vCD !== null) {
                    $vC = self::displayNrTrim(($vCD * ($vB - $vM)) / ($vB - $vA), 3);
                    $volume1 = $vC;
                    $vD = self::displayNrTrim(($vCD * ($vM - $vA)) / ($vB - $vA), 3);
                    $volume2 = $vD;
                } else {
                    return ['error' => true, 'errorMessage' => 'Please specify a volume.'];
                }
            }
        }

        if ($vCD !== ($vC + $vD) && $vC !== null && $vC > 0 && $vD !== null && $vD > 0) {
            $vCD = self::displayNrTrim($vC + $vD, 3);
            $totalVolume = $vCD;
        }

        return [
            'error' => false,
            'value1' => $value1,
            'value2' => $value2,
            'blendedValue' => $blendedValue,
            'volume1' => $volume1,
            'volume2' => $volume2,
            'totalVolume' => $totalVolume,
        ];
    }
}
