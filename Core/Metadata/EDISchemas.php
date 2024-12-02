<?php
namespace GZMP\EDI\Core\Metadata;

use PDODB;

/**
 * Schemas map data elements to segments and provide some additional metadata. This class handles retrieving this data.
 */
class EDISchemas
{
    protected static $schemas = array();

    /**
     * Load EDI schemas into memory.
     */
    protected static function setSchemas(): bool
    {
        $db = new PDODB();
        $query = "SELECT * FROM edi_schemas ORDER BY segment_id, data_index";
        $records = $db->fetchAll($query);
        if (empty($records) || !is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            self::$schemas[trim($record['segment_id'])][$record['data_index']] = $record;
        }

        return true;
    }

    /**
     * Return an array of EDI schemas.
     */
    private static function getSchemas(): array
    {
        if (empty(self::$schemas)) {
            self::setSchemas();
        }

        return self::$schemas;
    }

    /**
     * Return an array of EDI segmentIds.
     */
    public static function getAllSegmentIds(): array
    {
        if (empty(self::$schemas)) {
            self::setSchemas();
        }

        return array_keys(self::$schemas);
    }

    /**
     * Return the name of the given schema.
     */
    public static function getName($segment_id, $position): string
    {
        $schemas = self::getSchemas();

        if (!empty($schemas[$segment_id][$position]['name'])) {
            return $schemas[$segment_id][$position]['name'];
        }

        return '';
    }

    /**
     * Return all schemas for the specified segment.
     */
    public static function getSegmentSchemas($segment_id): array
    {
        $schemas = self::getSchemas();

        if (!empty($schemas[$segment_id])) {
            return $schemas[$segment_id];
        }

        return [];
    }

    /**
     * Return the specified schema as an array.
     */
    public static function getSchema($segment_id, $position): array
    {
        $schemas = self::getSchemas();

        if (!empty($schemas[$segment_id][$position])) {
            return $schemas[$segment_id][$position];
        }

        return [];
    }

    /**
     * Returns the data element id of the element at the specified position in the specified segment.
     */
    public static function getDataElementId($segment_id, $position)
    {
        $schemas = self::getSchemas();

        if (!empty($schemas[$segment_id][$position]['data_element_id'])) {
            return $schemas[$segment_id][$position]['data_element_id'];
        }

        return null;
    }
}
