<?php

namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentAK3 extends EDISegment
{
    protected array $excludeIndexes = [1,2,3,4];

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        $message = "{$this->values[1]} Error";
        if (!empty($this->values[2])) {
            $message .= " ({$data->addOrdinalNumberSuffix($this->values[2])} segment";
            if (!empty($this->values[3])) {
                $message .= ", loop {$this->values[3]}";
            }
            $message .= ")";
        }
        $message .= ': ' . EDICodes::getDescriptionText('AK3', 4, $this->values[4]);
        $data->addKeyValue('Message', $message);
    }
}
