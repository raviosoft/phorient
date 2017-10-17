<?php
/**
 * @package     bodev-core-bundles/php-orient-bundle
 * @subpackage  Odm/Types
 * @name        LinkList
 *
 * @author      Biber Ltd. (www.biberltd.com)
 * @author      Can Berkol
 *
 * @copyright   Biber Ltd. (C) 2015
 *
 * @version     1.0.0
 */

namespace BiberLtd\Bundle\Phorient\Odm\Types;

use BiberLtd\Bundle\Phorient\Odm\Exceptions\InvalidValueException;
use PhpOrient\Protocols\Binary\Data\ID as ID;

class OLinkList extends OrientCollection
{

    /** @var array $value */
    protected $value;

    /**
     * @param array $value
     *
     * @throws \BiberLtd\Bundle\Phorient\Odm\Exceptions\InvalidValueException
     */
    public function __construct(array $value = [], $embedded = false)
    {
        parent::__construct('OLinkList', $value, $embedded);
    }

    /**
     * @return array
     */
    public function getValue($embedded = false)
    {
        if(!$embedded) {
            $result = [];
            if (is_array($this->value) && count($this->value) > 0) {
                foreach ($this->value as $value) {
                    if($value instanceof ID) {
                        $result[] = $value;
                    }
                }
            }
            return $result;
        }

        return $this->value;
    }

    /**
     * @param array $value
     *
     * @return $this
     * @throws \BiberLtd\Bundle\Phorient\Odm\Exceptions\InvalidValueException
     */
    public function setValue($value)
    {
        if($this->validateValue($value)) {
            $this->value = $value;
        }

        return $this;
    }

    /*
     * @param mixed $value
     *
     * @return bool
     * @throws \BiberLtd\Bundle\Phorient\Odm\Exceptions\InvalidValueException
     */
    public function validateValue($value)
    {
        if(!is_array($value)) {
            throw new InvalidValueException($this);
        }
        foreach($value as $item) {
            if(!$item instanceof ID) {
                throw new InvalidValueException($this);
            }
        }

        return true;
    }
}