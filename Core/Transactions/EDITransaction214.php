<?php
namespace GZMP\EDI\Core\Transactions;

use GZMP\EDI\Core\Metadata\EDICodes;
use GZMP\EDI\Core\Segments\EDISegment;

class EDITransaction214 extends EDITransaction
{
    public static function create(string $scac, $transaction_type_code = 214): EDITransaction214
    {
        return parent::create($scac, 214);
    }

    public function addSegmentB10(
        $carrier_load_id,
        $shipper_reference_number,
        $reference_id_qualifier = null,
        $reference_id = null,
        $time = null
    ) {
        $segment = array(
            'B10',
            $carrier_load_id,
            $shipper_reference_number,
            $this->scac,
        );

        if (!empty($reference_id_qualifier) && !empty($reference_id)) {
            $segment = array_pad($segment, 5, '');
            $segment[] = $reference_id_qualifier;
            $segment[] = $reference_id;
        }

        if (!empty($time)) {
            // Ensure that we're adding these values in the correct spot since the previous values may or may not have
            // been added.
            $segment = array_pad($segment, 8, '');
            $segment[] = date($this->date_format, $time);
            $segment[] = date($this->time_format, $time);
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentL11($reference_id, $reference_id_qualifier, $description = '')
    {
        if (empty($reference_id) || empty($reference_id_qualifier)) {
            return;
        }

        $segment = array(
            'L11',
            $reference_id,
            $reference_id_qualifier,
        );

        if (!empty($description)) {
            $segment[] = $description;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentAT7($status_code, $status_reason_code, $datetime, $time_zone_code = 'LT')
    {
        $segment = array(
            'AT7',
            $status_code,
            $status_reason_code,
            '',
            '',
            date($this->date_format, $datetime),
            date($this->time_format, $datetime),
            $time_zone_code,
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentMS1($city, $state, $country, $longitude = null, $latitude = null)
    {
        if (!empty($longitude) && !empty($latitude)) {
            $longitude = $this->formatLatitudeOrLongitude($longitude);
            $latitude = $this->formatLatitudeOrLongitude($latitude);
            $segment = array(
                'MS1',
                '',
                '',
                '',
                preg_replace('/[^0-9]/', '', $longitude),
                preg_replace('/[^0-9]/', '', $latitude),
                $longitude < 0 ? 'W' : 'E',
                $latitude < 0 ? 'S' : 'N',
            );
        } else {
            if (empty($city) || (empty($state) && empty($country))) {
                return;
            }
            $segment = array(
                'MS1',
                $city,
                $state,
                $country,
            );
        }

        $this->addSegment(EDISegment::create($segment));
    }

    protected function formatLatitudeOrLongitude($lat_or_lon, $degrees_num_digits = 3)
    {
        $lat_or_lon = self::DECtoDMS($lat_or_lon);
        if (is_array($lat_or_lon)) {
            $deg = str_pad(round(abs($lat_or_lon['deg'])), $degrees_num_digits, '0', STR_PAD_LEFT);
            $min = str_pad(round($lat_or_lon['min']), 2, '0');
            $sec = str_pad(round($lat_or_lon['sec']), 2, '0');
            return (($lat_or_lon['deg'] < 0) ? '-' : '') . $deg . $min . $sec;
        }

        return '';
    }

    protected static function DECtoDMS($dec)
    {
        // Converts decimal longitude / latitude to DMS
        // ( Degrees / minutes / seconds )

        // This is the piece of code which may appear to
        // be inefficient, but to avoid issues with floating
        // point math we extract the integer part and the float
        // part by using a string function.

        $vars = explode(".", $dec);
        $deg = $vars[0];
        $tempma = "0." . $vars[1];

        $tempma = $tempma * 3600;
        $min = floor($tempma / 60);
        $sec = $tempma - ($min * 60);

        return array("deg" => $deg, "min" => $min, "sec" => $sec);
    }

    public function addSegmentMS2($equipment_number, $equipment_description_code)
    {
        if (empty($equipment_number) || $equipment_description_code) {
            return;
        }

        $segment = array(
            'MS2',
            $this->scac,
            $equipment_number,
            $equipment_description_code,
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentAT8($weight_qualifier, $weight_unit_code, $weight, $quantity)
    {
        $segment = array(
            'AT8',
            $weight_qualifier,
            $weight_unit_code,
            $weight,
            $quantity,
        );

        $this->addSegment(EDISegment::create($segment));
    }

    /**
     * Returns the primary reference number for the EDI transaction. This is the number the customer/partner uses to
     * identify the load.
     */
    public function getCustomersReferenceNumber(): string
    {
        return $this->getDataValue('B10', 2) ?? '';
    }

    /**
     * Returns the shipper's load id.
     */
    public function getShippersReferenceNumber(): string
    {
        return $this->getDataValue('B10', 1) ?? '';
    }

    /**
     * Return text summarizing this transaction.
     */
    public function getSummaryText(): string
    {
        foreach ($this->segments as $segment) {
            if (strtoupper($segment->getId()) == 'AT7') {
                $summary = EDICodes::getDescriptionText('AT7', 1, $segment->getValueByIndex(1));
                $code2 = strtoupper($segment->getValueByIndex(2));
                if (!empty($code2) && $code2 != 'NS') {
                    $summary .= "\n" . EDICodes::getDescriptionText('AT7', 2, $segment->getValueByIndex(2));
                }
                $date = $segment->formatDate($segment->getValueByIndex(5), 'm/d/Y');
                $time = $segment->formatTime($segment->getValueByIndex(6));
                $summary .= " {$date} {$time} " . EDICodes::getDescriptionText('AT7', 7, $segment->getValueByIndex(7));
                return $summary;
            }
        }

        return '';
    }
}
