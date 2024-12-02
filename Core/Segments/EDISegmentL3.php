<?php
namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;

class EDISegmentL3 extends EDISegment
{
    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        $data->resetLoops();
    }
}
