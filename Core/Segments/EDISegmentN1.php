<?php
namespace GZMP\EDI\Core\Segments;

use Exception;
use GZMP\EDI\Core\Data\EDIData;

class EDISegmentN1 extends EDISegment
{
    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        if (empty($this->values[1]) && empty($this->values[2])) {
            throw new Exception("Invalid entity");
        }
    }
}
