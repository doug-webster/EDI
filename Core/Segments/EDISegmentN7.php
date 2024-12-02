<?php
namespace GZMP\EDI\Core\Segments;

use GZMP\EDI\Core\Data\EDIData;

class EDISegmentN7 extends EDISegment
{
    protected array $excludeIndexes = [1,2,15];
    private string $number;
    private int $lengthFeet;
    private int $lengthInches;
    private string $lengthText = '';

    public function __construct(array $values = [])
    {
        parent::__construct($values);

        $this->number = (!empty($this->values[1])) ? "{$this->values[1]}{$this->values[2]}" : $values[2];
        $this->parseLength();
    }

    private function parseLength()
    {
        if (!isset($this->values[15]) || $this->values[15] === '') {
            return;
        }

        // The length may be 4 to 5 characters wide; the right 2 characters are inches; the left 2-3 characters are feet
        $this->lengthInches = (int)substr($this->values[15], -2);
        $this->lengthFeet = (int)substr($this->values[15], 0, -2);
        if (!empty($this->lengthFeet)) {
            $this->lengthText .= "{$this->lengthFeet}'";
        }
        if (!empty($this->lengthFeet) && !empty($this->lengthInches)) {
            $this->lengthText .= ' ';
        }
        if (!empty($this->lengthInches)) {
            $this->lengthText = $this->lengthInches . '"';
        }
    }

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        $data->addKeyValue('Number', $this->number ?? '');
        $data->addKeyValue('Length Feet', $this->lengthFeet ?? 0);
        $data->addKeyValue('Length Inches', $this->lengthInches ?? 0);
    }
}
