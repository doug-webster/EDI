<?php
namespace GZMP\EDI\Core\Transactions;

use GZMP\EDI\Core\Segments\EDISegment;

class EDITransaction210 extends EDITransaction
{
    public static function create(string $scac, $transaction_type_code = 210): EDITransaction210
    {
        return parent::create($scac, 210);
    }

    public function addSegmentB3(
        $shipment_qualifier,
        $carriers_load_id,
        $shipper_reference_number,
        $payment_method,
        $billing_datetime,
        $amount,
        $correction_indicator,
        $delivery_datetime,
        $datetime_qualifier,
        $pickup_datetime
    ) {
        $segment = array(
            'B3',
            $shipment_qualifier,
            $carriers_load_id,
            $shipper_reference_number,
            $payment_method,
            'L',
            date($this->date_format, $billing_datetime),
            $amount,
            $correction_indicator,
            date($this->date_format, $delivery_datetime),
            $datetime_qualifier,
            $this->scac,
            date($this->date_format, $pickup_datetime),
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentC3($currency = 'USD')
    {
        $segment = array(
            'C3',
            $currency,
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentG62($datetime, $date_qualifier, $time_qualifier = null)
    {
        if (empty($datetime) || $datetime <= 0) {
            return;
        }

        $segment = array(
            'G62',
            $date_qualifier,
            date($this->date_format, $datetime),
        );

        if (!empty($time_qualifier)) {
            $segment[] = $time_qualifier;
            $segment[] = date($this->time_format, $datetime);
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentR3(
        $routing_sequence_code,
        $transportation_method,
        $description = null,
        $invoice_number = null,
        $sevice_level_code_provided = null
    ) {
        $segment = array(
            'R3',
            $this->scac,
            $routing_sequence_code,
            '',
            $transportation_method,
        );

        if (!empty($invoice_number)) {
            $segment = array_pad($segment, 6, '');
            $segment[] = $invoice_number;
        }

        if (!empty($description)) {
            $segment = array_pad($segment, 9, '');
            $segment[] = $description;
        }

        if (!empty($sevice_level_code_provided)) {
            $segment = array_pad($segment, 10, '');
            $segment[] = $sevice_level_code_provided;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentN7(
        $equipment_number,
        $equipment_number_prefix = null,
        $equipment_type_code = null,
        $equipment_type = null,
        $equipment_length_feet = null,
        $equipment_length_inches = 0
    ) {
        $segment = array(
            'N7',
        );

        if (!empty($equipment_number_prefix)) {
            $segment[] = $equipment_number_prefix;
        }

        $segment = array_pad($segment, 2, '');
        $segment[] = $equipment_number;

        if (!empty($equipment_type_code)) {
            $segment = array_pad($segment, 11, '');
            $segment[] = $equipment_type_code;
        }

        if (!empty($equipment_length_feet) || !empty($equipment_length_inches)) {
            $segment = array_pad($segment, 15, '');
            $segment[] = $equipment_length_feet . $this->formatInches($equipment_length_inches);
        }

        if (!empty($equipment_type)) {
            $segment = array_pad($segment, 22, '');
            $segment[] = $equipment_type;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function formatInches(int $inches)
    {
        if ($inches > 11) {
            $inches = 11;
        }
        if ($inches < 0) {
            $inches = 0;
        }
        return str_pad((string)$inches, 2, 0, STR_PAD_LEFT);
    }

    public function addSegmentM7($seal_numbers)
    {
        if (empty($seal_numbers) || empty($seal_numbers[0])) {
            return;
        }

        $segment = array(
            'M7',
        );
        foreach ($seal_numbers as $seal_number) {
            $segment[] = $seal_number;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentSPO($po_number)
    {
        if (empty($po_number)) {
            return;
        }

        $segment = array(
            'SPO',
            $po_number,
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentS5(
        $stop_number,
        $stop_reason,
        $weight,
        $weight_qualifier,
        $volume = null,
        $volume_unit_qualifier = null
    ) {
        $segment = array(
            'S5',
            $stop_number,
            $stop_reason,
            $weight,
            $weight_qualifier,
        );

        if (!empty($volume) && !empty($volume_unit_qualifier)) {
            $segment[] = $volume;
            $segment[] = $volume_unit_qualifier;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentL5($line_item_number, $lading_description)
    {
        $segment = array(
            'L5',
            $line_item_number,
            $lading_description,
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentL0(
        $line_item_number,
        $billed_quantity,
        $billed_quantity_qualifier,
        $weight,
        $weight_qualifier,
        $quantity,
        $quantity_qualifier
    ) {
        $segment = array(
            'L0',
            $line_item_number,
            $billed_quantity,
            $billed_quantity_qualifier,
            $weight,
            $weight_qualifier,
            '',
            '',
            $quantity,
            $quantity_qualifier,
            '',
            'L',
        );

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentL1(
        $line_item_number,
        $rate,
        $rate_qualifier,
        $charge,
        $code = null,
        $description = '',
        $billed_quantity = 0,
        $billed_quantity_qualifier = ''
    ) {
        $segment = array(
            'L1',
            $line_item_number,
            $rate,
            $rate_qualifier,
            $charge,
        );

        if (!empty($code)) {
            $segment = array_pad($segment, 8, '');
            $segment[] = $code;
        }

        if (!empty($description)) {
            $segment = array_pad($segment, 12, '');
            $segment[] = $description;
        }

        if (!empty($billed_quantity)) {
            $segment = array_pad($segment, 17, '');
            $segment[] = $billed_quantity;
            if (!empty($billed_quantity_qualifier)) {
                $segment[] = $billed_quantity_qualifier;
            }
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentL7($line_item_number, $freight_class)
    {
        $segment = array(
            'L7',
            $line_item_number,
        );

        if (!empty($freight_class)) {
            $segment = array_pad($segment, 7, '');
            $segment[] = $freight_class;
        }

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentL3($weight, $weight_qualifier, $amount, $quantity, $weight_unit_code)
    {
        $segment = array(
            'L3',
            $weight,
            $weight_qualifier,
            '',
            '',
            $amount,
        );
        $segment = array_pad($segment, 11, '');
        $segment[] = $quantity;
        $segment[] = $weight_unit_code;

        $this->addSegment(EDISegment::create($segment));
    }

    public function addSegmentPOD($datetime, $name)
    {
        $segment = [
            'POD',
            date($this->date_format, $datetime),
            date($this->time_format, $datetime),
            $name,
        ];

        $this->addSegment(EDISegment::create($segment));
    }

    /**
     * Returns the primary reference number for the EDI transaction. This is the number the customer/partner uses to
     * identify the load.
     */
    public function getCustomersReferenceNumber(): string
    {
        return $this->getDataValue('B3', 3) ?? '';
    }

    /**
     * Returns the shipper's load id.
     */
    public function getShippersReferenceNumber(): string
    {
        return $this->getDataValue('B3', 2) ?? '';
    }
}
