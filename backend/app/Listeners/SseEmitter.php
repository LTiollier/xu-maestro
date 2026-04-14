<?php

namespace App\Listeners;

use App\Events\AgentBubble;
use App\Events\AgentStatusChanged;
use App\Events\AgentWaitingForInput;
use App\Events\RunCompleted;
use App\Events\RunError;

class SseEmitter
{
    public function handleAgentStatusChanged(AgentStatusChanged $event): void
    {
        $payload = json_encode([
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'status'    => $event->status,
            'step'      => $event->step,
            'message'   => $event->message,
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        echo "event: agent.status.changed\n";
        echo "data: {$payload}\n\n";
        flush();
    }

    public function handleAgentBubble(AgentBubble $event): void
    {
        $payload = json_encode([
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'message'   => $event->message,
            'step'      => $event->step,
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        echo "event: agent.bubble\n";
        echo "data: {$payload}\n\n";
        flush();
    }

    public function handleAgentWaitingForInput(AgentWaitingForInput $event): void
    {
        $payload = json_encode([
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'question'  => $event->question,
            'step'      => $event->step,
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        echo "event: agent.waiting_for_input\n";
        echo "data: {$payload}\n\n";
        flush();
    }

    public function handleRunCompleted(RunCompleted $event): void
    {
        $payload = json_encode([
            'runId'      => $event->runId,
            'duration'   => $event->duration,
            'agentCount' => $event->agentCount,
            'status'     => $event->status,
            'runFolder'  => $event->runFolder,
            'timestamp'  => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        echo "retry: 30000\n";
        echo "event: run.completed\n";
        echo "data: {$payload}\n\n";
        flush();
    }

    public function handleRunError(RunError $event): void
    {
        $payload = json_encode([
            'runId'          => $event->runId,
            'agentId'        => $event->agentId,
            'step'           => $event->step,
            'message'        => $event->message,
            'checkpointPath' => $event->checkpointPath,
            'timestamp'      => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        echo "retry: 30000\n";
        echo "event: run.error\n";
        echo "data: {$payload}\n\n";
        flush();
    }
}
