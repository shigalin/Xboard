<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasColumn('v2_user', 'email')) {
            return;
        }

        // MySQL commits DDL independently. Never expose a partially converted
        // table to requests, including after an interrupted migration.
        if (DB::table('v2_user')->exists() && !app()->isDownForMaintenance()) {
            throw new RuntimeException('Email identity migration requires maintenance mode. Stop all application writers, back up the database, then run php artisan down before migrating.');
        }

        $this->assertNoEmailCollisions();

        if (!Schema::hasColumn('v2_user', 'email_search')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $column = $table->string('email_search', 254)->default('');
                match (DB::getDriverName()) {
                    'mysql' => $column->charset('utf8mb4')->collation('utf8mb4_bin'),
                    'pgsql' => $column->collation('C'),
                    'sqlsrv' => $column->collation('Latin1_General_100_BIN2'),
                    default => null,
                };
            });
        }

        // Use bytewise comparison for MySQL and PostgreSQL; SQLite defaults to BINARY.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('email', 64)
                    ->charset('utf8mb4')
                    ->collation('utf8mb4_bin')
                    ->change();
            });
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "v2_user" ALTER COLUMN "email" TYPE varchar(64) COLLATE "C" USING "email"::varchar(64)');
        } elseif (DB::getDriverName() === 'sqlsrv') {
            Schema::table('v2_user', fn (Blueprint $table) => $table->dropUnique('email'));
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('email', 64)
                    ->collation('Latin1_General_100_BIN2')
                    ->change();
            });
            Schema::table('v2_user', fn (Blueprint $table) => $table->unique('email', 'email'));
        }

        DB::table('v2_user')
            ->select('id', 'email')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $email = User::normalizeEmail((string) $user->email);

                    DB::table('v2_user')
                        ->where('id', $user->id)
                        ->update(['email' => $email, 'email_search' => User::searchableEmail($email)]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        // Original email representations are not recoverable from canonical
        // values. Never offer a schema-only rollback for a populated database.
        if (DB::table('v2_user')->exists()) {
            throw new RuntimeException('Email identity data cannot be rolled back in place. Restore the pre-upgrade database backup together with the previous application version.');
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('email', 64)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
            });
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "v2_user" ALTER COLUMN "email" TYPE varchar(64) COLLATE "default" USING "email"::varchar(64)');
        } elseif ($driver === 'sqlsrv') {
            Schema::table('v2_user', fn (Blueprint $table) => $table->dropUnique('email'));
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('email', 64)->collation('DATABASE_DEFAULT')->change();
            });
            Schema::table('v2_user', fn (Blueprint $table) => $table->unique('email', 'email'));
        }

        if (Schema::hasColumn('v2_user', 'email_search')) {
            Schema::table('v2_user', fn (Blueprint $table) => $table->dropColumn('email_search'));
        }
    }

    private function assertNoEmailCollisions(): void
    {
        $seen = [];

        DB::table('v2_user')
            ->select('id', 'email')
            ->orderBy('id')
            ->chunkById(500, function ($users) use (&$seen) {
                foreach ($users as $user) {
                    $email = User::normalizeEmail((string) $user->email);

                    if (mb_strlen($email, 'UTF-8') > 64) {
                        throw new RuntimeException(
                            "Normalized email exceeds the 64-character column limit for user ID {$user->id}."
                        );
                    }

                    if (isset($seen[$email])) {
                        throw new RuntimeException(
                            "Email normalization collision between user IDs {$seen[$email]} and {$user->id}. Resolve these accounts before rerunning the migration."
                        );
                    }

                    $seen[$email] = $user->id;
                }
            });
    }
};
