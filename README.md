pdo_oci8
========

A PDO userspace driver for the Oracle database using the oci8 PHP module.

Based on `ramsey/pdo_oci8`, `yjeroen/oci8Pdo` and `yajra/laravel-pdo-via-oci8`.

# Installation

~~~
composer require nineinchnick\pdo_oci8
~~~

# Usage

~~~
$tsn = <<<TSN
(
  DESCRIPTION=(
    ADDRESS_LIST=(
      ADDRESS=(PROTOCOL=TCP)(HOST=localhost)(PORT=1521)
    )
  )(
    CONNECT_DATA=(SID=XE)
  )
)
TSN;
$dsn = "oci:dbname=$tsn;charset=AL32UTF8;";
$username = 'xe';
$password = 'xe';
$attributes = [];

$conn = new nineinchnick\pdo\Oci8($dsn, $username, $password, $attributes);
~~~
