<?php
require_once 'PHPUnit/Framework.php';

require_once 'Pdo/Util.php';

/**
 * Test class for Pdo_Util.
 * Generated by PHPUnit on 2009-05-31 at 13:07:33.
 */
class Pdo_UtilTest extends PHPUnit_Framework_TestCase
{
    /**
     * DSN string
     *
     * @var string
     */
    protected $_dsn;

    /**
     * Database name in DSN
     *
     * @var string
     */
    protected $_dbname;

    /**
     * Character set in DSN
     *
     * @var string
     */
    protected $_charset;

    /**
     * Sets up the test
     */
    public function setUp()
    {
        $this->_dsn     = 'user:driver=Pdo_Oci8;dbname=//localhost:1521/xe;charset=AL32UTF8';
        $this->_dbname  = '//localhost:1521/xe';
        $this->_charset = 'AL32UTF8';
    }

    /**
     * Tests parsing a DSN with driver invocation
     */
    public function testParseDsnDriverInvocation()
    {
        $parsedDsn = Pdo_Util::parseDsn($this->_dsn, ['dbname', 'charset']);

        $this->assertType('array', $parsedDsn);
        $this->assertArrayHasKey('dbname', $parsedDsn);
        $this->assertArrayHasKey('charset', $parsedDsn);
        $this->assertEquals($this->_dbname, $parsedDsn['dbname']);
        $this->assertEquals($this->_charset, $parsedDsn['charset']);
    }

    /**
     * Tests parsing a DSN with URI invocation
     */
    public function testParseDsnUriInvocation()
    {
        // Set up the file
        $tmpFile = tempnam(sys_get_temp_dir(), 'pdo');
        $dsn     = "uri:file://{$tmpFile}";
        file_put_contents($tmpFile, $this->_dsn);

        $parsedDsn = Pdo_Util::parseDsn($dsn, ['dbname', 'charset']);

        $this->assertType('array', $parsedDsn);
        $this->assertArrayHasKey('dbname', $parsedDsn);
        $this->assertArrayHasKey('charset', $parsedDsn);
        $this->assertEquals($this->_dbname, $parsedDsn['dbname']);
        $this->assertEquals($this->_charset, $parsedDsn['charset']);

        unlink($tmpFile);
    }

    /**
     * Tests parsing a DSN using an alias
     * @todo Need to figure out how to stub a static method in PHPUnit 4 to complete this test.
     */
    public function testParseDsnAlias()
    {
        $this->markTestIncomplete('Incomplete test.');

        // Set up the alias
        $dsnAlias = 'mydb';

        // Create a stub object for testing ini_get()
        $stub = $this->getMock('Pdo_Util');
        $stub->expects($this->any())
            ->method('iniGet')
            ->with($this->equalTo("pdo.dsn.{$dsnAlias}"))
            ->will($this->returnValue($this->_dsn));

        $parsedDsn = $stub->parseDsn($dsnAlias, ['dbname', 'charset']);

        $this->assertType('array', $parsedDsn);
        $this->assertArrayHasKey('dbname', $parsedDsn);
        $this->assertArrayHasKey('charset', $parsedDsn);
        $this->assertEquals($this->_dbname, $parsedDsn['dbname']);
        $this->assertEquals($this->_charset, $parsedDsn['charset']);
    }
}

?>
