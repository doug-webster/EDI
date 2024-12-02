<?php

namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentAK9 extends EDISegment
{
    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        $data->resetLoops();

        $groupAcknowledgementCode = EDICodes::getDescriptionText('AK9', 1, $this->values[1]);
        $data->addKeyValue('Group Acknowledgement', $groupAcknowledgementCode);

        $data->addKeyValue('Transaction Count', $this->values[2]);
        $data->addKeyValue('Received Transaction Count', $this->values[3]);
        $data->addKeyValue('Accepted Transaction Count', $this->values[4]);
    }
}
