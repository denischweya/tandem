<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the testing helper traits, guarding the test database first.
     *
     * By the time setUpTraits() runs, setUpTheTestEnvironment() has already booted the
     * application (so config and the DB facade are available), but RefreshDatabase's own
     * setUpTraits() entry - refreshDatabase(), which runs migrate:fresh - has not. This is
     * the latest point at which the guard below can still run before that wipe, and the
     * earliest point at which it is possible at all. A beforeEach() hook in tests/Pest.php
     * was tried first and rejected: Pest's beforeEach() closures run from inside the
     * generated test method, which PHPUnit always invokes after setUp() has returned - i.e.
     * strictly after RefreshDatabase has already migrated (and wiped) the database for the
     * first test in the run. Verified empirically (see fix-wave-1-report.md item 1): a probe
     * recorded "setUp-start, setUp-end, beforeEach, test-body" in that order, every run.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $this->guardTestDatabase();

        return parent::setUpTraits();
    }

    /**
     * Refuse to let RefreshDatabase's migrate:fresh run against anything but the dedicated
     * test database. See tandem-docs branch-review-remediation.md, item 1 (CRITICAL): if
     * bootstrap/cache/config.php exists, LoadConfiguration::bootstrap() (vendor) requires it
     * and skips loadConfigurationFiles() entirely - confirmed by reading that method, which
     * also exposes app('config_loaded_from_cache') for exactly this purpose - so no config
     * file ever calls env() and every <env> in phpunit.xml, including DB_DATABASE, is dead.
     * database.php then resolves whatever .env names instead: the real development database,
     * on every machine that has ever run `artisan config:cache` or `artisan optimize`.
     *
     * One more reason every <env> in phpunit.xml matters here: PHPUnit's <env> element
     * defaults to force="false", meaning a pinned value is only a *default* - it is
     * overridden by a real environment variable of the same name already present in the
     * process (e.g. exported in a shell profile, or set by a CI runner). The guard below
     * cannot see that override happen; it can only catch what it ends up pointed at.
     *
     * Three independent checks, because they diagnose three different failures that all end
     * in the same disaster if unchecked:
     *
     * 1. Config was loaded from the cache file at all. This is checked directly rather than
     *    inferred, so the error can name the real cause with certainty instead of a guess.
     * 2. The active connection driver is "pgsql". config/database.php defaults DB_CONNECTION
     *    to sqlite - the project's own historical "silently running on SQLite" failure - and
     *    Connection::getDatabaseName() returns the *configured* value for a SQLite connection
     *    too, so checking the name alone does not catch a wrong driver: it would either match
     *    "tandem_test" by coincidence (SQLite happily treats that as a file name and creates
     *    it) and silently run tests against a throwaway SQLite file, or fail later with a
     *    misleading "database missing" message when PDO can't open a file that was never a
     *    real database. Checking the driver directly names the actual cause instead.
     * 3. The resolved database name is not "tandem_test" - a defence in depth in case (1) and
     *    (2) do not apply (e.g. someone hand-edited bootstrap state) but the connection still
     *    points somewhere it must not.
     *
     * A fourth, distinct failure - the correctly-named test database not existing or not
     * being reachable - is deliberately given a different message. Collapsing "wrong
     * database" and "database missing" into one message was tried and rejected: a developer
     * who dropped tandem_test needs "run `make test-db`", not a config-cache warning that
     * sends them looking in the wrong place.
     */
    private function guardTestDatabase(): void
    {
        $expected = 'tandem_test';

        if (app()->bound('config_loaded_from_cache') && app('config_loaded_from_cache') === true) {
            throw new RuntimeException(
                'Refusing to run tests: bootstrap/cache/config.php exists, so Laravel loaded '
                .'cached config instead of reading phpunit.xml - every <env> value in that '
                .'file, including DB_DATABASE, was silently ignored. RefreshDatabase is about '
                .'to run migrate:fresh against whatever database your .env names, which is '
                .'very likely a real one. Run: /opt/homebrew/opt/php/bin/php artisan config:clear'
            );
        }

        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            throw new RuntimeException(
                "Refusing to run tests: the active connection driver is \"{$driver}\", not "
                .'"pgsql". This is a WRONG DRIVER failure - config/database.php defaults '
                .'DB_CONNECTION to sqlite, and a database-NAME check alone would not catch '
                .'this reliably, since Connection::getDatabaseName() returns the configured '
                .'value for SQLite too. The near-certain cause is the same as a WRONG '
                .'DATABASE failure below: a cached config file. Run: '
                .'/opt/homebrew/opt/php/bin/php artisan config:clear'
            );
        }

        $actual = DB::connection()->getDatabaseName();

        if ($actual !== $expected) {
            throw new RuntimeException(
                'Refusing to run tests: the active connection resolved to database '
                ."\"{$actual}\", not the required test database \"{$expected}\". This is a "
                .'WRONG DATABASE failure, not a missing one - phpunit.xml\'s <env '
                .'name="DB_DATABASE"> did not take effect. The near-certain cause is a '
                .'cached config file: delete bootstrap/cache/config.php (or run '
                .'/opt/homebrew/opt/php/bin/php artisan config:clear) and try again.'
            );
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Refusing to run tests: database \"{$expected}\" is correctly configured but "
                .'could not be reached. This is a DATABASE MISSING failure, not a '
                .'misconfiguration - run `make up` to start services and `make test-db` to '
                ."create it. Underlying error: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
