<?php
namespace GZMP\EDI\Core;

use DateTime;
use DateTimeZone;
use Exception;
use GZMP\EDI\Core\Metadata\EDIDataElements;
use GZMP\EDI\Core\Metadata\EDISchemas;
use GZMP\EDI\Core\Segments\EDISegment;

/**
 * A class representing an Electronic Document Interchange (EDI) file.
 */
class EDInterchange extends EDIBase
{
    protected string $data_delimiter = '*';
    protected string $authorization_info_qualifier = '00';
    protected string $authorization_info = '          ';
    protected string $security_info_qualifier = '00';
    protected string $security_info = '          ';
    protected string $sender_id_qualifier = 'ZZ';
    protected string $sender_id;
    protected string $receiver_id_qualifier = 'ZZ';
    protected string $receiver_id;
    protected string $edi_standard_code = 'U';
    protected string $repetition_separator = '|';
    protected int $acknowledgment_requested = 1;
    protected string $usage = 'P'; // P/T for Production or Testing
    protected string $component_terminator = '>';
    protected string $segment_terminator = '~';
    protected string $date_format = 'ymd';
    private array $groups = []; // one or more groups

    public function __construct($properties = null)
    {
        parent::__construct($properties);

        // Ensure that these properties have a valid value.
        if (empty($this->component_terminator)) {
            $this->resetPropertyToDefault('component_terminator');
        }
        if (empty($this->segment_terminator)) {
            $this->resetPropertyToDefault('segment_terminator');
        }
        if (empty($this->data_delimiter)) {
            $this->resetPropertyToDefault('data_delimiter');
        }
    }

    protected function resetPropertyToDefault(string $property)
    {
        $this->$property = get_class_vars(__CLASS__)[$property];
    }

    /**
     * Return an array of the interchange's groups as objects.
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Return an array of segments representing this interchange as either objects or arrays.
     */
    public function getSegments(bool $segmentsAsArray = false): array
    {
        $segments = [];
        foreach ($this->groups as $group) {
            // append group segments to previous group(s) (if any)
            $segments = array_merge($segments, $group->getSegments($segmentsAsArray));
        }

        if (empty($segments)) {
            return [];
        }

        // add interchange header segment to beginning of array
        array_unshift($segments, $this->generateHeader());
        // add interchange footer segment to end of array
        $segments[] = $this->generateFooter();

        if ($segmentsAsArray) {
            foreach ($segments as $i => $segment) {
                if ($segment instanceof EDISegment) {
                    $segment->conformValuesToRequirements();
                    // Prevent a segment with no values
                    if (count($segment->getValues()) > 1) {
                        $segments[$i] = $segment->getValues();
                    }
                }
            }
        }

        return $segments;
    }

    /**
     * Return interchange's sender id.
     */
    public function getSender()
    {
        return $this->sender_id;
    }

    /**
     * Return interchange's receiver id.
     */
    public function getReceiver()
    {
        return $this->receiver_id;
    }

    /**
     * Returns true if the interchange header requests an acknowledgement, false otherwise.
     */
    public function acknowledgmentRequested(): bool
    {
        return (bool)$this->acknowledgment_requested;
    }

    /**
     * Return an EDISegment object representing this interchange's header.
     */
    public function generateHeader(): EDISegment
    {
        return new EDISegment([
            'ISA',
            $this->authorization_info_qualifier,
            $this->authorization_info,
            $this->security_info_qualifier,
            $this->security_info,
            $this->sender_id_qualifier,
            $this->sender_id,
            $this->receiver_id_qualifier,
            $this->receiver_id,
            $this->date,
            $this->time,
            (((int)$this->x12_version > 4010) ? $this->repetition_separator : $this->edi_standard_code),
            $this->x12_version,
            $this->control_number,
            $this->acknowledgment_requested,
            $this->usage,
            $this->component_terminator,
        ]);
    }

    /**
     * Return an EDISegment object representing this interchange's footer.
     */
    public function generateFooter(): EDISegment
    {
        return new EDISegment([
            'IEA',
            count($this->groups),
            $this->control_number,
        ]);
    }

    /**
     * Return a count of the number of transactions in this interchange.
     */
    public function countTransactions()
    {
        $transactionCount = 0;
        foreach ($this->getGroups() as $group) {
            $transactionCount += $group->countTransactions();
        }
        return $transactionCount;
    }

    public function getWarnings()
    {
        $warnings = $this->warnings;

        foreach ($this->getGroups() as $group) {
            $warnings = array_merge($warnings, $group->getWarnings());
        }

        return $warnings;
    }

    /**
     * Magic method which converts this interchange object into corresponding EDI text and returns it.
     */
    public function __toString()
    {
        $segments = $this->getSegments(true);
        if (empty($segments)) {
            return '';
        }
        array_walk_recursive($segments, array($this, 'sanitizeData'));
        foreach ($segments as $i => $segment) {
            foreach ($segment as $j => $value) {
                $dataType = EDIDataElements::getDataType(EDISchemas::getDataElementId($segment[0], $i));
                if ($dataType == 'CP' && is_array($value)) {
                    $segments[$i][$j] = implode($this->component_terminator, $value) . $this->component_terminator;
                }
            }
            $segments[$i] = implode($this->data_delimiter, $segment);
        }
        return implode($this->segment_terminator, $segments) . $this->segment_terminator;
    }

    /**
     * This prevents EDI errors by removing any delimiters present in the data values.
     */
    private function sanitizeData(&$value)
    {
        $search = array(
            $this->data_delimiter,
            $this->segment_terminator,
        );
        $value = str_replace($search, '', $value);
    }

    /**
     * Attempt to convert a raw EDI text sting into an EDInterchange object with corresponding sub-objects.
     */
    public function parse($edi_string)
    {
        if (substr($edi_string, 0, 3) != 'ISA') {
            throw new Exception('Invalid EDI.');
        }

        $this->data_delimiter = $this->determineDataDelimiter($edi_string);
        $this->segment_terminator = $this->determineSegmentTerminator($edi_string);

        // trim a trailing segment terminator if any so that a blank segment won't be generated
        $edi_string = rtrim($edi_string, $this->segment_terminator);

        // convert EDI message from a string into multi-dimensional array
        // Separate segments
        $segments = explode($this->segment_terminator, $edi_string);
        // Separate values within a segment
        foreach ($segments as $i => $segment) {
            $segments[$i] = explode($this->data_delimiter, $segment);
            // Separate components in a component value
            foreach ($segments[$i] as $j => $value) {
                $dataType = EDIDataElements::getDataType(EDISchemas::getDataElementId($segment[0], $i));
                if ($dataType == 'CP' && str_contains($value, $this->component_terminator)) {
                    $segments[$i][$j] = explode($this->component_terminator, $value);
                }
            }
        }

        $this->parseHeader(array_shift($segments));
        $this->parseGroups($segments);
        $this->parseFooter(array_pop($segments));
    }

    /**
     * Determine and return the data delimiter for the given EDI string.
     */
    private function determineDataDelimiter(string $edi_string): string
    {
        return substr($edi_string, 3, 1);
    }

    /**
     * Attempt to determine and return the segment terminator for the given EDI string.
     */
    private function determineSegmentTerminator(string $edi_string): string
    {
        // the ISA header line should have fixed column widths; the segment delimiter should be 1-3 characters between character 105 and the "GS" text
        $segment_terminator_start = 105; // the 0 indexed character number where the segment delimiter should start
        $GS_start = strpos($edi_string, 'GS', $segment_terminator_start);
        if ($GS_start !== false) {
            $length = $GS_start - $segment_terminator_start;
            $segment_terminator = substr($edi_string, $segment_terminator_start, $length);
            if (EDInterchange::validateSegmentDelimiter($segment_terminator)) {
                return $segment_terminator;
            }
        }

        // apparently some EDI messages are not well formed, so if the above didn't work, we'll try another way
        $this->warnings[] = 'ISA header apparently malformed.';
        $pieces = explode($this->data_delimiter, $edi_string);
        if (isset($pieces[16])) {
            if (substr($pieces[16], -2) == 'GS') {
                $segment_terminator = substr($pieces[16], 1, strlen($pieces[16]) - 3); // 3 because the composite delimiter should be 1 character and 2 for "GS"
            }
        }
        if (EDInterchange::validateSegmentDelimiter($segment_terminator)) {
            return $segment_terminator;
        }

        throw new Exception('Could not determine the segment delimiter; EDI probably malformed.');
    }

    /**
     * Determine if the given segment delimiter is valid.
     */
    public static function validateSegmentDelimiter(string $delimiter): bool
    {
        // the segment delimiter should be 1 character with an optional return/new line, so potentially up to 3 characters
        // new line characters are themselves valid delimiters
        if (in_array($delimiter, array("\r", "\n", "\r\n"))) {
            return true;
        }
        if (strlen($delimiter) <= 3 && strlen(trim($delimiter)) == 1) {
            return true;
        }
        return false;
    }

    /**
     * Loop through an array of segments as arrays, create corresponding groups, and process each group.
     */
    private function parseGroups(array $segments): void
    {
        $skipToNextGroup = false;

        foreach ($segments as $segment) {
            $segment[0] = strtoupper($segment[0]);

            if ($skipToNextGroup && $segment[0] != 'GS') {
                continue;
            }

            switch ($segment[0]) {
                case 'GS': // Group header
                    $skipToNextGroup = false;
                    $group = EDIGroup::create();
                    try {
                        $group->parseHeader($segment);
                    } catch (Exception $exception) {
                        $this->warnings[] = $exception->getMessage();
                        $group->setAcceptance('R');
                        $this->addGroup($group);
                        unset($group, $groupSegments);
                        $skipToNextGroup = true;
                    }
                    $groupSegments = [];
                    break;
                case 'GE': // Group footer
                    $group->parseTransactions($groupSegments);
                    try {
                        $group->parseFooter($segment);
                    } catch (Exception $exception) {
                        $this->warnings[] = $exception->getMessage();
                    }
                    $group->setAcceptance();
                    $this->addGroup($group);
                    unset($group, $groupSegments);
                    break;
                case 'IEA': // Interchange footer
                    // Once we reach the interchange footer, we're done parsing groups so jump out of the switch and
                    // foreach loop.
                    break 2;
                default:
                    if (!isset($group)) {
                        break;
                    }
                    $groupSegments[] = $segment;
            } // end switch
        } // end loop for each segment
    }

    /**
     * Add an EDIGroup object to the array of this interchange's groups.
     */
    public function addGroup(EDIGroup $group)
    {
        if ($group->countTransactions()) {
            $this->groups[] = $group;
        }
    }

    /**
     * Convert an array representing the interchange header into property values
     */
    public function parseHeader(array $segment): void
    {
        if (count($segment) < 17) {
            throw new Exception('Header does not contain enough data.');
        }
        // 0 should = ISA
        $this->authorization_info_qualifier = $segment[1];
        $this->authorization_info           = trim($segment[2]);
        $this->security_info_qualifier      = $segment[3];
        $this->security_info                = trim($segment[4]);
        $this->sender_id_qualifier          = $segment[5];
        $this->sender_id                    = trim($segment[6]);
        $this->receiver_id_qualifier        = $segment[7];
        $this->receiver_id                  = trim($segment[8]);
        $this->date                         = $segment[9];
        $this->time                         = $segment[10];
        $this->x12_version                  = $segment[12];
        if ((int)$this->x12_version > 401) {
            $this->repetition_separator = $segment[11];
        } else {
            $this->edi_standard_code = $segment[11];
        }
        $this->control_number               = $segment[13];
        $this->acknowledgment_requested     = $segment[14];
        $this->usage                        = $segment[15];
        $this->component_terminator         = $segment[16];
    }

    /**
     * Use the interchange footer to check the validity of the EDI.
     */
    public function parseFooter(array $segment): void
    {
        if (count($segment) < 3) {
            throw new Exception('Footer does not contain enough data.');
        }
        // 0 should = IEA
        $num_groups = count($this->groups);
        if ($segment[1] != $num_groups) {
            $this->warnings[] = "Warning: IEA expects {$segment[1]} number of groups, but {$num_groups} counted in interchange.";
        }
        if ($segment[2] != $this->control_number) {
            $this->warnings[] = "Control number {$this->control_number} expected but {$segment[2]} found.";
        }
    }

    /*
     * Return the type code of the first group
     */
    public function getTypeCode(): string
    {
        $groups = $this->getGroups();
        if (!isset($groups[0]) || !$groups[0] instanceof EDIGroup) {
            throw new Exception("No valid group.");
        }

        return $groups[0]->getTransactionTypeCode();
    }

    public function getDateTime(string $format = '', DateTimeZone $timeZone = null, DateTimeZone $returnTimeZone = null)
    {
        $segment = new EDISegment();
        // $date = $segment->formatDate($this->date);
        // $time = $segment->formatTime($this->time);
        if (!$timeZone) {
            $timeZone = new DateTimeZone(date_default_timezone_get());
        }
        $dateTime = new DateTime('now', $timeZone);

        $date = $segment->parseDate($this->date);
        $dateTime->setDate($date['year'], $date['month'], $date['day']);

        $time = $segment->parseTime($this->time);
        $dateTime->setTime($time['hours'], $time['minutes'], $time['seconds']);

        if (!$returnTimeZone) {
            $returnTimeZone = new DateTimeZone(date_default_timezone_get());
        }
        $dateTime->setTimezone($returnTimeZone);

        return ($format) ? $dateTime->format($format) : $dateTime;
    }
}

// Polyfill since str_contains doesn't exist until PHP 8
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}
