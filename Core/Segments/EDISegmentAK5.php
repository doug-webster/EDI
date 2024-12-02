<?php

namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;

class EDISegmentAK5 extends EDISegment
{
    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        $data->setLoop(100);
    }
}
