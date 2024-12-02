<?php
namespace GZMP\EDI\Core\Segments;

use Exception;
use GZMP\EDI\Core\Data\EDIData;

class EDISegmentB2 extends EDISegment
{
    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        if (empty($this->values[4])) {
            throw new Exception("Missing reference number");
        }
    }
}
