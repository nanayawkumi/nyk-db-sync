<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests;

use InvalidArgumentException;
use Nanayawkumi\NykDbSync\Postgres\PostgresDatabaseCreator;
use Nanayawkumi\NykDbSync\Tests\Fixtures\FakePostgresSession;

class PostgresDatabaseCreatorTest extends TestCase
{
    public function test_it_creates_a_database_and_login(): void
    {
        $session = new FakePostgresSession();

        (new PostgresDatabaseCreator())->create($session, 'my_app', 'app_user', "secret'word");

        $this->assertSame([
            'create role "app_user" login password \'secret\'\'word\'',
            'create database "my_app" owner "app_user" encoding \'UTF8\' template template0',
            'grant connect on database "my_app" to "app_user"',
            'grant usage, create on schema public to "app_user"',
        ], $session->statements);
        $this->assertSame(['my_app'], $session->databases);
    }

    public function test_it_updates_an_existing_login_and_leaves_an_existing_database(): void
    {
        $session = new FakePostgresSession();
        $session->roles[] = 'app_user';
        $session->existingDatabases[] = 'my_app';

        (new PostgresDatabaseCreator())->create($session, 'my_app', 'app_user', 'secret');

        $this->assertSame([
            'alter role "app_user" with login password \'secret\'',
            'grant connect on database "my_app" to "app_user"',
            'grant usage, create on schema public to "app_user"',
        ], $session->statements);
    }

    public function test_it_rejects_the_postgres_superuser_as_the_app_login(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PostgresDatabaseCreator())->create(new FakePostgresSession(), 'my_app', 'postgres', 'secret');
    }
}
