<?php

namespace App\Console\Commands;

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Services\Plugin\PluginManager;

class XboardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'xboard:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'xboard 更新';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('正在导入数据库请稍等...');
        $wasDown = app()->isDownForMaintenance();
        if (!$wasDown && Artisan::call('down', ['--retry' => 60]) !== 0) {
            $this->error('无法进入维护模式，已停止更新。');
            return self::FAILURE;
        }
        // Leave maintenance enabled on failure, including process interruption.
        // A successful retry preserves it until the operator explicitly resumes.
        try {
            $status = Artisan::call('migrate', ['--force' => true]);
            $this->info(Artisan::output());
            if ($status !== 0) {
                $this->error('数据库迁移失败，已保留维护模式。');
                return self::FAILURE;
            }
        } catch (\Throwable $exception) {
            $this->error('数据库迁移失败，已保留维护模式。');
            throw $exception;
        }
        if (!$wasDown && Artisan::call('up') !== 0) {
            $this->error('无法退出维护模式，请检查后执行 php artisan up。');
            return self::FAILURE;
        }
        $this->info('正在检查并安装默认插件...');
        PluginManager::installDefaultPlugins();
        $this->info('默认插件检查完成');
        $updateService = new UpdateService();
        $updateService->updateVersionCache();
        $themeService = app(ThemeService::class);
        $themeService->refreshCurrentTheme();
        if (config('queue.default') === 'sync') {
            $this->info('horizon:terminate skipped (sync queue, no workers to terminate).');
        } else {
            try {
                Artisan::call('horizon:terminate');
            } catch (\Throwable $e) {
                $this->warn('horizon:terminate skipped: ' . $e->getMessage());
            }
        }
        if ($wasDown) {
            $this->warn('更新已完成，但站点仍处于维护模式。确认无误后请执行 php artisan up。');
        } else {
            $this->info('更新完毕。');
        }
    }
}
