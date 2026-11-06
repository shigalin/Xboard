<?php

namespace Tests\Unit\Services\Auth;

use App\Console\Commands\XboardUpdate;
use App\Services\ThemeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class EmailMigrationFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->maintenanceMode()->deactivate();
        parent::tearDown();
    }

    private function runUpdate(?BufferedOutput $output = null): int
    {
        $command = new XboardUpdate();
        $command->setLaravel($this->app);
        return $command->run(new ArrayInput([]), $output ?? new BufferedOutput());
    }

    public function test_nonzero_migration_result_stops_update_and_preserves_maintenance(): void
    {
        Artisan::shouldReceive('call')->once()->with('down', ['--retry' => 60])->andReturnUsing(function () {
            app()->maintenanceMode()->activate(['time' => time(), 'retry' => 60, 'status' => 503]);
            return 0;
        });
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Migration failed');

        $this->assertSame(1, $this->runUpdate());
        $this->assertTrue(app()->isDownForMaintenance());
    }

    public function test_migration_exception_preserves_existing_maintenance(): void
    {
        app()->maintenanceMode()->activate(['time' => time(), 'status' => 503]);
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])
            ->andThrow(new \RuntimeException('Simulated DDL failure'));

        try {
            $this->runUpdate();
            $this->fail('Expected the migration exception to stop the update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated DDL failure', $exception->getMessage());
        }
        $this->assertTrue(app()->isDownForMaintenance());
    }

    public function test_successful_update_warns_when_existing_maintenance_is_preserved(): void
    {
        app()->maintenanceMode()->activate(['time' => time(), 'status' => 503]);
        // The File facade mock below would also intercept the file cache store.
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Migration completed');
        File::shouldReceive('isDirectory')->once()->with(base_path('plugins-core'))->andReturn(false);
        Process::fake([
            'git log -1 --format=%cd:%H --date=format:%Y%m%d' => Process::result(output: '20261106:295e86326693bac0101e490a55a03f83006b25df'),
        ]);
        $this->mock(ThemeService::class)->shouldReceive('refreshCurrentTheme')->once()->andReturn(true);
        $output = new BufferedOutput();

        $this->assertSame(0, $this->runUpdate($output));
        $this->assertTrue(app()->isDownForMaintenance());
        $text = $output->fetch();
        $this->assertStringContainsString('仍处于维护模式', $text);
        $this->assertStringContainsString('php artisan up', $text);
        $this->assertStringNotContainsString('无需进行任何操作', $text);
    }
}
