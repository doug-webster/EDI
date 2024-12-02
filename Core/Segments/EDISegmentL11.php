<?php
namespace GZMP\EDI\Core\Segments;

use Exception;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentL11 extends EDISegment
{
    // Codes to l3.3 descriptions to exclude
    private array $excludes = [
        'BM' => ['BM', 'BOL', 'BOL NUMBER', 'Load ID'],
        'CR' => ['Customer Load ID'],
        'MH' => ['JOHN DEERE #'],
        'PO' => ['PO Number'],
        'Q1' => ['PSL QUOTE #'],
        'SE' => ['JOHN DEERE SERIAL #'],
        'KD' => ['NULL'],
    ];

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        if (!isset($this->values[1]) || $this->values[1] === '') {
            throw new Exception("Invalid L11 segment: " . implode('*', $this->values));
        }

        $value = $this->values[1];
        $code = $this->values[2] ?? null;
        $description = $this->values[3] ?? null;

        $codeDescription = ($code) ? EDICodes::getDescriptionText('L11', 2, $code, false) : null;

        if (!empty($description) && !is_numeric($description)
            && (!$codeDescription || $codeDescription !== $description)
            && (!array_key_exists($code, $this->excludes) || !in_array($description, $this->excludes[$code]))
        ) {
            $data->addKeyValue($description, $value);
            $this->excludeIndexes[] = 1;
            $this->excludeIndexes[] = 3;
        } elseif (isset($this->values[2]) && $this->values[2] !== '') {
            $data->addKeyValue($codeDescription, $value);
            $this->excludeIndexes[] = 1;
            $this->excludeIndexes[] = 2;
        }
    }
}
