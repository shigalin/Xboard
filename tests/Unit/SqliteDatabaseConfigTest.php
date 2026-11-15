<?php

namespace Tests\Unit;

use Tests\TestCase;

class SqliteDatabaseConfigTest extends TestCase
{
    public function test_sqlite_preserves_memory_database_and_resolves_file_paths(): void
    {
        $original = [getenv('DB_DATABASE'), $_ENV['DB_DATABASE'] ?? null, $_SERVER['DB_DATABASE'] ?? null];
        try {
            foreach ([':memory:' => ':memory:', 'database/test.sqlite' => base_path('database/test.sqlite')] as $input => $expected) {
                putenv('DB_DATABASE=' . $input);
                $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $input;
                $config = require base_path('config/database.php');
                $this->assertSame($expected, $config['connections']['sqlite']['database']);
            }
        } finally {
            putenv($original[0] === false ? 'DB_DATABASE' : 'DB_DATABASE=' . $original[0]);
            if ($original[1] === null) {
                unset($_ENV['DB_DATABASE']);
            } else {
                $_ENV['DB_DATABASE'] = $original[1];
            }
            if ($original[2] === null) {
                unset($_SERVER['DB_DATABASE']);
            } else {
                $_SERVER['DB_DATABASE'] = $original[2];
            }
        }
    }
}
