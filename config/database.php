<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
 * Both connections accept more than one driver, and a few settings are not
 * portable between them: PostgreSQL rejects the charset MySQL requires, and
 * they listen on different ports. Deriving those from the driver means
 * pointing a connection at the other backend is one variable, not four, and
 * removes a class of misconfiguration that only fails at connection time.
 *
 * Every derived value remains overridable by its own variable.
 */
$driverDefaults = static fn (string $driver): array => $driver === 'pgsql'
    ? ['port' => '5432', 'charset' => 'utf8']
    : ['port' => '3306', 'charset' => 'utf8mb4'];

$mailwardDriver = env('DB_DRIVER', 'pgsql');
$mailwardDefaults = $driverDefaults($mailwardDriver);

$vmailDriver = env('VMAIL_DB_DRIVER', 'mysql');
$vmailDefaults = $driverDefaults($vmailDriver);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'mailward'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        /*
         * Mailward's own database. Owned outright: migrations, full DDL.
         * Because this is the default connection, migrate:fresh and
         * migrate:rollback are physically incapable of reaching mail data.
         * See docs/01-architecture.md §3.
         */
        'mailward' => [
            'driver' => $mailwardDriver,
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', $mailwardDefaults['port']),
            'database' => env('DB_DATABASE', 'mailward'),
            'username' => env('DB_USERNAME', 'mailward'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', $mailwardDefaults['charset']),
            'collation' => env('DB_COLLATION'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * iRedMail's account database. DML only — SELECT, INSERT, UPDATE,
         * DELETE. Mailward never issues DDL here and no migration may name
         * this connection. The database user should be granted DML privileges
         * only, so the boundary is enforced by the server rather than by our
         * discipline (docs/decisions/0002-separate-application-database.md).
         *
         * The driver is configuration because iRedMail supports MySQL,
         * MariaDB and PostgreSQL, and the type divergences between them are
         * real (docs/reference/schema-type-matrix.md).
         */
        'vmail' => [
            'driver' => $vmailDriver,
            'url' => env('VMAIL_DB_URL'),
            'host' => env('VMAIL_DB_HOST', '127.0.0.1'),
            'port' => env('VMAIL_DB_PORT', $vmailDefaults['port']),
            'database' => env('VMAIL_DB_DATABASE', 'vmail'),
            'username' => env('VMAIL_DB_USERNAME', 'mailward'),
            'password' => env('VMAIL_DB_PASSWORD', ''),
            'unix_socket' => env('VMAIL_DB_SOCKET', ''),
            'charset' => env('VMAIL_DB_CHARSET', $vmailDefaults['charset']),
            'collation' => env('VMAIL_DB_COLLATION'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('VMAIL_DB_SSLMODE', 'prefer'),

            /*
             * Deliberately not strict. Laravel's strict mode sets a session
             * sql_mode that rejects iRedMail's own sentinel dates, such as
             * the '0001-01-01' default on mailbox.birthday. We do not own
             * this schema and must read the rows it already contains.
             */
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('VMAIL_MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * The iredapd and amavisd connections are declared when the features
         * that need them land (docs/01-architecture.md §3). Only vmail is
         * used in v1.
         */

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
