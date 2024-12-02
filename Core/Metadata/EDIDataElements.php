<?php
namespace GZMP\EDI\Core\Metadata;

use PDODB;

/**
 * This class handles retrieving data on specific data elements, identified by their data element id.
 */
class EDIDataElements
{
    protected static $elements = [];

    /**
     * Load data elements into memory.
     */
    protected static function setElements()
    {
        $db = new PDODB();
        $query = "SELECT * FROM edi_data_elements";
        $records = $db->fetchAll($query);
        if (empty($records) || !is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            self::$elements[$record['id']] = $record;
        }

        return true;
    }

    /**
     * Return array of all data elements.
     */
    private static function getElements(): array
    {
        if (empty(self::$elements)) {
            self::setElements();
        }

        return self::$elements;
    }

    /**
     * Return the specified data element's type.
     */
    public static function getDataType($data_element_id)
    {
        $elements = self::getElements();

        if (!empty($elements[$data_element_id]['data_type'])) {
            return $elements[$data_element_id]['data_type'];
        }

        return '';
    }

    /**
     * Return the given element's record as an array.
     */
    public static function getElement($data_element_id): array
    {
        $elements = self::getElements();

        if (isset($elements[$data_element_id])) {
            return $elements[$data_element_id];
        }

        return [];
    }
}
