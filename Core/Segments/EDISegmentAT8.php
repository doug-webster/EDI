<?php
namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentAT8 extends EDISegment
{
    protected array $excludeIndexes = [1,2,3];

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        // See code for data element 187 for possible keys; all are weight related
        $key = EDICodes::getDescriptionText('AT8', 1, $this->values[1], false);
        $weight = $this->values[3];
        $weight .= ' ' . EDICodes::getDescriptionText('AT8', 2, $this->values[2]);
        $data->addKeyValue($key, $weight);
    }
}
