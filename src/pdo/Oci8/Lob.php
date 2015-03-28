<?php
/**
 * PDO Userspace Driver for Oracle (oci8)
 *
 * @category   Database
 * @package    nineinchnick\pdo
 * @subpackage Oci8
 * @author     Ben Ramsey <ramsey@php.net>
 * @copyright  Copyright (c) 2009 Ben Ramsey (http://benramsey.com/)
 * @license    http://open.benramsey.com/license/mit  MIT License
 */

namespace nineinchnick\pdo\Oci8;

/**
 * Represents a LOB with type and value.
 */
class Lob
{
    /**
     * @var integer one of Oci8::PARAM_CLOB or Oci8::PARAM_BLOB
     */
    public $type;
    /**
     * @var \OCI-Lob a LOB object returned by oci_new_descriptor() or from query results
     */
    public $object;

    public function __construct($type = null, $object = null)
    {
        $this->type = $type;
        $this->object = $object;
    }
}
