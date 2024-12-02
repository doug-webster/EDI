<?php
namespace GZMP\EDI\Core\Segments;

use DateTimeZone;
use Exception;
use Logger;
use GZMP\EDI\Core\Data\EDIData;
use GZMP\EDI\Core\Metadata\EDICodes;
use GZMP\EDI\Core\Metadata\EDIDataElements;
use GZMP\EDI\Core\Metadata\EDISchemas;

class EDISegment
{
    protected const TIMEZONE_CONVERSION = [
        'UT' => 'UTC', // 'Universal Time Coordinate',
        'GM' => 'GMT', // 'Greenwich Mean Time',
        'NT' => 'America/St_Johns', // 'Newfoundland Time',
        'TT' => 'America/Halifax', // 'Atlantic Time',
        'ET' => 'America/New_York', // 'Eastern Time',
        'CT' => 'America/Chicago', // 'Central Time',
        'MT' => 'America/Denver', // 'Mountain Time',
        'PT' => 'America/Los_Angeles', // 'Pacific Time',
        'AT' => 'America/Anchorage', // 'Alaska Time',
        'HT' => 'Pacific/Honolulu', // 'Hawaii-Aleutian Time',
    ];

    protected const TIMEZONE_OFFSETS = [
        'ND' => '-02:30', // 'Newfoundland Daylight Time',
        'NS' => '-03:30', // 'Newfoundland Standard Time',

        'TD' => '-03:00', // 'Atlantic Daylight Time',
        'TS' => '-04:00', // 'Atlantic Standard Time',

        'ED' => '-04:00', // 'Eastern Daylight Time',
        'ES' => '-05:00', // 'Eastern Standard Time',

        'CD' => '-05:00', // 'Central Daylight Time',
        'CS' => '-06:00', // 'Central Standard Time',

        'MD' => '-06:00', // 'Mountain Daylight Time',
        'MS' => '-07:00', // 'Mountain Standard Time',

        'PD' => '-07:00', // 'Pacific Daylight Time',
        'PS' => '-08:00', // 'Pacific Standard Time',

        'AD' => '-08:00', // 'Alaska Daylight Time',
        'AS' => '-09:00', // 'Alaska Standard Time',

        'HD' => '-09:00', // 'Hawaii-Aleutian Daylight Time',
        'HS' => '-10:00', // 'Hawaii-Aleutian Standard Time',
    ];

    /**
     * An array of the segment's values.
     */
    protected array $values = [];

    /**
     * An array of indexes of values to exclude from automatic conversion into EDIData.
     */
    protected array $excludeIndexes = [];

    /**
     * An array of AK3 error codes for errors found in this segment.
     */
    protected array $errorCodes = [];

    /**
     * An array of AK4 errors for errors found in this segment's values.
     */
    protected array $elementErrors = [];

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /**
     * Create and return an EDISegment or child object.
     */
    public static function create(array $values = [], bool $conform = true): EDISegment
    {
        $segmentId = $values[0] ?? '';

        $className = (class_exists("GZMP\EDI\Core\Segments\EDISegment{$segmentId}"))
            ? "GZMP\EDI\Core\Segments\EDISegment{$segmentId}" : 'GZMP\EDI\Core\Segments\EDISegment';
        $segment = new $className($values);

        if ($conform) {
            $segment->conformValuesToRequirements();
            // TODO: once we can specify union types, potentially return null if there are no values in the segment
        }

        return $segment;
    }

    /**
     * Return a date formatted as specified.
     */
    public function formatDate(string $date, string $format = 'Y-m-d'): string
    {
        if (empty($date)) {
            return '';
        }

        $date = $this->parseDate($date);
        return date($format, strtotime("{$date['year']}-{$date['month']}-{$date['day']}"));
    }

    /**
     * Parses in EDI date value into an array containing the year, month, and day.
     */
    public function parseDate(string $date): array
    {
        if (strlen($date) == 8) {
            $year = substr($date, 0, 4);
        } else {
            $year = '20' . substr($date, 0, 2);
        }
        return [
            'year' => $year,
            'month' => substr($date, -4, 2),
            'day' => substr($date, -2, 2),
        ];
    }

    /**
     * Return a time formatted as specified.
     */
    public function formatTime(string $time): string
    {
        if (empty($time)) {
            return '';
        }

        $time = $this->parseTime($time);
        return "{$time['hours']}:{$time['minutes']}" . (($time['seconds'] !== '00') ? ":{$time['seconds']}" : '');
    }

    /**
     * Parses in EDI time value into an array containing the hours, minutes, and seconds.
     */
    public function parseTime(string $time): array
    {
        return [
            'hours' => substr($time, 0, 2),
            'minutes' => substr($time, 2, 2),
            'seconds' => (strlen($time) > 4) ? substr($time, 4, 2) : '00',
        ];
    }

    /**
     * Attempt to determine the proper time zone for the given code.
     */
    protected function convertTimeZoneCode(string $timeZoneCode): DateTimeZone
    {
        if (is_numeric($timeZoneCode)) {
            if ($timeZoneCode <= 12) {
                return new DateTimeZone("+$timeZoneCode:00");
            } elseif ($timeZoneCode <= 24) {
                return new DateTimeZone('-' . str_pad(abs((int)$timeZoneCode - 25), 2, '0') . ':00');
            }
        }

        $timeZoneCode = strtoupper($timeZoneCode);

        // LT = Local Time - effectively no time zone (or would have to lookup based on location)
        if ($timeZoneCode == 'LT' || empty($timeZoneCode)) {
            return new DateTimeZone(date_default_timezone_get());
        }

        if (array_key_exists($timeZoneCode, EDISegment::TIMEZONE_OFFSETS)) {
            return new DateTimeZone(EDISegment::TIMEZONE_OFFSETS[$timeZoneCode]);
        } elseif (array_key_exists($timeZoneCode, EDISegment::TIMEZONE_CONVERSION)) {
            try {
                return new DateTimeZone(EDISegment::TIMEZONE_CONVERSION[$timeZoneCode]);
            } catch (Exception $exception) {
                Logger::warning("EDI time zone code error: $timeZoneCode.");
            }
        } else {
            Logger::warning("Unknown EDI time zone code: $timeZoneCode.");
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Return the segment's id/type code.
     */
    public function getId(): string
    {
        return $this->values[0] ?? '';
    }

    /**
     * Allows segments to handle how their values are translated into the data object.
     */
    public function addSegmentValuesToEDIData(EDIData &$data)
    {
    }

    /**
     * Return an array of this segment's values with codes replaced by descriptions and keyed by the data element's
     * description.
     */
    public function getParsedValues(): array
    {
        $values = [];
        foreach ($this->values as $i => $value) {
            if ($i == 0 || in_array($i, $this->excludeIndexes) || trim($value) == '') {
                continue;
            }

            $key = EDISchemas::getName($this->getId(), $i);
            $value = $this->getFormattedValue($this->getId(), $i, $value);
            // Add key-value pair to the values array.
            EDIData::addValueToArray($key, $value, $values);
        }

        return $values;
    }

    /**
     * Return an array of this segment's values.
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Return the value of the data at the specified index.
     */
    public function getValueByIndex(int $i)
    {
        return $this->values[$i] ?? null;
    }

    /**
     * Return HTML representing this segment.
     */
    public function getHTML(array $values = null, string $segment_id = null, int $data_index = null): string
    {
        // The $segment_id and $data_index are to allow this function to also be used for returning HTML for components
        $html = "<table>\n";

        if (empty($values)) {
            $values = $this->values;
        }

        if (empty($segment_id)) {
            $segment_id = array_shift($values);
            $caption = "$segment_id - " . EDISchemas::getName($segment_id, 0);
            $html .= "<caption>{$caption}</caption>\n";
        }
        $header_cells = array();//"<th>Position</th>\n"
        $row1_cells = array();//"<td>Name</td>\n"
        $row2_cells = array();//"<td>Value</td>\n"

        for ($i = 1; $i <= count($values); $i++) {
            // Negative numbers are used for components; if the segment index is 4, then the first component will be -401
            $index = (is_null($data_index)) ? $i : 0 - (($data_index * 100) + $i);
            $pos = str_pad($i, 2, '0', STR_PAD_LEFT);
            $header_cells[] = (is_null($data_index)) ? "<th>{$segment_id}.{$pos}</th>\n" : "<th>{$segment_id}.{$data_index}-{$pos}</th>\n";

            $row1_cells[] = "<td>" . EDISchemas::getName($segment_id, $index) . "</td>\n";

            // subtract 1 due to 0 index
            $value = $this->getFormattedValue($segment_id, $index, $values[$i - 1], true, $dataType);
            $value = ($dataType != 'CP') ? htmlspecialchars($value) : $value;
            $row2_cells[] = "<td>{$value}</td>\n";
        } // End loop for each data element

        $html .= '<tr>' . implode('', $header_cells) . "</tr>\n";
        $html .= '<tr>' . implode('', $row1_cells) . "</tr>\n";
        $html .= '<tr>' . implode('', $row2_cells) . "</tr>\n";
        $html .= "</table>\n";

        return $html;
    }

    protected function getFormattedValue($segment_id, $index, $value, $include_code = true, &$dataType = '')
    {
        $value = EDICodes::getDescriptionText($segment_id, $index, $value);

        $data_element_id = EDISchemas::getDataElementId($segment_id, $index);
        $dataType = EDIDataElements::getDataType($data_element_id);

        switch ($dataType) {
            case 'DT':
                $value = $this->formatDate($value);
                break;
            case 'TM':
                $value = $this->formatTime($value);
                break;
            case 'CP':
                if (!is_array($value)) {
                    $value = [$value];
                }

                $values = [];
                foreach ($value as $j => $v) {
                    // for example, if the CP is the 4th element, then the index for first item within
                    // the CP = -401.
                    $k = (0 - ($index * 100)) - ($j + 1);
                    $values[] = $this->getFormattedValue($segment_id, $k, $v, $include_code);
                }

                $value = implode("\n", $values);
                break;
        } // end switch

        // Nx = a number with x implied decimal places (but w/o a decimal separator)
        if (preg_match('/^N([0-9])$/', $dataType, $matches) && !empty($matches[1])) {
            $offset = 0 - $matches[1];
            $value = substr($value, 0, $offset) . '.' . substr($value, $offset);
        }

        return $value;
    }

    /**
     * Ensure that this segment's values conform to corresponding segment requirements.
     */
    public function conformValuesToRequirements(): void
    {
        foreach ($this->values as $i => $value) {
            $dataElementId = EDISchemas::getDataElementId($this->getId(), $i);
            $this->values[$i] = $this->conformValueToRequirements($dataElementId, $value);
        }
        $this->trim();
    }

    /**
     * Ensure that the given value conforms to the requirements of the corresponding data element.
     */
    protected function conformValueToRequirements($data_element_id, $value)
    {
        // ISA (and composite elements) are the only elements using negative ids.
        // ISA is the only segment which requires fixed width values even when values are empty.
        // For all other segments, empty values are allowed and minimum width only applies for non-empty values.
        if (trim($value) === '' && $data_element_id > 0) {
            return '';
        }

        $element = EDIDataElements::getElement($data_element_id);
        if (empty($element)) {
            return $value;
        }

        $min_width = (isset($element['min_width'])) ? (int)$element['min_width'] : 0;
        $max_width = (isset($element['max_width'])) ? (int)$element['max_width'] : 99999;

        // Nx = a number with x implied decimal places (but w/o a decimal separator)
        if (preg_match('/^N([0-9])$/', $element['data_type'], $matches) && isset($matches[1])) {
            return $this->conformImpliedDecimalValue($value, $min_width, $max_width, $matches[1]);
        }

        // R = decimal number
        if (trim($element['data_type']) == 'R') {
            return $this->conformDecimalValue($value, $min_width, $max_width);
        }

        return str_pad(substr($value, 0, $max_width), $min_width, ' ');
    }

    protected function conformImpliedDecimalValue($value, int $min_width, int $max_width, int $decimal_req_width)
    {
        $parts = explode('.', (string)$value);
        $whole = $parts[0] ?: '';
        $decimal = $parts[1] ?: '';

        // First, get the decimal portion to the correct width
        if (strlen($decimal) < $decimal_req_width) {
            $decimal = str_pad($decimal, $decimal_req_width, '0', STR_PAD_RIGHT);
        }
        if (strlen($decimal) > $decimal_req_width) {
            $decimal = substr($decimal, 0, $decimal_req_width);
        }

        $value = "{$whole}{$decimal}";

        if (strlen($value) < $min_width) {
            return str_pad($value, $min_width, '0', STR_PAD_LEFT);
        }
        if (strlen($value) >= $max_width) {
            // It's not clear how we could automatically fix a value which is too large.
        }

        return $value;
    }

    protected function conformDecimalValue($value, int $min_width, int $max_width)
    {
        $str_value = (string)$value;
        $parts = explode('.', $str_value);

        if (strlen($parts[0]) > $max_width) {
            // Error: It's not clear how we could automatically fix a value which is too large.
            return $value;
        }

        $width = strlen(preg_replace('/[^0-9]/', '', $str_value));

        if ($width > $max_width) {
            $decimal_places = $max_width - $parts[0];
            $value = rtrim(number_format($value, $decimal_places, '.', ''), '0');
            // Don't combine with the above so that significant 0 to the left of the decimal place aren't removed.
            $value = rtrim($value, '.');
            $width = strlen(preg_replace('/[^0-9]/', '', $value));
        }

        if ($width < $min_width) {
            return str_pad($value, $min_width, '0', STR_PAD_LEFT);
        }

        return (string)$value;
    }

    /**
     * Removes empty elements from end of segment
     */
    protected function trim(): void
    {
        $elements = count($this->values);
        for ($i = $elements - 1; $i > 0; $i--) {
            if (!is_numeric($this->values[$i]) && trim($this->values[$i]) === '') {
                array_pop($this->values);
            } else {
                break;
            }
        }
    }

    public function validate()
    {
        // 1 Unrecognized segment ID
        if (!in_array($this->getId(), EDISchemas::getAllSegmentIds())) {
            $this->addErrorCode(1);
        }

        // Check that all required elements are present
        $schemas = EDISchemas::getSegmentSchemas($this->getId());
        foreach ($schemas as $schema) {
            $value = $this->values[$schema['data_index']] ?? null;
            if ($schema['required'] == 'M' && empty($value) && !is_numeric($value)) {
                $this->addElementError(1, $schema['data_index'], $value);
            }
            // 2  Conditional required data element missing - we don't have a good way of checking this here
        }

        foreach ($this->values as $i => $value) {
            if (!empty($value) || is_numeric($value)) {
                $this->validateValue($i, $value);
            }
        }

        // 8 Segment Has Data Element Errors
        if (!empty($this->elementErrors)) {
            $this->addErrorCode(8);
        }
    }

    /**
     * Validate that the given value conforms to the requirements of the corresponding data element.
     */
    protected function validateValue(int $index, $value): void
    {
        $schema = EDISchemas::getSchema($this->getId(), $index);
        // 3  Too many data elements
        if (empty($schema)) {
            $this->addElementError(3, $index, $value);
            return;
        }

        $element = EDIDataElements::getElement($schema['data_element_id']);
        if (empty($element)) {
            return;
        }

        // 4  Data element too short
        // 5  Data element too long
        $min_width = (!empty($element['min_width'])) ? $element['min_width'] : 0;
        $max_width = (!empty($element['max_width'])) ? $element['max_width'] : strlen($value);

        $width = strlen($value);
        if ($width < $min_width) {
            $this->addElementError(4, $index, $value);
        }
        if ($width > $max_width) {
            $this->addElementError(5, $index, $value);
        }

        switch ($element['data_type']) {
            case 'AN': // String
                // 6  Invalid character in data element - for now at least we'll accept any characters
                break;
            case 'CP': // Array
                // Ignore this case for now since validation will be a bit tricky to implement.
                break;
            case 'ID': // Code
                $codes = EDICodes::getCodesForDataElement($element['id']);
                // Not all ID fields have codes in the database; if there are no codes, skip this validation.
                if (!empty($codes) && !array_key_exists($value, $codes)) {
                    $this->addElementError(7, $index, $value);
                }
                break;
            case 'R': // Decimal (real number)
                // R data can only contain digits and an optional decimal place
                if (preg_match('/[^0-9.]/', $value)) {
                    $this->addElementError(6, $index, $value);
                }
                break;
            case 'DT': // Date - should be either 6 or 8 digits
                extract($this->parseDate($value));
                if (!preg_match('/^[0-9]{6}|[0-9]{8}$/', $value) || !checkdate($month, $day, $year)) {
                    $this->addElementError(8, $index, $value);
                }
                break;
            case 'TM': // Time - should be either 4, 6 or 8 digits
                extract($this->parseTime($value));
                if (!preg_match('/^[0-9]{4}|[0-9]{6}|[0-9]{8}$/', $value)
                    || $hour > 23 || $minutes > 59 || $seconds > 59
                ) {
                    $this->addElementError(9, $index, $value);
                }
                break;
            default:
                // Nx = a number with x implied decimal places (but w/o a decimal separator)
                if (preg_match('/^N[0-9]$/', $element['data_type']) && preg_match('/[^0-9]/', $value)) {
                    $this->addElementError(6, $index, $value);
                }
                break;
        }

        // 10 Exclusion Condition Violated - we don't have a good way to check this as of now
    }

    public function addErrorCode(int $code)
    {
        $this->errorCodes[] = $code;
    }

    public function addElementError(int $code, int $position, $value)
    {
        $this->elementErrors[] = [
            'position' => $position,
            'errorCode' => $code,
            'value' => $value,
        ];
    }

    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }

    public function getElementErrors(): array
    {
        return $this->elementErrors;
    }
}
