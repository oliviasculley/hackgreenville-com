<?php

namespace Tests\Feature;

use ArrayObject;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ContainerFailureReportTest extends TestCase
{
    private string $directory;

    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/hg-container-entrypoint-' . uniqid();
        mkdir($this->directory);

        foreach (['RAILWAY_ENVIRONMENT_NAME', 'RAILWAY_SERVICE_NAME', 'RAILWAY_DEPLOYMENT_ID'] as $name) {
            $this->previousEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }

        $this->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_command_logs_the_exit_code_environment_and_log_tail(): void
    {
        $messages = $this->captureLogMessages();

        $log = $this->directory . '/boot.log';
        file_put_contents($log, "optimize done\ncannot create /etc/s6-overlay/s6-rc.d/user/type: Permission denied\n");

        putenv('RAILWAY_ENVIRONMENT_NAME=staging');
        putenv('RAILWAY_SERVICE_NAME=web');
        putenv('RAILWAY_DEPLOYMENT_ID=deploy-1');

        $this->artisan('container:report-failure', [
            '--exit-code' => 2,
            '--log' => $log,
        ])->assertSuccessful();

        $message = $this->loggedMessage($messages);

        $this->assertStringContainsString('Container exited 2', $message);
        $this->assertStringContainsString('Environment: staging', $message);
        $this->assertStringContainsString('Service: web', $message);
        $this->assertStringContainsString('Deployment: deploy-1', $message);
        $this->assertStringContainsString('Permission denied', $message);
    }

    public function test_command_keeps_the_end_of_a_long_log(): void
    {
        $messages = $this->captureLogMessages();

        $log = $this->directory . '/boot.log';
        file_put_contents($log, 'START' . str_repeat('a', 4000) . "\nPermission denied\n");

        $this->artisan('container:report-failure', [
            '--exit-code' => 1,
            '--log' => $log,
        ])->assertSuccessful();

        $message = $this->loggedMessage($messages);

        $this->assertStringContainsString('Permission denied', $message);
        $this->assertStringNotContainsString('START', $message);
    }

    public function test_wrapper_reports_a_non_zero_exit(): void
    {
        $this->writeExecutable($this->directory . '/entrypoint', <<<'BASH'
#!/bin/bash
echo "banner"
echo "cannot create /etc/s6-overlay/s6-rc.d/user/type: Permission denied" >&2
exit "${FAKE_EXIT_CODE:-1}"
BASH);

        $process = $this->runWrapper(2);

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('banner', $process->getOutput());
        $this->assertStringContainsString('Permission denied', $process->getOutput());
        $this->assertFileExists($this->directory . '/called');
        $this->assertStringContainsString('container:report-failure', file_get_contents($this->directory . '/called'));
        $this->assertStringContainsString('--exit-code=2', file_get_contents($this->directory . '/called'));
        $this->assertStringContainsString('Permission denied', file_get_contents($this->directory . '/log-copy'));
    }

    public function test_wrapper_ignores_a_clean_exit(): void
    {
        $this->writeExecutable($this->directory . '/entrypoint', <<<'BASH'
#!/bin/bash
echo "banner"
exit 0
BASH);

        $process = $this->runWrapper(0);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('banner', $process->getOutput());
        $this->assertFileDoesNotExist($this->directory . '/called');
    }

    public function test_wrapper_does_not_report_a_stop_signal(): void
    {
        $ready = $this->directory . '/ready';
        $this->writeExecutable($this->directory . '/entrypoint', <<<BASH
#!/bin/bash
touch "{$ready}"
exec sleep 30
BASH);

        $process = $this->startWrapper();
        $deadline = microtime(true) + 5;

        while ( ! is_file($ready) && microtime(true) < $deadline) {
            usleep(50000);
        }

        $this->assertFileExists($ready);

        // Railway sends SIGQUIT. SIGTERM takes the same "this was a stop" path.
        exec('kill -TERM ' . (int) $process->getPid());
        $process->wait();

        $this->assertFileDoesNotExist($this->directory . '/called');
        $this->assertSame(143, $process->getExitCode());
    }

    private function runWrapper(int $exitCode): Process
    {
        $process = $this->startWrapper($exitCode);
        $process->wait();

        return $process;
    }

    private function startWrapper(?int $exitCode = null): Process
    {
        $this->writeExecutable($this->directory . '/php', <<<'BASH'
#!/bin/bash
printf '%s\n' "$@" > "$CAPTURE_FILE"
for arg in "$@"; do
    case "$arg" in
        --log=*)
            cp "${arg#--log=}" "$LOG_COPY"
            ;;
    esac
done
exit 0
BASH);

        $env = array_merge(getenv() ?: [], [
            'CONTAINER_ENTRYPOINT_BIN' => $this->directory . '/entrypoint',
            'CONTAINER_PHP_BIN' => $this->directory . '/php',
            'CONTAINER_APP_DIR' => $this->directory,
            'CAPTURE_FILE' => $this->directory . '/called',
            'LOG_COPY' => $this->directory . '/log-copy',
        ]);

        if ($exitCode !== null) {
            $env['FAKE_EXIT_CODE'] = (string) $exitCode;
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGQUIT, SIG_DFL);
        }

        $process = new Process(
            [base_path('scripts/container-entrypoint.sh')],
            base_path(),
            $env,
        );
        $process->setTimeout(10);
        $process->start();

        return $process;
    }

    private function captureLogMessages(): ArrayObject
    {
        $messages = new ArrayObject();

        Event::listen(function (MessageLogged $event) use ($messages) {
            $messages->append($event->message);
        });

        return $messages;
    }

    private function loggedMessage(ArrayObject $messages): string
    {
        foreach ($messages as $message) {
            if (str_contains($message, 'Container exited')) {
                return $message;
            }
        }

        $this->fail('Container failure was not logged.');
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0755);
    }

    private function deleteDirectory(string $directory): void
    {
        if ( ! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
