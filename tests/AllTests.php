<?php
/**
 * PDO Userspace Driver for Oracle (oci8)
 *
 * @category   Tests
 * @package    Pdo
 * @subpackage Oci8
 * @author     Ben Ramsey <ramsey@php.net>
 * @copyright  Copyright (c) 2009 Ben Ramsey (http://benramsey.com/)
 * @license    http://open.benramsey.com/license/mit  MIT License
 */

namespace nineinchnick\pdo\tests;

class AllTests
{
    /**
     * Test suite handler
     *
     * @return PHPUnit_Framework_TestSuite
     */
    public static function suite()
    {
        $suite = new \PHPUnit_Framework_TestSuite(
            'PDO Userspace Driver for Oracle (oci8)');

        $suite->addTestSuite('Pdo_UtilTest');
        $suite->addTestSuite('Pdo_Oci8Test');
        $suite->addTestSuite('Pdo_Oci8_StatementTest');

        return $suite;
    }
}
