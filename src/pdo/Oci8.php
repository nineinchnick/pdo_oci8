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
    const PARAM_BLOB = OCI_B_BLOB;
    const PARAM_CLOB = OCI_B_CLOB;
    const LOB_SQL    = 0;
    const LOB_PL_SQL = 1;

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
        PDO::ATTR_DRIVER_NAME => 'oci',
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
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
            $this->dbh = oci_pconnect($username, $password, $parsedDsn['dbname'], $parsedDsn['charset']);
        } else {
            $this->dbh = oci_connect($username, $password, $parsedDsn['dbname'], $parsedDsn['charset']);
        }

        if ($this->dbh === false) {
            $this->handleError($this->error = oci_error());
        }
    }

    /**
     * Returns current connection handler.
     * @return resource
     */
    public function getConnectionHandler()
    {
        return $this->dbh;
    }

    /**
     * Prepares a statement for execution and returns a statement object
     *
     * @param string $statement
     * @param array $options
     * @return Oci8\Statement
     */
    public function prepare($statement, $options = [])
    {
        $sth = oci_parse($this->dbh, $statement);

        if ($sth === false) {
            $this->handleError($this->error = oci_error($this->dbh));
            return false;
        }

        if (!is_array($options)) {
            $options = [];
        }

        return new Oci8\Statement($sth, $this, $options);
    }

    /**
     * Begins a transaction (turns off autocommit mode)
     * @return boolean
     */
    public function beginTransaction()
    {
        if ($this->isTransaction()) {
            $this->error = [
                'code'    => '00000',
                'message' => 'There is already an active transaction',
                'offset'  => null,
                'sqltext' => null,
            ];
            $this->handleError($this->error);
            return null;
        }

        $this->isTransaction = true;
        return true;
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
            $this->error = [
                'code'    => '00000',
                'message' => 'There is no active transaction',
                'offset'  => null,
                'sqltext' => null,
            ];
            $this->handleError($this->error);
            return false;
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
            $this->error = [
                'code'    => '00000',
                'message' => 'There is no active transaction',
                'offset'  => null,
                'sqltext' => null,
            ];
            $this->handleError($this->error);
            return false;
        }

        if (oci_rollback($this->dbh)) {
            $this->isTransaction = false;

            return true;
        }

        return false;
    }

    /**
     * Executes an SQL statement and returns the number of affected rows
     *
     * @param string $statement
     * @return int The number of rows affected
     */
    public function exec($statement)
    {
        $stmt = $this->prepare($statement);
        if (!$stmt->execute()) {
            return false;
        }

        return $stmt->rowCount();
    }

    /**
     * Executes an SQL statement, returning the results as a Oci8\Statement
     *
     * @param string $statement
     * @param int $mode
     * @param mixed $type_arg
     * @param array $ctor_args
     * @return Oci8\Statement
     */
    public function query($statement, $mode = null, $type_arg = null, $ctor_args = [])
    {
        $stmt = $this->prepare($statement);
        if ($mode !== null) {
            $stmt->setFetchMode($mode, $type_arg, $ctor_args);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * Return the last inserted id
     * If the sequence name is not sent, throws an exception
     *
     * @param string $name Sequence name
     * @return integer
     */
    public function lastInsertId($name = null)
    {
        if ($name === null) {
            $this->error = [
                'code'    => 'IM001',
                'message' => 'SQLSTATE[IM001]: Driver does not support this function: '
                    . 'driver does not support lastInsertId()',
                'offset'  => null,
                'sqltext' => null,
            ];
            $this->handleError($this->error);
            return null;
        }
        $row = $this->query("SELECT $name.CURRVAL FROM DUAL")->fetch(\PDO::FETCH_ASSOC);
        return $row["CURRVAL"];
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
     * @param int $parameter_type
     * @return string
     */
    public function quote($string, $parameter_type = PDO::PARAM_STR)
    {
        if($parameter_type !== PDO::PARAM_STR) {
            throw new PDOException('Only PDO::PARAM_STR is currently implemented for the $parameter_type of Oci8::quote()');
        }
        return "'" . str_replace("'", "''", $string) . "'";
    }

    /**
     * Special non PDO function used to start cursors in the database
     * Remember to call oci_free_statement() on your cursor.
     *
     * @return resource New statement handle, or FALSE on error.
     */
    public function getNewCursor()
    {
        return oci_new_cursor($this->dbh);
    }

    /**
     * Special non PDO function used to create a new descriptor.
     *
     * @param int $type One of OCI_DTYPE_FILE, OCI_DTYPE_LOB or OCI_DTYPE_ROWID.
     * @return mixed New LOB or FILE descriptor on success, FALSE on error.
     */
    public function getNewDescriptor($type = OCI_D_LOB)
    {
        return oci_new_descriptor($this->dbh, $type);
    }

    /**
     * Special non PDO function used to allocate a new collection object.
     *
     * @param string $tdo    Should be a valid named type (uppercase).
     * @param string $schema Should point to the scheme, where the named type was created.
     *                       The name of the current user is the default value.
     * @return mixed New collection on success, FALSE on error.
     */
    public function getNewCollection($tdo, $schema = null)
    {
        return oci_new_collection($this->dbh, $tdo, $schema);
    }

    /**
     * Special non PDO function used to close an open cursor in the database
     *
     * @param mixed $cursor A valid OCI statement identifier.
     * @return boolean
     */
    public function closeCursor($cursor)
    {
        return oci_free_statement($cursor);
    }
}
