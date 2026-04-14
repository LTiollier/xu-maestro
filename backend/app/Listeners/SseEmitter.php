<?php

namespace App\Listeners;

use App\Events\AgentBubble;
use App\Events\AgentLogLine;
use App\Events\AgentStatusChanged;
use App\Events\AgentWaitingForInput;
use App\Events\RunCompleted;
use App\Events\RunError;

class SseEmitter
{
    public function handleAgentStatusChanged(AgentStatusChanged $event): void
    {
        $payload = [
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'status'    => $event->status,
            'step'      => $event->step,
            'message'   => $event->message,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->appendEventToLog($event->runId, 'agent.status.changed', $payload);

        echo "event: agent.status.changed\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
    }

    public function handleAgentLogLine(AgentLogLine $event): void
    {
        $payload = json_encode([
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'line'      => $event->line,
            'step'      => $event->step,
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        echo "event: agent.log_line\n";
        echo "data: {$payload}\n\n";
        flush();
    }

    public function handleAgentBubble(AgentBubble $event): void
    {
        $payload = [
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'message'   => $event->message,
            'step'      => $event->step,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->appendEventToLog($event->runId, 'agent.bubble', $payload);

        echo "event: agent.bubble\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
    }

    public function handleAgentWaitingForInput(AgentWaitingForInput $event): void
    {
        $payload = [
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'question'  => $event->question,
            'step'      => $event->step,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->appendEventToLog($event->runId, 'agent.waiting_for_input', $payload);

        echo "event: agent.waiting_for_input\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
    }

    public function handleRunCompleted(RunCompleted $event): void
    {
        $payload = [
            'runId'      => $event->runId,
            'duration'   => $event->duration,
            'agentCount' => $event->agentCount,
            'status'     => $event->status,
            'runFolder'  => $event->runFolder,
            'timestamp'  => now()->toIso8601String(),
        ];

        $this->appendEventToLog($event->runId, 'run.completed', $payload);

        echo "retry: 30000\n";
        echo "event: run.completed\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
    }

    public function handleRunError(RunError $event): void
    {
        $payload = [
            'runId'          => $event->runId,
            'agentId'        => $event->agentId,
            'step'           => $event->step,
            'message'        => $event->message,
            'checkpointPath' => $event->checkpointPath,
            'timestamp'      => now()->toIso8601String(),
        ];

        $this->appendEventToLog($event->runId, 'run.error', $payload);

        echo "retry: 30000\n";
        echo "event: run.error\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
    }

    private function appendEventToLog(string $runId, string $type, array $payload): void
    {
        $key = "run:{$runId}:event_log";
        $log = cache()->get($key, []);
        $log[] = ['type' => $type, 'payload' => $payload];
        cache()->put($key, $log, 7200);
    }
}
