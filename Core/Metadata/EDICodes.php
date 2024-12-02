<?php
namespace GZMP\EDI\Core\Metadata;

use PDODB;

/**
 * This class handles retrieving codes for specific data elements identified by the data element's id.
 */
class EDICodes
{
    protected static $codes = array();

    /**
     * Load EDI codes into memory.
     */
    protected static function setCodes()
    {
        $db = new PDODB();
        $query = "SELECT * FROM edi_codes ORDER BY data_element_id, code";
        $records = $db->fetchAll($query);
        if (empty($records) || !is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            self::$codes[$record['data_element_id']][$record['code']] = $record['description'];
        }

        return true;
    }

    /**
     * Return an array of EDI codes.
     */
    private static function getCodes()
    {
        if (empty(self::$codes)) {
            self::setCodes();
        }

        return self::$codes;
    }

    /**
     * Return an array of codes => descriptions for the specified data element.
     */
    public static function getCodesForDataElement($data_element_id): array
    {
        $codes = self::getCodes();

        if (isset($codes[$data_element_id])) {
            return $codes[$data_element_id];
        }

        return [];
    }

    /**
     * Return the description corresponding to the specified code.
     */
    public static function getValue($data_element_id, $code)
    {
        $codes = self::getCodes();

        if (isset($codes[$data_element_id][$code])) {
            return $codes[$data_element_id][$code];
        }

        return $code;
    }

    /**
     * Return the description corresponding to the specified code.
     */
    public static function getDescriptionText($segment_id, $position, $code, $include_code = true)
    {
        $data_element_id = EDISchemas::getDataElementId($segment_id, $position);
        $description = EDICodes::getValue($data_element_id, $code);
        if (!empty($description) && $description != $code) {
            $code = trim($code);
            return $include_code ? "$description [$code]" : $description;
        }
        return $code;
    }
}
