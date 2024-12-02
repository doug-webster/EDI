<?php
namespace GZMP\EDI\Core\Segments;

use Exception;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentB2A extends EDISegment
{
    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        if (empty($this->values[1])) {
            throw new Exception("Unspecified purpose");
        }
    }

    public function getPurpose()
    {
        return EDICodes::getDescriptionText('B2A', 1, $this->values[1]);
    }
}
