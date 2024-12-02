<?php
namespace GZMP\EDI\Core;

use GZMP\EDI\Core\Segments\EDISegment;

abstract class EDIBase
{
    protected string $date;
    protected string $time;
    protected string $date_format = 'Ymd';
    protected string $time_format = 'Hi';
    protected string $control_number;
    protected string $x12_version = '004010';
    protected array $warnings = [];

    public function __construct($properties = null)
    {
        $this->setDefaultDate();
        $this->setDefaultTime();
        $this->setDefaultControlNumber();
        if (!empty($properties)) {
            $this->setProperties($properties);
        }
    }

    protected function setDefaultDate()
    {
        $this->date = date($this->date_format);
    }

    protected function setDefaultTime()
    {
        $this->time = date($this->time_format);
    }

    protected function setDefaultControlNumber()
    {
        $this->control_number = date('His') . rand(100, 999);
    }

    public function setProperties(array $properties)
    {
        foreach (get_class_vars(get_class($this)) as $property => $value) {
            if (isset($properties[$property])) {
                $this->$property = $properties[$property];
            }
        }
    }

    /**
     * Return this interchange, group, or transaction's control number.
     */
    public function getControlNumber()
    {
        return $this->control_number;
    }

    public function getHTML(): string
    {
        $html = '';
        foreach ($this->getSegments() as $segment) {
            if (strtoupper($segment->getId()) == 'ST') {
                $html .= "<div class='edi-transaction'>\n";
            }

            $html .= $segment->getHTML();

            if (strtoupper($segment->getId()) == 'SE') {
                $html .= "</div>\n";
            }
        } // end loop for each segment

        return $this->wrapHTML($html);
    }

    protected function wrapHTML($html)
    {
        return <<<HTML
        <style>
            .edi {
            font-family: verdana;
                /*border-bottom: 3px solid;
                padding-bottom: 4px;*/
                padding: 1em;
            }

            .edi table {
            border-collapse: collapse;
                margin-bottom: 1em;
            }

            .edi th, .edi td {
            border: thin solid;
                padding: 0.1em 0.3em;
                text-align: center;
            }

            .edi-transaction {
            border-bottom: 2px solid;
                margin-bottom: 1em;
            }
        </style>
        <div class='edi'>$html</div>\n
        HTML;
    }

    abstract public function generateHeader(): EDISegment;
    abstract public function generateFooter(): EDISegment;
    abstract public function parseHeader(array $segment): void;
    abstract public function parseFooter(array $segment): void;
}
