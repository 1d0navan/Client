<?php
/**
 * @package MailLibrary
 * @author Tomáš Blatný
 */

namespace greeny\MailLibrary;

use Nette\Object;

/**
 * Represents set of conditions.
 */
class Filter extends Object
{
    public $order = array();

    public $limit = -1;

    public $offset = -1;

    protected $conditions = array();

    public function addCondition($key, $value)
    {
        $this->conditions[] = array('key' => $key, 'value' => $value);
        return $this;
    }

    public function getConditions()
    {
        return $this->conditions;
    }
}
