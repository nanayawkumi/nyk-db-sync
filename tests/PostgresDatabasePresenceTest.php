<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use Nanayawkumi\NykDbSync\Postgres\PostgresDatabasePresence;

class PostgresDatabasePresenceTest extends TestCase
{
    public function test_it_shows_creation_only_when_the_named_database_is_missing(): void
    {
        $presence = new PostgresDatabasePresence();

        $this->assertTrue($presence->missing(null, false, null));
        $this->assertTrue($presence->missing('', false, 'connection refused'));
        $this->assertTrue($presence->missing('peedocastdb', false, 'SQLSTATE[3D000] FATAL: database "peedocastdb" does not exist'));
        $this->assertFalse($presence->missing('peedocastdb', true, null));
        $this->assertFalse($presence->missing('peedocastdb', false, 'SQLSTATE[08006] connection refused'));
    }
}
