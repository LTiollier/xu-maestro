<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AgentBubble;
use App\Events\AgentLogLine;
use App\Events\AgentStatusChanged;
use App\Events\AgentWaitingForInput;
use App\Events\RunCompleted;
use App\Events\RunError;
use Illuminate\Support\Facades\File;

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
        $this->flushNewLogContent($event->runId);
    }

    public function handleAgentLogLine(AgentLogLine $event): void
    {
        $payload = [
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'line'      => $event->line,
            'step'      => $event->step,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->appendEventToLog($event->runId, 'agent.log_line', $payload);

        echo "event: agent.log_line\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
        $this->flushNewLogContent($event->runId);
    }

    public function handleAgentBubble(AgentBubble $event): void
    {
        $payload = [
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'message'   => $event->message,
            'step'      => $event->step,
            'type'      => $event->type,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->appendEventToLog($event->runId, 'agent.bubble', $payload);

        echo "event: agent.bubble\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
        $this->flushNewLogContent($event->runId);
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
        $this->flushNewLogContent($event->runId);
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

        echo "event: run.completed\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
        $this->flushNewLogContent($event->runId);
    }

    public function handleRunError(RunError $event): void
    {
        $payload = [
            'runId'          => $event->runId,
            'agentId'        => $event->agentId,
            'step'           => $event->step,
            'message'        => $event->message,
            'timestamp'      => now()->toIso8601String(),
        ];

        $this->appendEventToLog($event->runId, 'run.error', $payload);

        echo "event: run.error\n";
        echo "data: " . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        flush();
        $this->flushNewLogContent($event->runId);
    }

    private function appendEventToLog(string $runId, string $type, array $payload): void
    {
        $countKey = "run:{$runId}:event_count";
        cache()->add($countKey, 0, 7200);
        $index = cache()->increment($countKey);
        cache()->put("run:{$runId}:event:{$index}", ['type' => $type, 'payload' => $payload], 7200);
    }

    /**
     * Émet les nouveaux octets de session.md en tant qu'événements log.append.
     * Activé uniquement pour l'endpoint unifié (/runs/{id}/events).
     */
    private function flushNewLogContent(string $runId): void
    {
        if (! cache()->has("run:{$runId}:unified")) {
            return;
        }

        $runPath = cache()->get("run:{$runId}:path");
        if ($runPath === null) {
            return;
        }

        $sessionPath = $runPath . '/session.md';
        if (! File::exists($sessionPath)) {
            return;
        }

        $offsetKey = "run:{$runId}:log_offset";
        $offset    = (int) cache()->get($offsetKey, 0);

        try {
            $content = File::get($sessionPath);
            $chunk   = substr($content, $offset);
            if ($chunk !== '') {
                cache()->put($offsetKey, $offset + strlen($chunk), 7200);
                echo "event: log.append\n";
                echo 'data: ' . json_encode(['chunk' => $chunk], JSON_THROW_ON_ERROR) . "\n\n";
                flush();
            }
        } catch (\Throwable) {
            // Lecture échouée — on ignore ce tick
        }
    }
}
