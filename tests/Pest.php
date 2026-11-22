<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Feature tests boot the Laravel application and reset the database
| (in-memory SQLite, see phpunit.xml) for every test. Unit tests stay plain
| so the pure booking logic can be tested without the framework.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Concurrency tests (ISSUE-13) use a second database connection that must
| see the test data, so they cannot run inside RefreshDatabase's
| transaction. Their data is committed and truncated instead. They only
| run on MySQL/MariaDB and skip themselves on SQLite.
*/

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');
