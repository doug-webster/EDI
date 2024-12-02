<?php
namespace GZMP\EDI\Core\Segments;

use DateTime;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;

class EDISegmentG62 extends EDISegment
{
    protected array $excludeIndexes = [1,2,3,4,5];
    protected DateTime $datetime;

    public function __construct(array $values = [])
    {
        parent::__construct($values);

        $dateTime = new DateTime();

        // Set time zone first because it will alter the time.
        $dateTime->setTimezone($this->convertTimeZoneCode($values[5] ?? ''));
        $this->datetime = $dateTime;

        if (isset($values[2])) {
            $date = $this->parseDate($values[2]);
            $dateTime->setDate($date['year'], $date['month'], $date['day']);
        }

        $time = $this->parseTime($values[4] ?? '0000');
        $dateTime->setTime($time['hours'], $time['minutes'], $time['seconds']);
    }

    public function addSegmentValuesToEDIData(EDIData &$data)
    {
        // See codes for data element 432 for possible keys; all specify meaning of date
        $key = EDICodes::getDescriptionText('G62', 1, $this->values[1]);
        $data->addKeyValue($key, $this->datetime);
    }
}
