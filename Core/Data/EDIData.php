<?php
namespace GZMP\EDI\Core\Data;

use DateTimeInterface;
use Exception;
use Logger;
use GZMP\EDI\Core\Segments\EDISegment;
use GZMP\EDI\Core\Transactions\EDITransaction;

class EDIData
{
    protected array $data = [];

    /**
     * $currentLoopId and $currentLoopIndexes are for keeping track of where we are during processing.
     */
    protected int $currentLoopId = 0;
    protected array $currentLoopIndexes = [];

    /**
     * An array of loop ids along with the segment ids which begin them.
     */
    protected array $loopInitializers = [];

    /**
     * A potentially multi-level array specifying the hierarchy of loops.
     */
    protected array $loopHierarchy = [];

    /**
     * An array for setting a user-friendly name to replace the loop id in the final data set.
     */
    protected array $loopIdsToKeys = [];

    /**
     * Populate this EDIData object based on the submitted EDI transaction.
     */
    public function processTransaction(EDITransaction $transaction): array
    {
        $warnings = [];

        foreach ($transaction->getSegments() as $segment) {
            try {
                $this->processSegment($segment);
            } catch (Exception $exception) {
                $warnings[] = $exception->getMessage();
            }
        }
        $this->postProcessing();

        return $warnings;
    }

    /**
     * Process a single segment.
     */
    protected function processSegment(EDISegment $segment)
    {
        $this->checkLoop($segment->getId());

        $segment->addSegmentValuesToEDIData($this);

        foreach ($segment->getParsedValues() as $key => $unparsedValue) {
            $this->addValue($key, $unparsedValue, $this->currentLoopIndexes, $this->data);
        }
    }


    /**
     * Returns an array of loop ids which are parents to the specified loop id.
     */
    protected function getLoopsParentIds(int $searchLoopId, array $loopHierarchy): array
    {
        // This function has to work in an unusual way whereby it has to recursively dig down into sub-arrays
        // until the loop id we're searching for is found. At this point, loop ids are collected on the way
        // out of the recursion.
        if (array_key_exists($searchLoopId, $loopHierarchy)) {
            // This is how we inform the parent function that the searched for loop id has been found.
            return [0];
        }

        foreach ($loopHierarchy as $loopId => $children) {
            if (empty($children)) {
                continue;
            }
            $parentIds = $this->getLoopsParentIds($searchLoopId, $children);
            // If the searched for loop id was not in this child, an empty array would be returned in which case
            // this check will be false. If it is true, we know the loop id was found. Add the current id and
            // continue backing out of the recursion.
            if ($parentIds) {
                $parentIds[] = $loopId;
                return $parentIds;
            }
        }

        // The searched for loop id was not found in this loop hierarchy or any children; return this falsey value
        // to indicate as much.
        return [];
    }

    /**
     * Returns any child loop ids for the given loop id.
     */
    protected function getLoopsChildIds(int $loopId, array $loopHierarchy): array
    {
        if ($loopId === 0) {
            return array_keys($loopHierarchy);
        }

        if (array_key_exists($loopId, $loopHierarchy)) {
            return array_keys($loopHierarchy[$loopId]);
        }

        foreach ($loopHierarchy as $children) {
            if (empty($children)) {
                continue;
            }
            $childIds = $this->getLoopsChildIds($loopId, $children);
            if ($childIds) {
                return $childIds;
            }
        }

        return [];
    }

    /**
     * Return an array of loop ids which are siblings of the specified loop.
     */
    protected function getLoopsSiblingIds(int $loopId, array $loopHierarchy): array
    {
        if (array_key_exists($loopId, $loopHierarchy)) {
            return array_keys($loopHierarchy);
        }

        foreach ($loopHierarchy as $children) {
            if (empty($children)) {
                continue;
            }
            $siblingIds = $this->getLoopsSiblingIds($loopId, $children);
            if ($siblingIds) {
                return $siblingIds;
            }
        }

        return [];
    }

    /**
     * Allows segments to reset the current loop to the initial state of not being in any loop.
     */
    public function resetLoops(): void
    {
        $this->currentLoopId = 0;
        $this->currentLoopIndexes = [];
    }

    /**
     * Allows segments to specify what loop we should be in. Most often segments can't specify the loop because a loop
     * may or may not be initialized based on the broader context of where the segment appears in the transaction. This
     * is why we have to determine the loop for the most part within this class. However, this method is necessary for
     * the case in which the presence of a segment indicates that we have jumped out of a previous loop but not entirely
     * out of all loops.
     */
    public function setLoop(int $loopId)
    {
        $this->initializeLoop($loopId, $this->loopHierarchy, $this->data, false);
    }

    /**
     * Determines if we need to change which loop we're in and updates properties accordingly.
     */
    protected function checkLoop(string $segmentId)
    {
        if (!in_array($segmentId, $this->loopInitializers)) {
            return;
        }

        // If the segment initializes a loop which is an immediate child of the current loop, initialize that loop.
        // We need to do this before checking siblings, because if both are possible, initiating the child takes
        // precedent.
        if ($this->initializeLoopIfChild($segmentId)) {
            return;
        }

        // If the loop potentially initialized by this segment is a subsequent sibling, we'll initialize
        // the loop. If it is a prior or the current loop, we'll initialize a new loop iteration.
        if ($this->initializeLoopOrIterationIfSibling($segmentId)) {
            return;
        }

        // Finally, if the segment initializes a loop which is a parent of the current loop, this indicates that we
        // need to start a new iteration of that loop.
        if ($this->initializeLoopIterationIfParent($segmentId)) {
            return;
        }

        // If we made it this far, no new loop is initialized by this segment within the current context.
    }

    /**
     * Determines if the specified segment id should begin a new loop which is an immediate child of the current loop.
     * If so, will initialize this new loop.
     */
    protected function initializeLoopIfChild(string $segmentId): bool
    {
        $childIds = $this->getLoopsChildIds($this->currentLoopId, $this->loopHierarchy);
        foreach ($childIds as $childId) {
            if ($this->loopInitializers[$childId] == $segmentId) {
                $this->initializeLoop($childId, $this->loopHierarchy, $this->data, false);
                return true;
            }
        }

        return false;
    }

    /**
     * Determines if the specified segment id should begin a new loop which is a sibling of the current loop.
     */
    protected function initializeLoopOrIterationIfSibling(string $segmentId): bool
    {
        // Siblings will include self ($this->>currentLoopId).
        $siblingIds = $this->getLoopsSiblingIds($this->currentLoopId, $this->loopHierarchy);
        foreach ($siblingIds as $siblingId) {
            if ($this->loopInitializers[$siblingId] != $segmentId) {
                continue;
            }
            if ($siblingId > $this->currentLoopId) {
                $this->initializeLoop($siblingId, $this->loopHierarchy, $this->data, false);
            } else {
                $this->initializeLoop($siblingId, $this->loopHierarchy, $this->data, true);
            }
            return true;
        }

        return false;
    }

    /**
     * Determines if the specified segment id should begin a new loop which is a parent of the current loop.
     */
    protected function initializeLoopIterationIfParent(string $segmentId): bool
    {
        $parentIds = $this->getLoopsParentIds($this->currentLoopId, $this->loopHierarchy);
        foreach ($parentIds as $parentId) {
            if ($this->loopInitializers[$parentId] == $segmentId) {
                $this->initializeLoop($parentId, $this->loopHierarchy, $this->data, true);
                return true;
            }
        }

        return false;
    }

    /**
     * Adds indexes to data array if necessary, and updates the currentLoopIndexes accordingly.
     */
    protected function initializeLoop(int $newLoopId, array $loopHierarchy, array &$array, bool $newIteration): bool
    {
        $this->currentLoopIndexes = [];

        if (array_key_exists($newLoopId, $loopHierarchy)) {
            $this->currentLoopId = $newLoopId;
            $this->addKeyToCurrentIndexes($newLoopId, $newIteration, $array);
            return true;
        }

        foreach ($loopHierarchy as $loopId => $children) {
            if (empty($children)) {
                continue;
            }
            if (!isset($array[$loopId])) {
                $array[$loopId] = [];
            }
            $i = array_key_last($array[$loopId]) ?? 0;
            if (!isset($array[$loopId][$i])) {
                $array[$loopId][$i] = [];
            }
            if ($this->initializeLoop($newLoopId, $children, $array[$loopId][$i], $newIteration)) {
                // $this->currentLoopId = $newLoopId;
                $this->addKeyToCurrentIndexes($loopId, false, $array);
                return true;
            }
        }

        return false;
    }

    /**
     * Ensures that the data loop is initialized as well as the subsequent index, incrementing to the next if
     * $newIteration is true. If the loop has not been initialized before, it will initialize a 0 index regardless
     * of $newIteration.
     */
    protected function addKeyToCurrentIndexes(int $loopId, bool $newIteration, array &$array): void
    {
        if (!isset($array[$loopId])) {
            $array[$loopId] = [];
        }

        $lastArrayIndex = array_key_last($array[$loopId]) ?? 0;
        if ($newIteration) {
            $lastArrayIndex++;
        }
        array_unshift($this->currentLoopIndexes, $lastArrayIndex);
        if (!isset($array[$loopId][$lastArrayIndex])) {
            $array[$loopId][$lastArrayIndex] = [];
        }

        array_unshift($this->currentLoopIndexes, $loopId);
    }


    /**
     * Used by segments to add a key-value pair to the current data.
     */
    public function addKeyValue(string $key, $value)
    {
        $this->addValue($key, $value, $this->currentLoopIndexes, $this->data);
    }

    /**
     * Adds the key-value pair to the given array. When in a loop, indexes may look like [300, 0, 310, 1]. The idea
     * is that this function will be called recursively in order to set $array[300][0][310][1][$key] = $value.
     */
    protected function addValue(string $key, $value, array $indexes, array &$array): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (empty($indexes)) {
            EDIData::addValueToArray($key, $value, $array);
            return true;
        }

        foreach ($indexes as $i) {
            $nextIndexes = array_slice($indexes, 1);
            if (!isset($array[$i])) {
                Logger::warning("Missing array.");
                return false;
            }
            if ($this->addValue($key, $value, $nextIndexes, $array[$i])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds the key-value pair to the array, with special handling if the key already exists.
     */
    public static function addValueToArray(string $key, $value, array &$array)
    {
        if (array_key_exists($key, $array)) {
            // If $array[$key] is a numeric indexed array, add the value to the end of the array.
            if (is_array($array[$key]) && ($array[$key] === [] || array_keys($array[$key])[0] === 0)) {
                $array[$key][] = $value;
            } else {
                // If $array[$key] exists but is not a numerically indexed array, convert to an indexed array. This
                // is in order to handle how there may be multiple values for the same thing in EDI.
                $value1 = $array[$key];
                $array[$key] = [$value1, $value];
            }
        } else {
            $array[$key] = $value;
        }
    }

    /**
     * Cleans up the data after processing.
     */
    public function postProcessing()
    {
        $this->cleanup($this->data);

        $this->replaceLoopIdsWithKeys($this->loopHierarchy, $this->data);
    }

    /**
     * Remove duplicate values, flatten arrays with only one value.
     */
    protected function cleanup(array &$array)
    {
        foreach ($array as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_key_exists($key, $this->loopInitializers)) {
                foreach ($value as $key2 => $value2) {
                    $this->cleanup($array[$key][$key2]);
                }
                continue;
            }

            $this->cleanup($value);
            $value = array_unique($value, SORT_REGULAR);
            $array[$key] = (count($value) === 1) ? array_shift($value) : $value;
        }
    }

    protected function replaceLoopIdsWithKeys(array $loopHierarchy, array &$array)
    {
        foreach ($loopHierarchy as $loopId => $children) {
            if (!isset($array[$loopId])) {
                continue;
            }

            if (!empty($children)) {
                foreach ($array[$loopId] as $i => $innerArray) {
                    $this->replaceLoopIdsWithKeys($children, $array[$loopId][$i]);
                }
            }

            if (!empty($this->loopIdsToKeys[$this->loopInitializers[$loopId]])) {
                $newKey = $this->loopIdsToKeys[$this->loopInitializers[$loopId]];
                $array[$newKey] = $array[$loopId];
                unset($array[$loopId]);
            }
        }
    }


    /**
     * Return the value for the specified key.
     */
    public function getValue(string $key, bool $allowReturnArray = false)
    {
        $value = $this->data[$key] ?? null;
        return (!$allowReturnArray && is_array($value)) ? array_shift($value) : $value;
    }

    /**
     * Return the code for the specified key.
     */
    public function getCode(string $key)
    {
        $value = $this->getValue($key);
        if (is_null($value)) {
            return $value;
        }

        preg_match('/\[([A-Za-z0-9]+)\]/', $value, $matches);

        return $matches[1] ?? null;
    }

    /**
     * Return the data.
     */
    public function getAll(): array
    {
        return $this->data;
    }

    /**
     * Set the data.
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Convert the given array or the object's data into an array and return. The only thing this does in practice is to
     * check for any DateTimeInterface values and converts them to strings.
     */
    public function toArray(array $data = null): array
    {
        if (is_null($data)) {
            $data = $this->data;
        }

        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format(DateTimeInterface::ATOM);
            } elseif (is_array($value)) {
                $data[$key] = $this->toArray($value);
            }
        }

        return $data;
    }

    /**
     * Append "st", "nd", "rd", or "th" to the given number and return the result.
     */
    public function addOrdinalNumberSuffix($num)
    {
        if (!in_array(($num % 100), array(11, 12, 13))) {
            switch ($num % 10) {
                case 1:
                    return $num . 'st';
                case 2:
                    return $num . 'nd';
                case 3:
                    return $num . 'rd';
            }
        }
        return $num . 'th';
    }

    /**
     * When a code is converted to a description, the code is usually appended in brackets like "description [code]".
     * This function extracts and returns the code from a description string.
     */
    public static function getCodeFromDescription(string $description)
    {
        preg_match('/\[([A-Z0-9]{2,3})\]/', $description, $matches);
        return $matches[1] ?? '';
    }
}
