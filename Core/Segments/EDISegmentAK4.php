<?php

namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentAK4 extends EDISegment
{
    protected array $excludeIndexes = [1,2,3,4];

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        $position = str_pad($this->values[1], 2, '0', STR_PAD_LEFT);
        $message = "Error at {$position}";
        if (!empty($this->values[2])) {
            $message .= " ({$this->values[2]})";
        }
        $errorMsg = EDICodes::getDescriptionText('AK4', 3, $this->values[3]);
        $message .= ": $errorMsg; value received: '{$this->values[4]}'";
        $data->addKeyValue('Message', $message);
    }
}
