<?php
/**
 * PDO Userspace Driver for Oracle (oci8)
 *
 * @category   Database
 * @package    Pdo
 * @subpackage Oci8
 * @author     Ben Ramsey <ramsey@php.net>
 * @author     Jan Waś <janek.jan@gmail.com>
 * @copyright  Copyright (c) 2009 Ben Ramsey (http://benramsey.com/)
 * @license    http://open.benramsey.com/license/mit  MIT License
 */

namespace nineinchnick\pdo;

use PDO;
use PDOException;

/**
 * Oci8 class to mimic the interface of the PDO class
 *
 * This class extends PDO but overrides all of its methods. It does this so
 * that instanceof checks and type-hinting of existing code will work
 * seamlessly.
 */
class Oci8 extends PDO
{
    /**
     * @var resource Database handler
     */
    protected $dbh;

    /**
     * @var array Driver options
     */
    protected $options = [
        PDO::ATTR_AUTOCOMMIT => true,
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];

    /**
     * @var bool Whether currently in a transaction
     */
    protected $isTransaction = false;

    /**
     * @var array
     */
    protected $error = false;

    /**
     * Creates a PDO instance representing a connection to a database
     *
     * @param string $dsn The Data Source Name, or DSN, contains the information required to connect to the database.
     * @param string $username The user name for the DSN string.
     * @param string $password The password for the DSN string.
     * @param array $options A key=>value array of driver-specific connection options.
     */
    public function __construct($dsn, $username = null, $password = null, array $options = [])
    {
        $parsedDsn = Oci8\Util::parseDsn($dsn, ['dbname', 'charset']);
        $this->options[PDO::ATTR_CLIENT_VERSION] = oci_client_version();
        $this->options = array_merge($this->options, $options);

        if (isset($options[PDO::ATTR_PERSISTENT]) && $options[PDO::ATTR_PERSISTENT]) {
            $this->dbh = oci_pconnect(
                $username,
                $password,
                $parsedDsn['dbname'],
                $parsedDsn['charset']
            );
        } else {
            $this->dbh = oci_connect(
                $username,
                $password,
                $parsedDsn['dbname'],
                $parsedDsn['charset']
            );

        }

        if (!$this->dbh) {
            $this->handleError($this->error = oci_error());
        }
    }

    /**
     * Prepares a statement for execution and returns a statement object
     *
     * @param string $statement
     * @param array $options
     * @return Oci8\Statement
     */
    public function prepare($statement, $options = null)
    {
        $sth = oci_parse($this->dbh, $statement);

        if (!$sth) {
            $this->handleError($this->error = oci_error($this->dbh));
        }

        if (!is_array($options)) {
            $options = [];
        }

        return new Oci8\Statement($sth, $this, $options);
    }

    /**
     * Begins a transaction (turns off autocommit mode)
     */
    public function beginTransaction()
    {
        if ($this->isTransaction()) {
            throw new PDOException('There is already an active transaction');
        }

        $this->isTransaction = true;
        $this->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
    }

    /**
     * Returns true if the current process is in a transaction
     *
     * @return bool
     */
    public function isTransaction()
    {
        return $this->isTransaction;
    }

    /**
     * Commits all statements issued during a transaction and ends the transaction
     *
     * @return bool
     */
    public function commit()
    {
        if (!$this->isTransaction()) {
            throw new PDOException('There is no active transaction');
        }

        if (oci_commit($this->dbh)) {
            $this->isTransaction = false;

            return true;
        }

        return false;
    }

    /**
     * Rolls back a transaction
     *
     * @return bool
     */
    public function rollBack()
    {
        if (!$this->isTransaction()) {
            throw new PDOException('There is no active transaction');
        }

        if (oci_rollback($this->dbh)) {
            $this->_isTransaction = false;

            return true;
        }

        return false;
    }

    /**
     * Executes an SQL statement and returns the number of affected rows
     *
     * @param string $query
     * @return int The number of rows affected
     */
    public function exec($query)
    {
        $stmt = $this->prepare($query);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Executes an SQL statement, returning the results as a Oci8\Statement
     *
     * @param string $query
     * @param int $fetchType
     * @param mixed $typeArg
     * @param array $ctor_args
     * @return Oci8\Statement
     */
    public function query($query, $fetchType = null, $typeArg = null, array $ctor_args = [])
    {
        $stmt = $this->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Issues a PHP warning, just as with the PDO_OCI driver
     *
     * Oracle does not support the last inserted ID functionality like MySQL.
     * You must implement this yourself by returning the sequence ID from a
     * stored procedure, for example.
     *
     * @param string $name Sequence name; no use in this context
     * @return void
     */
    public function lastInsertId($name = null)
    {
        $this->error = [
            'code' => 'IM001',
            'message' => 'SQLSTATE[IM001]: Driver does not support this function: '
                . 'driver does not support lastInsertId()',
            'offset' => null,
            'sqltext' => null,
        ];
        $this->handleError($this->error);
    }

    /**
     * Handles the error depending on the PDO::ATTR_ERRMODE option.
     * @param array $error obtained from oci_error()
     */
    public function handleError($error)
    {
        switch ($this->options[PDO::ATTR_ERRMODE]) {
            default:
            case PDO::ERRMODE_SILENT:
                break;
            case PDO::ERRMODE_EXCEPTION:
                $e = new PDOException($error['message'], $error['code']);
                $e->errorInfo = $this->errorInfo();
                throw $e;
            case PDO::ERRMODE_WARNING:
                trigger_error($error['message'], E_WARNING);
                break;
        }
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
     * Sets an attribute on the database handle
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
     * Retrieve a database connection attribute
     *
     * @param integer $attribute
     * @return mixed
     */
    public function getAttribute($attribute)
    {
        if ($attribute === PDO::ATTR_SERVER_VERSION && !isset($this->options[$attribute])) {
            return $this->options[$attribute] = oci_server_version($this->dbh);
        }
        if (isset($this->options[$attribute])) {
            return $this->options[$attribute];
        }

        return null;
    }

    /**
     * Quotes a string for use in a query
     *
     * @param string $string
     * @param int $paramType
     * @return string
     */
    public function quote($string, $paramType = PDO::PARAM_STR)
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }
}
