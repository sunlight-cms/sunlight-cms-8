<?php

namespace Sunlight\Util;

use Sunlight\Slugify\Slugify;

abstract class StringManipulator
{
    /**
     * Orezat retezec na pozadovanou delku
     *
     * @param string $string
     * @param int    $length pozadovana delka
     * @return string
     */
    static function cut($string, $length)
    {
        if (mb_strlen($string) > $length) {
            return mb_substr($string, 0, $length);
        } else {
            return $string;
        }
    }

    /**
     * Orezat text na pozadovanou delku a pridat "...", pokud je delsi nez limit
     *
     * @param string $string           vstupni retezec
     * @param int    $length           pozadovana delka
     * @param bool   $convert_entities prevest html entity zpet na originalni znaky a po orezani opet zpet
     * @return string
     */
    static function ellipsis($string, $length, $convert_entities = true)
    {
        if ($length === null || $length <= 0) {
            return $string;
        }

        if ($convert_entities) {
            $string = Html::unescape($string);
        }
        if (mb_strlen($string) > $length) {
            $string = mb_substr($string, 0, max(0, $length - 3)) . "...";
        }
        if ($convert_entities) {
            $string = _e($string);
        }

        return $string;
    }

    /**
     * Odstraneni nezadoucich odradkovani a mezer z retezce
     *
     * @param string $string vstupni retezec
     * @return string
     */
    static function trimExtraWhitespace($string)
    {
        $from = array("{(\r\n){3,}}s", "{  +}s");
        $to = array("\r\n\r\n", ' ');

        return preg_replace($from, $to, trim($string));
    }

    /**
     * Formatovani retezce pro uzivatelska jmena, mod rewrite atd.
     *
     * @param string $input vstupni retezec
     * @param bool $lower prevest na mala pismena 1/0
     * @param string|null $extraAllowedChars seznam extra povolenych znaku nebo null
     * @param string|null $fallback fallback pro pripad, ze neni mozne prevest vstup na validni slug
     * @return string
     */
    static function slugify($input, $lower = true, $extraAllowedChars = '._', $fallback = null)
    {
        $slug = Slugify::getInstance()->slugify(
            $input,
            array(
                'lowercase' => $lower,
                'regexp' => sprintf('{(?:[^A-Za-z0-9%s]|-)++}', preg_quote($extraAllowedChars)),
            )
        );

        if ($slug !== '') {
            return $slug;
        }

        if ($fallback !== null) {
            return $fallback;
        }

        return sprintf('item-%x', crc32($input));
    }

    /**
     * Formatovani retezce jako camelCase nebo CamelCase
     *
     * @param string $input
     * @param bool $firstLetterLower
     * @return string
     */
    static function toCamelCase($input, $firstLetterLower = false)
    {
        $output = '';
        $parts = preg_split('{[^a-zA-Z0-9\x80-\xFF]+}', $input, null, PREG_SPLIT_NO_EMPTY);

        for ($i = 0; isset($parts[$i]); ++$i) {
            $part = mb_strtolower($parts[$i]);
            $firstLetter = mb_substr($part, 0, 1);

            if ($i > 0 || !$firstLetterLower) {
                $firstLetter = mb_strtoupper($firstLetter);
            } else {
                $firstLetter = mb_strtolower($firstLetter);
            }

            $output .= $firstLetter . mb_strtolower(mb_substr($part, 1));
        }

        return $output;
    }
}
