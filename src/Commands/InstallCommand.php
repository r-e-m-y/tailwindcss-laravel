<?php

namespace Tonysm\TailwindCss\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tonysm\TailwindCss\Actions\AppendTailwindTag;

class InstallCommand extends Command
{
    protected $signature = '
        tailwindcss:install
        {--download : If you also want to download the Tailwind CSS binary.}
        {--cli-version= : You may override the configured version for the CLI.}
    ';

    protected $description = 'Installs the Tailwind CSS scaffolding for new Laravel applications.';

    public function handle()
    {
        $this->ensureTailwindConfigExists();
        $this->ensureTailwindCliBinaryExists();
        $this->appendTailwindStylesToLayouts();
        $this->installMiddleware('\Tonysm\TailwindCss\Http\Middleware\AddLinkHeaderForPreloadedAssets::class');
        $this->addIngoreLines();
        $this->runFirstBuild();
        $this->removeUnusedFiles();

        $this->newLine();

        $this->components->info('TailwindCSS Laravel was installed successfully.');

        return self::SUCCESS;
    }

    protected function phpBinary()
    {
        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }

    private function ensureTailwindConfigExists()
    {
        $this->copyStubToApp(
            stub: __DIR__ . '/../../stubs/postcss.config.js',
            to: base_path('postcss.config.js'),
        );

        if (! File::exists($appCssFilePath = resource_path('css/app.css')) || empty(trim(File::get($appCssFilePath))) || $this->mainCssIsDefault($appCssFilePath)) {
            $this->copyStubToApp(
                stub: __DIR__ . '/../../stubs/resources/css/app.css',
                to: $appCssFilePath,
            );
        }
    }

    private function ensureTailwindCliBinaryExists()
    {
        if (! File::exists(config('tailwindcss.bin_path')) || $this->option('download')) {
            Process::forever()->tty(SymfonyProcess::isTtySupported())->run([
                $this->phpBinary(),
                'artisan',
                'tailwindcss:download',
                '--cli-version',
                $this->option('cli-version') ?: config('tailwindcss.version'),
            ], function ($_type, $output) {
                $this->output->write($output);
            });
        }
    }

    private function copyStubToApp(string $stub, string $to): void
    {
        File::ensureDirectoryExists(dirname($to));
        File::copy($stub, $to);
    }

    /**
     * Install the middleware to a group in the application Http Kernel.
     *
     * @param  string  $after
     * @param  string  $name
     * @param  string  $group
     * @return void
     */
    private function installMiddlewareAfter($after, $name, $group = 'web')
    {
        $httpKernel = file_get_contents(app_path('Http/Kernel.php'));

        $middlewareGroups = Str::before(Str::after($httpKernel, '$middlewareGroups = ['), '];');
        $middlewareGroup = Str::before(Str::after($middlewareGroups, "'{$group}' => ["), '],');

        if (str_contains($middlewareGroup, $name)) {
            return;
        }

        $modifiedMiddlewareGroup = str_replace(
            $after . ',',
            $after . ',' . PHP_EOL . '            ' . $name . ',',
            $middlewareGroup,
        );

        file_put_contents(app_path('Http/Kernel.php'), str_replace(
            $middlewareGroups,
            str_replace($middlewareGroup, $modifiedMiddlewareGroup, $middlewareGroups),
            $httpKernel
        ));
    }

    private function appendTailwindStylesToLayouts()
    {
        $this->existingLayoutFiles()
            ->each(fn ($file) => File::put(
                $file,
                (new AppendTailwindTag())(File::get($file)),
            ));
    }

    private function existingLayoutFiles()
    {
        return collect(['app', 'guest'])
            ->map(fn ($file) => resource_path("views/layouts/{$file}.blade.php"))
            ->filter(fn ($file) => File::exists($file));
    }

    private function installMiddleware(string $middleware)
    {
        if (file_exists(app_path('Http/Kernel.php'))) {
            $this->installMiddlewareAfter('SubstituteBindings::class', $middleware);
        } else {
            $this->installMiddlewareToBootstrap($middleware);
        }
    }

    private function installMiddlewareToBootstrap(string $middleware, string $group = 'web', string $modifier = 'append')
    {
        $bootstrapApp = file_get_contents(base_path('bootstrap/app.php'));

        if (str_contains($bootstrapApp, $middleware)) {
            return;
        }

        $bootstrapApp = str_replace(
            '->withMiddleware(function (Middleware $middleware) {',
            '->withMiddleware(function (Middleware $middleware) {'
                . PHP_EOL . "        \$middleware->{$group}({$modifier}: ["
                . PHP_EOL . "            {$middleware},"
                . PHP_EOL . '        ]);'
                . PHP_EOL,
            $bootstrapApp,
        );

        file_put_contents(base_path('bootstrap/app.php'), $bootstrapApp);
    }

    private function addIngoreLines()
    {
        $binary = basename(config('tailwindcss.bin_path'));

        if (str_contains(File::get(base_path('.gitignore')), $binary)) {
            return;
        }

        File::append(base_path('.gitignore'), <<<LINES

        /public/css/
        /public/dist/
        .tailwindcss-manifest.json
        {$binary}
        LINES);
    }

    private function runFirstBuild()
    {
        Process::forever()->tty(SymfonyProcess::isTtySupported())->run([
            $this->phpBinary(),
            'artisan',
            'tailwindcss:build',
        ], function ($_type, $output) {
            $this->output->write($output);
        });
    }

    private function removeUnusedFiles()
    {
        $files = [
            base_path('tailwind.config.js'),
        ];

        foreach ($files as $file) {
            File::exists($file) && File::delete($file);
        }
    }

    private function mainCssIsDefault($appCssFilePath): bool
    {
        return trim(File::get($appCssFilePath)) === trim(<<<'CSS'
        @tailwind base;
        @tailwind components;
        @tailwind utilities;
        CSS);
    }
}
