<?php

namespace App\Drivers;

interface DriverInterface
{
    /**
     * Execute a CLI agent with the given prompt and options.
     *
     * @param  string  $prompt   The prompt/brief to pass to the CLI agent
     * @param  array   $options  Driver-specific options (timeout, system_prompt, etc.)
     * @return string            The raw stdout output from the CLI process
     */
    public function execute(string $prompt, array $options): string;

    /**
     * Cancel a running CLI job by its job ID.
     *
     * @param  string  $jobId  The job identifier returned or tracked by the driver
     * @return void
     */
    public function cancel(string $jobId): void;
}
