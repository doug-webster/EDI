<?php
namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentNTE extends EDISegment
{
    protected array $excludeIndexes = [1,2];

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        $note = EDICodes::getDescriptionText('NTE', 1, $this->values[1]);
        $note .= ": {$this->values[2]}";

        $data->addKeyValue('Notes', $note);
    }
}
