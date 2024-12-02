<?php
namespace GZMP\EDI\Core\Segments;

use Exception;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentG61 extends EDISegment
{
    protected array $excludeIndexes = [1,2,3,4,5];

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        if (empty($this->values[1])) {
            throw new Exception("Invalid G61 segment");
        }

        // The nested array is intentional; the goal is to create an array of contacts instead of ending up with
        // something like $contacts['name'] == ['name1','name2']
        $type = EDICodes::getDescriptionText('G61', 3, $this->values[3] ?? '', false);
        $values = [[
            'Type' => EDICodes::getDescriptionText('G61', 1, $this->values[1], false),
            'Name' => $this->values[2] ?? '',
            $type => $this->values[4] ?? '',
        ]];
        if (!empty($this->values[5])) {
            $values['Notes'] = $this->values[5];
        }

        $data->addKeyValue('Contacts', $values);
    }
}
