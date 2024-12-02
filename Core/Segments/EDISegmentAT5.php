<?php
namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentAT5 extends EDISegment
{
    protected array $excludeIndexes = [1,2,3];

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        $value = [];
        $value[] = EDICodes::getDescriptionText('AT5', 1, $this->values[1]);
        $value[] = EDICodes::getDescriptionText('AT5', 2, $this->values[2]);
        $value[] = $this->values[3];
        $value = array_filter($value);

        $data->addKeyValue('Notes', implode('; ', $value));
    }
}
