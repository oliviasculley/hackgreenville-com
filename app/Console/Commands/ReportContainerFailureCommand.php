<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReportContainerFailureCommand extends Command
{
    protected $signature = 'container:report-failure
        {--exit-code=1 : Exit code of the container process}
        {--log= : Path to the captured container output}';

    protected $description = 'Send a container crash to the Slack log channel';

    public function handle(): int
    {
        $exitCode = (string) $this->option('exit-code');
        $environment = getenv('RAILWAY_ENVIRONMENT_NAME') ?: config('app.env');
        $service = getenv('RAILWAY_SERVICE_NAME') ?: '';
        $deployment = getenv('RAILWAY_DEPLOYMENT_ID') ?: '';

        $lines = [
            "Container exited {$exitCode}",
            "Environment: {$environment}",
        ];

        if ($service !== '') {
            $lines[] = "Service: {$service}";
        }

        if ($deployment !== '') {
            $lines[] = "Deployment: {$deployment}";
        }

        $tail = $this->tail((string) $this->option('log'));

        if ($tail !== '') {
            $lines[] = $tail;
        }

        Log::error(implode("\n", $lines));

        return self::SUCCESS;
    }

    private function tail(string $path): string
    {
        if ($path === '' || ! is_file($path)) {
            return '';
        }

        $contents = str_replace("\r", '', (string) file_get_contents($path));
        $max = 3000;

        if (mb_strlen($contents) <= $max) {
            return $contents;
        }

        $contents = mb_substr($contents, -$max);
        $newline = mb_strpos($contents, "\n");

        if ($newline !== false) {
            $contents = mb_substr($contents, $newline + 1);
        }

        return $contents;
    }
}
