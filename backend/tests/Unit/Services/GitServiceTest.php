<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Drivers\DriverResolver;
use App\Drivers\GeminiDriver;
use App\Services\GitService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use Mockery;

class GitServiceTest extends TestCase
{
    private GitService $gitService;
    private $driverResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driverResolver = Mockery::mock(DriverResolver::class);
        $this->gitService = new GitService($this->driverResolver);
    }

    public function test_is_enabled_returns_true_when_global_is_enabled(): void
    {
        $workflow = ['git_checkpoints' => ['enabled' => true]];
        $agent = [];

        $this->assertTrue($this->gitService->isEnabled($workflow, $agent));
    }

    public function test_is_enabled_returns_false_when_global_is_disabled(): void
    {
        $workflow = ['git_checkpoints' => ['enabled' => false]];
        $agent = [];

        $this->assertFalse($this->gitService->isEnabled($workflow, $agent));
    }

    public function test_agent_config_overrides_global_config(): void
    {
        $workflow = ['git_checkpoints' => ['enabled' => false]];
        $agent = ['git_checkpoint' => true];
        $this->assertTrue($this->gitService->isEnabled($workflow, $agent));

        $workflow = ['git_checkpoints' => ['enabled' => true]];
        $agent = ['git_checkpoint' => false];
        $this->assertFalse($this->gitService->isEnabled($workflow, $agent));
    }

    public function test_agent_detailed_config_overrides_global_config(): void
    {
        $workflow = ['git_checkpoints' => ['enabled' => false]];
        $agent = ['git_checkpoint' => ['enabled' => true]];
        $this->assertTrue($this->gitService->isEnabled($workflow, $agent));
    }

    public function test_generate_commit_message_calls_driver(): void
    {
        $path = '/tmp/repo';
        $agentOutput = 'Modified files';
        $workflow = ['git_checkpoints' => ['enabled' => true, 'engine' => 'gemini-cli']];
        $agent = ['engine' => 'gemini-cli'];

        Process::fake([
            'git diff --cached' => Process::result('diff output'),
        ]);

        $driver = Mockery::mock(\App\Drivers\DriverInterface::class);
        $driver->shouldReceive('execute')
            ->once()
            ->with($path, Mockery::type('string'), '', 60)
            ->andReturn('  :sparkles: feat: update tests  ');

        $this->driverResolver->shouldReceive('for')->with('gemini-cli')->andReturn($driver);

        $message = $this->gitService->generateCommitMessage($path, $agentOutput, $workflow, $agent);

        $this->assertSame(':sparkles: feat: update tests', $message);
    }
}
