<?php
/**
 * PDO Userspace Driver for Oracle (oci8)
 *
 * @category   Database
 * @package    Pdo
 * @subpackage Oci8
 * @author     Ben Ramsey <ramsey@php.net>
 * @copyright  Copyright (c) 2009 Ben Ramsey (http://benramsey.com/)
 * @license    http://open.benramsey.com/license/mit  MIT License
 */

namespace nineinchnick\pdo\Oci8;

use nineinchnick\pdo\Oci8;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Oci8 Statement class to mimic the interface of the PDOStatement class
 *
 * This class extends PDOStatement but overrides all of its methods. It does
 * this so that instanceof check and type-hinting of existing code will work
 * seamlessly.
 */
class Statement extends PDOStatement
{
    /**
     * @var resource Statement handler
     */
    protected $sth;

    /**
     * @var Oci8 PDO Oci8 driver
     */
    protected $pdoOci8;

    /**
     * @var array Statement options
     */
    protected $options = [];

    /**
     * @var array
     */
    protected $error = false;

    /**
     * @var integer Default fetch mode for this statement
     */
    protected $fetchMode = null;

    /**
     * @var integer Column number for PDO::FETCH_COLUMN fetch mode
     */
    protected $fetchColumnNumber = 0;

    /**
     * @var string Class name for PDO::FETCH_CLASS fetch mode
     */
    protected $fetchClassName = '\stdClass';

    /**
     * @var array Constructor arguments for PDO::FETCH_CLASS
     */
    protected $fetchCtorArgs = [];

    /**
     * @var object Object reference for PDO::FETCH_INTO fetch mode
     */
    protected $fetchIntoObject = null;

    /**
     * @var array Bound columns for bindColumn()
     */
    protected $boundColumns = [];

    /**
     * @var boolean should LOB be converted to string when reading results
     */
    protected $returnLobs = true;

    /**
     * @var Lob[] Lob object, when need lob->save after oci_execute.
     */
    protected $saveLobs = [];

    /**
     * @var Lob[] Lob object, when need lob->write after oci_bind_by_name.
     */
    protected $writeLobs = [];

    /**
     * Array of param value, which binded in bindParam as lob.
     *
     * @var array
     */
    protected $lobsValue = [];

    /**
     * Constructor
     *
     * @param resource $sth  Statement handle created with oci_parse()
     * @param Oci8 $pdoOci8  The Pdo_Oci8 object for this statement
     * @param array $options Options for the statement handle
     */
    public function __construct($sth, Oci8 $pdoOci8, array $options = [])
    {
        if (strtolower(get_resource_type($sth)) != 'oci8 statement') {
            throw new PDOException('Resource expected of type oci8 statement; '
                . (string)get_resource_type($sth) . ' received instead');
        }

        $this->sth     = $sth;
        $this->pdoOci8 = $pdoOci8;
        $this->options = $options;
    }

    /**
     * Executes a prepared statement
     *
     * @param array $inputParams
     * @return bool
     */
    public function execute($inputParams = null)
    {
        $mode = OCI_COMMIT_ON_SUCCESS;
        if (!$this->pdoOci8->getAttribute(PDO::ATTR_AUTOCOMMIT) || $this->pdoOci8->isTransaction()
            || count($this->saveLobs) > 0 || count($this->writeLobs) > 0) {
            if (PHP_VERSION_ID > 503020) {
                $mode = OCI_NO_AUTO_COMMIT;
            } else {
                $mode = OCI_DEFAULT;
            }
        }

        // Set up bound parameters, if passed in
        if (is_array($inputParams)) {
            foreach ($inputParams as $key => $value) {
                if (!$this->bindParam($key, $value)) {
                    throw new PDOException($inputParams[$key] . ' could not be bound to ' . $key
                        . ' with Oci8PDO_Statement::bindParam()');
                }
            }
        }

        if (count($this->writeLobs) > 0) {
            /* @var $lob Lob */
            foreach ($this->writeLobs as $lobName => $lob) {
                $type = $lob->type == Oci8::PARAM_BLOB ? OCI_TEMP_BLOB : OCI_TEMP_CLOB;
                $lob->object->writetemporary($this->lobsValue[$lobName], $type);
            }
        }

        if (!oci_execute($this->sth, $mode)) {
            $this->pdoOci8->handleError($this->error = oci_error($this->sth));
            return false;
        }

        if (count($this->saveLobs) > 0) {
            foreach ($this->saveLobs as $lobName => $lob) {
                $lob->object->save($this->lobsValue[$lobName]);
            }
        }

        if (!$this->pdoOci8->inTransaction() && (count($this->saveLobs) > 0 || count($this->writeLobs) > 0)) {
            return oci_commit($this->pdoOci8->getConnectionHandler());
        }

        return true;
    }

    /**
     * Fetches the next row from a result set
     *
     * @param int $fetch_style
     * @param int $cursor_orientation
     * @param int $cursor_offset
     * @return mixed
     */
    public function fetch($fetch_style = null, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 0)
    {
        if ($cursor_orientation !== PDO::FETCH_ORI_NEXT || $cursor_offset !== 0) {
            throw new PDOException('$cursor_orientation that is not PDO::FETCH_ORI_NEXT '
                . 'is not implemented for Oci8PDO_Statement::fetch()');
        }

        if ($fetch_style === null) {
            if ($this->fetchMode !== null) {
                $fetch_style = $this->fetchMode;
            } else {
                $fetch_style = $this->pdoOci8->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
            }
        }

        if ($this->getAttribute(PDO::ATTR_ORACLE_NULLS) !== PDO::NULL_NATURAL) {
            throw new PDOException('Oci8\Statement does not support '
                . 'PDO::ATTR_ORACLE_NULLS other than PDO::NULL_NATURAL');
        }

        switch ($fetch_style) {
            case PDO::FETCH_ASSOC:
            case PDO::FETCH_BOTH:
                $result = oci_fetch_array(
                    $this->sth,
                    ($fetch_style === PDO::FETCH_BOTH ? OCI_BOTH : OCI_ASSOC) + OCI_RETURN_NULLS
                );
                if ($result === false) {
                    break;
                }
                if (($case = $this->pdoOci8->getAttribute(PDO::ATTR_CASE)) != PDO::CASE_NATURAL) {
                    $result = array_change_key_case($result, $case == PDO::CASE_LOWER ? CASE_LOWER : CASE_UPPER);
                }
                break;
            case PDO::FETCH_NUM:
                $result = oci_fetch_array($this->sth, OCI_NUM + OCI_RETURN_NULLS);
                break;
            case PDO::FETCH_COLUMN:
                $result = oci_fetch_row($this->sth);
                if (is_array($result) && array_key_exists($this->fetchColumnNumber, $result)) {
                    $result = $result[$this->fetchColumnNumber];
                }
                break;
            case PDO::FETCH_OBJ:
                if (($case = $this->pdoOci8->getAttribute(PDO::ATTR_CASE)) != PDO::CASE_NATURAL) {
                    throw new PDOException('Oci8\Statement does not support fetching objects with '
                        . 'PDO::ATTR_CASE not set to PDO::CASE_NATURAL');
                }
                $result = oci_fetch_object($this->sth);
                break;
            case PDO::FETCH_INTO:
            case PDO::FETCH_CLASS:
            case PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE:
                $result = oci_fetch_assoc($this->sth);
                if ($result === false) {
                    break;
                }
                if (($case = $this->pdoOci8->getAttribute(PDO::ATTR_CASE)) != PDO::CASE_NATURAL) {
                    $result = array_change_key_case($result, $case == PDO::CASE_LOWER ? CASE_LOWER : CASE_UPPER);
                }

                if ($fetch_style === PDO::FETCH_INTO) {
                    $object = $this->fetchIntoObject;
                } elseif ($fetch_style === PDO::FETCH_OBJ) {
                    $object = new \stdClass();
                } elseif ($this->fetchCtorArgs) {
                    $reflectionClass = new \ReflectionClass($this->fetchClassName);
                    $object          = $reflectionClass->newInstanceArgs($this->fetchCtorArgs);
                } else {
                    $object = new $this->fetchClassName();
                }

                foreach ($result as $field => $value) {
                    $object->$field = $value;
                }

                return $object;
            default:
            case PDO::FETCH_BOUND:
            case PDO::FETCH_LAZY:
                throw new PDOException('Oci8\Statement::fetch does not implement fetch mode ' . $fetch_style);
                break;
        }

        $this->bindToColumn($result);

        return $result;
    }

    /**
     * Binds a parameter to the specified variable name
     *
     * @param string $parameter
     * @param mixed $variable
     * @param int $data_type
     * @param int $length
     * @param array $driver_options
     * @return bool
     */
    public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = -1, $driver_options = null)
    {
        if (is_numeric($parameter)) {
            throw new PDOException('Oci8\Statement::bindParam() does not implement binding numerical params.');
        }
        if (is_array($variable)) {
            return oci_bind_array_by_name($this->sth, $parameter, $variable, count($variable), $length, $data_type);
        }
        if ($data_type === PDO::PARAM_LOB) {
            $data_type = Oci8::PARAM_BLOB;
        }
        if ($length == -1) {
            $length = strlen((string)$variable);
        }
        switch ($data_type) {
            case PDO::PARAM_BOOL:
                $oci_type = SQLT_INT;
                break;
            case PDO::PARAM_NULL:
                $oci_type = SQLT_CHR;
                break;
            case PDO::PARAM_INT:
                $oci_type = SQLT_INT;
                break;
            case PDO::PARAM_STR:
                $oci_type = SQLT_CHR;
                break;
            case Oci8::PARAM_BLOB:
            case Oci8::PARAM_CLOB:
                $oci_type = $data_type;

                $this->lobsValue[$parameter] = $variable;
                $variable = $this->pdoOci8->getNewDescriptor(OCI_D_LOB);

                if (in_array(Oci8::LOB_SQL, $driver_options)) {
                    $this->saveLobs[$parameter] = new Lob($oci_type, $variable);
                } elseif (in_array(Oci8::LOB_PL_SQL, $driver_options)) {
                    $this->writeLobs[$parameter] = new Lob($oci_type, $variable);
                }
                break;
            case PDO::PARAM_STMT:
                $oci_type = OCI_B_CURSOR;
                break;
            case SQLT_NTY:
                $oci_type = SQLT_NTY;
                break;
            default:
                $oci_type = SQLT_CHR;
                break;
        }

        return oci_bind_by_name($this->sth, $parameter, $variable, $length, $oci_type);
    }

    /**
     * Binds a column to a PHP variable
     *
     * @param mixed $column The number of the column or name of the column
     * @param mixed $param  The PHP variable to which the column should be bound
     * @param int $type
     * @param int $maxlen
     * @param mixed $driverdata
     * @return bool
     */
    public function bindColumn($column, &$param, $type = PDO::PARAM_STR, $maxlen = null, $driverdata = null)
    {
        if ($maxlen !== null || $driverdata !== null) {
            throw new PDOException('$maxlen and $driverdata parameters '
                . 'are not implemented for Oci8PDO_Statement::bindColumn()');
        }
        if ($type !== PDO::PARAM_INT && $type !== PDO::PARAM_STR) {
            throw new PDOException('Only PDO::PARAM_INT and PDO::PARAM_STR '
                . 'are implemented for the $type parameter of Oci8PDO_Statement::bindColumn()');
        }

        $this->boundColumns[] = [
            'column' => $column,
            'param'  => &$param,
            'type'   => $type
        ];
    }

    /**
     * @param $result
     */
    protected function bindToColumn($result)
    {
        if ($result === false) {
            return;
        }
        foreach ($this->boundColumns as $bound) {
            $key   = $bound['column'] - 1;
            $array = array_slice($result, $key, 1);
            if ($bound['type'] === PDO::PARAM_INT) {
                $bound['param'] = (int)array_pop($array);
            } else {
                $bound['param'] = array_pop($array);
            }
        }
    }

    /**
     * Binds a value to a corresponding named or question mark placeholder in the SQL statement
     * that was used to prepare the statement.
     *
     * @param string $parameter Parameter identifier.
     * @param mixed $value      The value to bind to the parameter.
     * @param int $data_type    Explicit data type for the parameter using the PDO::PARAM_* constants.
     * @return bool
     */
    public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR)
    {
        return $this->bindParam($parameter, $value, $data_type);
    }

    /**
     * Returns the number of rows affected by the last executed statement
     *
     * @return int
     */
    public function rowCount()
    {
        return oci_num_rows($this->sth);
    }

    /**
     * Returns a single column from the next row of a result set
     *
     * @param int $colNumber
     * @return string
     */
    public function fetchColumn($colNumber = 0)
    {
        $result = oci_fetch_array($this->sth, OCI_NUM + OCI_RETURN_NULLS);

        if ($result === false || !isset($result[$colNumber])) {
            return false;
        }
        return $result[$colNumber];
    }

    /**
     * Returns an array containing all of the result set rows
     *
     * @param int $fetch_style
     * @param mixed $fetch_argument
     * @param array $ctor_args
     * @return mixed
     */
    public function fetchAll($fetch_style = PDO::FETCH_BOTH, $fetch_argument = null, $ctor_args = [])
    {
        if ($this->fetchMode !== null) {
            $fetch_style = $this->fetchMode;
        }

        if ($fetch_style === PDO::FETCH_ASSOC || $fetch_style === PDO::FETCH_BOTH) {
            oci_fetch_all($this->sth, $result, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC + OCI_RETURN_NULLS);
            if (($case = $this->pdoOci8->getAttribute(PDO::ATTR_CASE)) != PDO::CASE_NATURAL) {
                $result = array_map(function ($row) use ($case) {
                    return array_change_key_case($row, $case == PDO::CASE_LOWER ? CASE_LOWER : CASE_UPPER);
                }, $result);
            }
            if ($fetch_style === PDO::FETCH_BOTH) {
                $result = array_merge($result, array_values($result));
            }
        } elseif ($fetch_style === PDO::FETCH_NUM) {
            oci_fetch_all($this->sth, $result, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_NUM + OCI_RETURN_NULLS);
        } elseif ($fetch_style === PDO::FETCH_COLUMN) {
            oci_fetch_all($this->sth, $preResult, 0, -1, OCI_FETCHSTATEMENT_BY_COLUMN + OCI_NUM + OCI_RETURN_NULLS);
            $result = [];
            foreach ($preResult as $row) {
                $result[] = $row[0];
            }
        } elseif ($fetch_style === PDO::FETCH_BOUND) {
            throw new PDOException('PDO::FETCH_BOUND is not implemented for Oci8PDO_Statement::fetchAll()');
        } elseif ($fetch_style === PDO::FETCH_CLASS) {
            throw new PDOException('PDO::FETCH_CLASS is not implemented for Oci8PDO_Statement::fetchAll()');
        } elseif ($fetch_style === PDO::FETCH_INTO) {
            throw new PDOException('PDO::FETCH_INTO is not implemented for Oci8PDO_Statement::fetchAll()');
        } elseif ($fetch_style === PDO::FETCH_LAZY) {
            throw new PDOException('PDO::FETCH_LAZY is not implemented for Oci8PDO_Statement::fetchAll()');
        } elseif ($fetch_style === PDO::FETCH_OBJ) {
            $result = [];
            while (false !== ($row = $this->fetch($fetch_style))) {
                $result[] = $row;
            }
        } else {
            throw new PDOException('This $fetch_style combination is not implemented for Oci8PDO_Statement::fetch()');
        }

        return $result;
    }

    /**
     * Fetches the next row and returns it as an object
     *
     * @param string $className
     * @param array $ctor_args
     * @return mixed
     */
    public function fetchObject($className = 'stdClass', $ctor_args = null)
    {
        if ($className == 'stdClass') {
            return oci_fetch_object($this->sth);
        }
        $object     = new $className($ctor_args);
        $object_oci = oci_fetch_object($this->sth);
        foreach ($object_oci as $k => $v) {
            $object->$k = $v;
        }
        return $object;
    }

    /**
     * Returns the error code associated with the last operation
     *
     * While this returns an error code, it merely emulates the action. If
     * there are no errors, it returns the success SQLSTATE code (00000).
     * If there are errors, it returns HY000. See errorInfo() to retrieve
     * the actual Oracle error code and message.
     *
     * @return string
     */
    public function errorCode()
    {
        return $this->error !== false ? 'HY000' : null;
    }

    /**
     * Returns extended error information for the last operation on the database
     *
     * @return array
     */
    public function errorInfo()
    {
        if ($this->error !== false) {
            return ['HY000', $this->error['code'], $this->error['message']];
        }

        return ['00000', null, null];
    }

    /**
     * Sets an attribute on the statement handle
     *
     * @param int $attribute
     * @param mixed $value
     * @return bool
     */
    public function setAttribute($attribute, $value)
    {
        $this->options[$attribute] = $value;

        return true;
    }

    /**
     * Retrieve a statement handle attribute
     *
     * @param integer $attribute
     * @return mixed
     */
    public function getAttribute($attribute)
    {
        if (isset($this->options[$attribute])) {
            return $this->options[$attribute];
        }

        return null;
    }

    /**
     * Returns the number of columns in the result set
     *
     * @return int
     */
    public function columnCount()
    {
        return oci_num_fields($this->sth);
    }

    /**
     * Returns metadata for a column in a result set
     *
     * The array returned by this function is patterned after that
     * returned by PDO::getColumnMeta(). It includes the following
     * elements:
     *
     *     native_type
     *     driver:decl_type
     *     flags
     *     name
     *     table
     *     len
     *     precision
     *     pdo_type
     *
     * @param int $column Zero-based column index
     * @return array
     */
    public function getColumnMeta($column)
    {
        // Columns in oci8 are 1-based; add 1 if it's a number
        if (is_numeric($column)) {
            $column++;
        }

        $meta                     = [];
        $meta['native_type']      = oci_field_type($this->sth, $column);
        $meta['driver:decl_type'] = oci_field_type_raw($this->sth, $column);
        $meta['flags']            = [];
        $meta['name']             = oci_field_name($this->sth, $column);
        $meta['table']            = null;
        $meta['len']              = oci_field_size($this->sth, $column);
        $meta['precision']        = oci_field_precision($this->sth, $column);
        $meta['pdo_type']         = null;

        return $meta;
    }

    /**
     * Set the default fetch mode for this statement
     *
     * @param int $mode
     * @param mixed $colClassOrObj
     * @param array $ctor_args
     * @return bool
     */
    public function setFetchMode($mode, $colClassOrObj = null, $ctor_args = [])
    {
        //52: $this->_statement->setFetchMode(PDO::FETCH_ASSOC);
        if ($colClassOrObj !== null || !empty($ctor_args)) {
            throw new PDOException('Second and third parameters'
                . ' are not implemented for Oci8PDO_Statement::setFetchMode()');
            //see http://www.php.net/manual/en/pdostatement.setfetchmode.php
        }
        $this->fetchMode = $mode;

        return true;
    }

    /**
     * Advances to the next rowset in a multi-rowset statement handle
     *
     * @return bool
     */
    public function nextRowset()
    {
        throw new PDOException('nextRowset() method is not implemented for Oci8PDO_Statement');
    }

    /**
     * Closes the cursor, enabling the statement to be executed again.
     *
     * @return bool
     */
    public function closeCursor()
    {
        return oci_free_statement($this->sth);
    }

    /**
     * Dump a SQL prepared command
     *
     * @return bool
     */
    public function debugDumpParams()
    {
        throw new PDOException('debugDumpParams() method is not implemented for Oci8PDO_Statement');
    }

    /**
     * Returns the current row from the rowset
     *
     * @return array
     */
    public function current()
    {
        throw new PDOException('current() method is not implemented for Oci8PDO_Statement');
    }

    /**
     * Returns the key for the current row
     *
     * @return mixed
     */
    public function key()
    {
        throw new PDOException('key() method is not implemented for Oci8PDO_Statement');
    }

    /**
     * Advances the cursor forward and returns the next row
     *
     * @return void
     */
    public function next()
    {
        throw new PDOException('next() method is not implemented for Oci8PDO_Statement');
    }

    /**
     * Rewinds the cursor to the beginning of the rowset
     *
     * @return void
     */
    public function rewind()
    {
        throw new PDOException('rewind() method is not implemented for Oci8PDO_Statement');
    }

    /**
     * Checks whether there is a current row
     *
     * @return bool
     */
    public function valid()
    {
        throw new PDOException('valid() method is not implemented for Oci8PDO_Statement');
    }
}
