<?php

namespace App\Providers;

use App\Events\AgentBubble;
use App\Events\AgentStatusChanged;
use App\Events\AgentWaitingForInput;
use App\Events\RunCompleted;
use App\Events\RunError;
use App\Listeners\SseEmitter;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Event::listen(AgentStatusChanged::class, [SseEmitter::class, 'handleAgentStatusChanged']);
        Event::listen(AgentBubble::class, [SseEmitter::class, 'handleAgentBubble']);
        Event::listen(RunCompleted::class, [SseEmitter::class, 'handleRunCompleted']);
        Event::listen(RunError::class, [SseEmitter::class, 'handleRunError']);
        Event::listen(AgentWaitingForInput::class, [SseEmitter::class, 'handleAgentWaitingForInput']);
    }
}
