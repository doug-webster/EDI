<?php

namespace GZMP\EDI\Core\Data;

class EDILoadTenderData extends EDIData
{
    protected array $loopHierarchy = [
        100 => [],
        200 => [],
        300 => [
            310 => [],
            320 => [
                325 => [
                    330 => [],
                ],
            ],
            350 => [
                360 => [
                    365 => [
                        370 => [],
                    ],
                ],
            ],
        ],
    ];
    protected array $loopInitializers = [
        100 => 'N1',
        200 => 'N7',
        300 => 'S5',
        310 => 'N1',
        320 => 'L5',
        325 => 'G61',
        330 => 'LH1',
        350 => 'OID',
        360 => 'L5',
        365 => 'G61',
        370 => 'LH1',
    ];
    protected array $loopIdsToKeys = [
        'N1' => 'entities',
        'N7' => 'equipment',
        'S5' => 'stops',
        'L5' => 'loadInfo',
        'G61' => 'contacts',
        'LH1' => 'hazardousInfo',
        'OID' => 'orderInfo',
    ];

    /**
     * Return specified value from specified stop entity (company) or return $default if not found.
     */
    public function getStopEntityValue(int $stopNumber, string $key, int $index = null, $default = null)
    {
        if (isset($this->data['stops'][$stopNumber]['entities'][0][$key])
            && is_array($this->data['stops'][$stopNumber]['entities'][0][$key])
        ) {
            if (is_null($index)) {
                $index = array_key_first($this->data['stops'][$stopNumber]['entities'][0][$key]);
            }
            return $this->data['stops'][$stopNumber]['entities'][0][$key][$index] ?? $default;
        }

        return $this->data['stops'][$stopNumber]['entities'][0][$key] ?? $default;
    }

    /**
     * Return specified value from specified stop or return $default if not found.
     * @param $returnType string Return value as "array", "first value", or if string, imploded, else as is.
     */
    public function getStopValue(
        int $stopNumber,
        string $key,
        string $returnType = 'first value',
        $default = null
    ) {
        // If the value is not found, return the default.
        if (!isset($this->data['stops'][$stopNumber][$key])) {
            return $default;
        }

        $value = $this->data['stops'][$stopNumber][$key];

        switch ($returnType) {
            case 'array':
                return is_array($value) ? $value : [$value];
            case 'first value':
                if (is_array($value)) {
                    $i = array_key_first($value);
                    return $value[$i];
                }
                return $value;
            default:
                if (is_string($returnType) && is_array($value)) {
                    return implode($returnType, $value);
                }
        }

        return $value;
    }

    /**
     * Return the specified stop data as an array.
     */
    public function getStop(int $stopNumber): array
    {
        return $this->data['stops'][$stopNumber] ?? [];
    }

    public function postProcessing()
    {
        parent::postProcessing();

        $this->mergeStops();
    }

    /**
     * It is possible that a partner can include multiple stop loops for a single stop.
     */
    protected function mergeStops(): void
    {
        if (empty($this->data['stops']) || !is_array($this->data['stops'])) {
            return;
        }

        $stops = [];
        foreach ($this->data['stops'] as $stop) {
            if (!isset($stop['Stop Sequence Number'])) {
                continue;
            }
            $stopNumber = (int)$stop['Stop Sequence Number'];

            // If stop doesn't exist, merely add it
            if (!array_key_exists($stopNumber, $stops)) {
                $stops[$stopNumber] = $stop;
                continue;
            }

            foreach ($stop as $key => $item) {
                // If the data matches, it is redundant and can be skipped.
                if (!is_array($item) && $stops[$stopNumber][$key] == $item) {
                    continue;
                }

                if (is_array($item)) {
                    if (!isset($stops[$stopNumber][$key])) {
                        $stops[$stopNumber][$key] = [];
                    } elseif (!is_array($stops[$stopNumber][$key])) {
                        $stops[$stopNumber][$key] = [$stops[$stopNumber][$key]];
                    }
                    $stops[$stopNumber][$key] = array_unique(array_merge_recursive($stops[$stopNumber][$key], $item));
                } else {
                    EDIData::addValueToArray($key, $item, $stops[$stopNumber]);
                }
            }
        }

        $this->data['stops'] = $stops;
    }
}
