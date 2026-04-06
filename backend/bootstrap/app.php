<?php

use App\Exceptions\AgentTimeoutException;
use App\Exceptions\CliExecutionException;
use App\Exceptions\InvalidJsonOutputException;
use App\Exceptions\RunCancelledException;
use App\Exceptions\YamlLoadException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (InvalidJsonOutputException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'INVALID_JSON_OUTPUT',
            ], 422);
        });

        $exceptions->render(function (CliExecutionException $e, Request $request) {
            return response()->json([
                'message' => "CLI execution failed for agent '{$e->agentId}' (exit code {$e->exitCode}): " . mb_substr($e->stderr, 0, 200),
                'code'    => 'CLI_EXECUTION_FAILED',
            ], 500);
        });

        $exceptions->render(function (YamlLoadException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'YAML_INVALID',
            ], 422);
        });

        $exceptions->render(function (AgentTimeoutException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'AGENT_TIMEOUT',
            ], 504);
        });

        $exceptions->render(function (RunCancelledException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'RUN_CANCELLED',
            ], 409);
        });
    })->create();
