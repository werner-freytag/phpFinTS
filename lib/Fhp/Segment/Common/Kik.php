<?php

namespace Fhp\Segment\Common;

use Fhp\Segment\BaseDeg;

/**
 * Mehrfach verwendetes Element: Kreditinstitutskennung (Version 1)
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Messages_Geschaeftsvorfaelle_2015-08-07_final_version.pdf
 * Section: B.2
 */
class Kik extends BaseDeg
{
    const DEFAULT_COUNTRY_CODE = '280'; // Germany

    /** @var string (ISO 3166-1; has leading zeros; Germany is 280, see also chapter E.4 */
    public $laenderkennzeichen;
    /** @var string|null Max length: 30 (Mandatory/absent depending on the country) */
    public $kreditinstitutscode;

    /**
     * @param string $kreditinstitutscode
     * @return Kik
     */
    public static function create($kreditinstitutscode)
    {
        $result = new Kik();
        $result->laenderkennzeichen = static::DEFAULT_COUNTRY_CODE;
        $result->kreditinstitutscode = $kreditinstitutscode;
        return $result;
    }
}
