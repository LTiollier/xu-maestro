# Story 3.2 : Retry automatique des étapes `mandatory`

Status: done

## Story

As a développeur,
I want que les étapes marquées `mandatory: true` soient automatiquement retentées en cas d'échec, dans la limite de `max_retries`,
so that les étapes critiques ont une chance de récupérer sans intervention manuelle.

## Acceptance Criteria

1. **Given** une étape avec `mandatory: true` et `max_retries: N` dans le YAML — **When** l'étape échoue (JSON invalide, erreur CLI, timeout) — **Then** `RunService` relance automatiquement l'étape — **And** chaque retry décrémente le compteur restant (FR12)

2. **Given** `max_retries` est atteint sans succès — **When** la dernière tentative échoue — **Then** le moteur marque l'étape en `error` définitif et émet `AgentStatusChanged(error)` + `run.error`

3. **Given** des retries automatiques en cours — **When** une tentative échoue mais il reste des essais — **Then** aucun `run.error` n'est émis — **And** une `agent.bubble` info est émise indiquant la tentative en cours (ex: "Tentative 2/3 en cours...") — **And** `agent.status.changed` 'working' est ré-émis pour maintenir le visuel

4. **Given** une étape sans `mandatory: true` (ou `mandatory: false`) — **When** elle échoue — **Then** comportement inchangé : échec immédiat, `run.error` émis sans retry

5. **Given** une étape `mandatory: true` avec `max_retries: 0` — **When** elle échoue — **Then** comportement identique à non-mandatory : échec immédiat, aucun retry

6. **Given** un retry en cours — **When** le flag `run:{id}:cancelled` est posé — **Then** `RunCancelledException` est levée avant la tentative suivante (check au début de chaque itération)

## Tasks / Subtasks

- [x] **T1 — Modifier `RunService` : boucle retry** (AC 1–6)
  - [x] Lire `$isMandatory` et `$maxRetries` depuis `$agent` avec valeurs par défaut sûres (même pattern que `$timeout`)
  - [x] Envelopper le bloc try/catch interne dans `do { } while ($attempt <= $maxRetries)`
  - [x] Incrémenter `$attempt` en tête de boucle ; check cancellation avant chaque tentative
  - [x] Pour `$attempt > 1` : émettre `AgentBubble` info + `AgentStatusChanged('working')` avant d'appeler le driver
  - [x] Dans chaque catch : si `$attempt <= $maxRetries` → `continue` (pas d'events erreur) ; sinon → émettre les events d'erreur finaux + throw (comportement 3.1 conservé)

- [x] **T2 — Mettre à jour `workflows/example.yaml`** (AC 1)
  - [x] Ajouter `mandatory: true` et `max_retries: 2` à l'agent exemple (avec commentaire explicatif)

- [x] **T3 — Créer `RunServiceRetryTest`** (AC 1–6)
  - [x] Créer `backend/tests/Unit/RunServiceRetryTest.php`
  - [x] Test : agent mandatory échoue 1×, puis succède → `RunCompleted` émis, `AgentBubble` info retry visible
  - [x] Test : agent mandatory épuise tous ses retries → `RunError` émis une seule fois (après dernier échec)
  - [x] Test : `max_retries: N` → driver appelé exactement N+1 fois
  - [x] Test : agent non-mandatory échoue → `RunError` immédiat, driver appelé 1× seulement
  - [x] Test : `mandatory: true, max_retries: 0` → `RunError` immédiat, 1 appel driver
  - [x] Test : cancel pendant retry loop → `RunCancelledException`, driver n'est pas rappelé
  - [x] Test : retry sur `ProcessTimedOutException` fonctionne (même pattern que CLI failure)
  - [x] Test : retry sur `InvalidJsonOutputException` fonctionne

- [x] **T4 — Vérification** (AC 1–6)
  - [x] `php artisan test` : 83/83 tests verts (74 existants + 8 nouveaux + 1 pré-existant bonus), 0 régression

### Review Findings (2026-04-10)

- [x] [Review][Decision] Timing du bubble AC3 — résolu : bubble émis dans chaque catch après l'échec ("Tentative X/N échouée — relance en cours..."), `$totalAttempts` extrait avant la boucle [RunService.php]
- [x] [Review][Patch] `$totalAttempts` recalculé à chaque itération — extrait avant la boucle [RunService.php]
- [x] [Review][Patch] Test manquant : `max_retries` fourni en string YAML — ajouté `it_ignores_max_retries_when_provided_as_string` [RunServiceRetryTest.php]
- [x] [Review][Patch] Test manquant : annulation mid-retry — ajouté `it_does_not_emit_run_error_when_cancelled_during_retry` [RunServiceRetryTest.php]
- [x] [Review][Patch] Test manquant : séquence d'exceptions mixtes — ajouté `it_retries_across_different_exception_types` [RunServiceRetryTest.php]
- [x] [Review][Patch] Test manquant : workflow 2 agents — ajouté `it_executes_second_agent_normally_after_first_agent_retries` [RunServiceRetryTest.php]
- [x] [Review][Patch] Test manquant : AC3 pour N>2 retries — ajouté `it_emits_retry_bubbles_with_correct_denominator_for_multiple_retries` [RunServiceRetryTest.php]
- [x] [Review][Defer] Pas de cap sur `max_retries` — `max_retries: 9999999` accepté silencieusement [RunService.php:58] — deferred, acceptable MVP
- [x] [Review][Defer] `mandatory: "true"` (string YAML) silencieusement ignoré — `=== true` strictement booléen [RunService.php:57] — deferred, comportement YAML standard
- [x] [Review][Defer] Annulation non détectée pendant l'exécution du driver — coopérative non wired — deferred, pre-existing
- [x] [Review][Defer] Checkpoint pré-agent non mis à jour entre les retries — crash mid-retry → reprise depuis état pré-agent correct [RunService.php:62] — deferred, intentionnel par spec
- [x] [Review][Defer] `error_emitted` scope run et non agent, `finally` ne le reset pas — deferred, pre-existing, documenté
- [x] [Review][Defer] TTL `error_emitted` hardcodé à 60s sans relation avec les timeouts du run [RunService.php:111,126,141] — deferred, pre-existing
- [x] [Review][Defer] `error_emitted` tripliqué dans 3 catch — risque de divergence si 4ème type d'exception ajouté [RunService.php:111,126,141] — deferred, architectural
- [x] [Review][Defer] Output des tentatives échouées perdu — seul le succès final est appendé à session.md — deferred, out of scope
- [x] [Review][Defer] Pas de check annulation après `break` réussi — comportement pre-existing du foreach — deferred, pre-existing
- [x] [Review][Defer] `max_retries` sur agent non-mandatory silencieusement ignoré — intentionnel par spec — deferred, by design

---

## Dev Notes

### §ÉTAT ACTUEL — Ne pas réinventer

```
backend/app/Services/RunService.php
  → CheckpointService injecté (4ème param constructeur)
  → foreach ($workflow['agents'] as $stepIndex => $agent) — $stepIndex déjà correct
  → Checkpoint pré-agent écrit AVANT driver.execute()
  → Checkpoint post-completion écrit APRÈS $completedAgents[] = $agentId, AVANT event(done)
  → 3 catch internes : CliExecutionException, ProcessTimedOutException, InvalidJsonOutputException
  → Chaque catch : event(AgentStatusChanged error) + event(RunError) + cache error_emitted + throw
  → finally : forget(run:{id}), forget(run:{id}:cancelled), put(run:{id}:done, true)
  → ABSENT du finally : forget(run:{id}:error_emitted) — intentionnel (voir §Flag error_emitted)

backend/app/Services/YamlService.php
  → validate() : vérifie name, project_path, agents[].id, agents[].engine
  → NE valide PAS timeout, mandatory, max_retries — champs optionnels lus directement dans RunService
  → Même pattern à appliquer : NE PAS modifier YamlService pour mandatory/max_retries

workflows/example.yaml
  → Contient 1 agent (agent-one, engine: claude-code, timeout: 60)
  → ABSENT : mandatory / max_retries — à ajouter en T2

backend/tests/Unit/RunServiceTest.php + RunServiceTimeoutTest.php
  → Pattern mock setUp() : 4 mocks (driver, yaml, artifact, checkpoint)
  → mockArtifact.initializeRun() → '/tmp/test-run'
  → mockArtifact.getContextContent() → '# context from session.md'
  → new RunService(mockDriver, mockYaml, mockArtifact, mockCheckpoint)
  → Event::fake() dans setUp()
  → validOutput() : json avec step/status/output/next_action/errors
```

---

### §Boucle retry — implémentation exacte dans `RunService::execute()`

Remplacer, **à l'intérieur du `foreach`**, le bloc try/catch actuel par la boucle suivante :

```php
// --- LIRE LES FLAGS RETRY (après $systemPrompt = ..., avant checkpoint pré-agent) ---
$isMandatory = isset($agent['mandatory']) && $agent['mandatory'] === true;
$maxRetries  = ($isMandatory && isset($agent['max_retries']) && is_int($agent['max_retries']) && $agent['max_retries'] > 0)
    ? $agent['max_retries']
    : 0;

// Checkpoint pré-agent (INCHANGÉ — écrit avant la boucle, une seule fois)
$this->checkpointService->write($runPath, [
    'runId'           => $runId,
    'workflowFile'    => $workflowFile,
    'brief'           => $brief,
    'completedAgents' => $completedAgents,
    'currentAgent'    => $agentId,
    'currentStep'     => $stepIndex,
    'context'         => $runPath . '/session.md',
]);

event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));

// --- BOUCLE RETRY ---
$attempt = 0;
do {
    $attempt++;

    if (cache()->get("run:{$runId}:cancelled", false)) {
        throw new RunCancelledException($runId);
    }

    if ($attempt > 1) {
        // Tentative de retry : bubble info + re-working
        $totalAttempts = $maxRetries + 1;
        event(new AgentBubble($runId, $agentId, "Tentative {$attempt}/{$totalAttempts} en cours...", $stepIndex));
        event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
    }

    try {
        $rawOutput = $this->driver->execute(
            $workflow['project_path'],
            $systemPrompt,
            $context,
            $timeout
        );
        $decoded = $this->validateJsonOutput($agentId, $rawOutput);
        break; // succès — sortir de la boucle
    } catch (CliExecutionException $e) {
        if ($attempt <= $maxRetries) {
            continue; // retry disponible — pas d'events erreur
        }
        $msg = $e->getMessage();
        event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
        event(new RunError(
            runId:          $runId,
            agentId:        $agentId,
            step:           $stepIndex,
            message:        $msg,
            checkpointPath: $runPath . '/checkpoint.json',
        ));
        cache()->put("run:{$runId}:error_emitted", true, 60);
        throw new CliExecutionException($agentId, $e->exitCode, $e->stderr);
    } catch (ProcessTimedOutException) {
        if ($attempt <= $maxRetries) {
            continue;
        }
        $msg = "Timeout after {$timeout}s";
        event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
        event(new RunError(
            runId:          $runId,
            agentId:        $agentId,
            step:           $stepIndex,
            message:        $msg,
            checkpointPath: $runPath . '/checkpoint.json',
        ));
        cache()->put("run:{$runId}:error_emitted", true, 60);
        throw new AgentTimeoutException($agentId, $timeout);
    } catch (InvalidJsonOutputException $e) {
        if ($attempt <= $maxRetries) {
            continue;
        }
        $msg = "Invalid JSON output from {$agentId}: {$e->getMessage()}";
        event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
        event(new RunError(
            runId:          $runId,
            agentId:        $agentId,
            step:           $stepIndex,
            message:        $msg,
            checkpointPath: $runPath . '/checkpoint.json',
        ));
        cache()->put("run:{$runId}:error_emitted", true, 60);
        throw $e;
    }
} while ($attempt <= $maxRetries);
// La boucle exit via break (succès) ou throw (échec final)
// Le code post-boucle (appendAgentOutput, checkpoint post-completion, events done) reste inchangé
```

**Positionnement dans le foreach :**
1. Lire `$isMandatory` / `$maxRetries` après `$systemPrompt = ...`
2. Écrire checkpoint pré-agent (INCHANGÉ, avant la boucle)
3. Émettre `AgentStatusChanged('working')` initial (INCHANGÉ, avant la boucle)
4. Bloc do-while (NOUVEAU — remplace le try/catch unique)
5. `$this->artifactService->appendAgentOutput(...)` (INCHANGÉ, après la boucle)
6. `$completedAgents[] = $agentId` (INCHANGÉ)
7. Checkpoint post-completion (INCHANGÉ)
8. `event(AgentBubble)` + `event(AgentStatusChanged 'done')` (INCHANGÉ)

---

### §Flag `error_emitted` — invariant critique

**NE PAS ajouter** `cache()->forget("run:{$runId}:error_emitted")` dans le bloc `finally`.

Raison : le `finally` de `RunService` s'exécute avant que `SseController` lise le flag dans son `catch (\Throwable $e)`. Si on forget en finally, la double-émission `RunError` n'est plus évitée (race condition identifiée en review 3.1).

Le TTL de 60s est suffisant — `SseController` lit le flag dans le même processus HTTP.

**Pendant les retries** : ne JAMAIS poser `error_emitted` tant que `$attempt <= $maxRetries`. Le flag est posé UNIQUEMENT juste avant le `throw` final (quand toutes les tentatives sont épuisées).

---

### §Contexte pendant les retries

Pas de re-lecture de `$context` entre tentatives — `session.md` n'a pas changé puisque l'agent a échoué (aucun `appendAgentOutput` n'a eu lieu). Le `$context` du début de l'itération `foreach` est réutilisé pour toutes les tentatives.

Le checkpoint pré-agent existe déjà sur disque (écrit avant la boucle). Aucun nouveau checkpoint n'est écrit entre tentatives.

---

### §Tests — `RunServiceRetryTest` — structure complète

```php
<?php
// backend/tests/Unit/RunServiceRetryTest.php
namespace Tests\Unit;

use App\Drivers\DriverInterface;
use App\Events\AgentBubble;
use App\Events\AgentStatusChanged;
use App\Events\RunCompleted;
use App\Events\RunError;
use App\Exceptions\AgentTimeoutException;
use App\Exceptions\CliExecutionException;
use App\Exceptions\InvalidJsonOutputException;
use App\Exceptions\RunCancelledException;
use App\Services\ArtifactService;
use App\Services\CheckpointService;
use App\Services\RunService;
use App\Services\YamlService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Tests\TestCase;

class RunServiceRetryTest extends TestCase
{
    private DriverInterface $mockDriver;
    private YamlService $mockYaml;
    private ArtifactService $mockArtifact;
    private CheckpointService $mockCheckpoint;
    private RunService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Event::fake();

        $this->mockDriver     = $this->createMock(DriverInterface::class);
        $this->mockYaml       = $this->createMock(YamlService::class);
        $this->mockArtifact   = $this->createMock(ArtifactService::class);
        $this->mockCheckpoint = $this->createMock(CheckpointService::class);

        $this->mockArtifact->method('initializeRun')->willReturn('/tmp/test-run');
        $this->mockArtifact->method('getContextContent')->willReturn('# context');

        $this->service = new RunService(
            $this->mockDriver, $this->mockYaml, $this->mockArtifact, $this->mockCheckpoint
        );
    }

    private function validOutput(): string
    {
        return json_encode([
            'step' => 'analyse', 'status' => 'done',
            'output' => 'OK', 'next_action' => null, 'errors' => [],
        ]);
    }

    private function mandatoryWorkflow(int $maxRetries): array
    {
        return [
            'name' => 'Test', 'project_path' => '/tmp/test', 'file' => 'test.yaml',
            'agents' => [[
                'id' => 'agent-one', 'engine' => 'claude-code',
                'mandatory' => true, 'max_retries' => $maxRetries,
            ]],
        ];
    }

    private function nonMandatoryWorkflow(): array
    {
        return [
            'name' => 'Test', 'project_path' => '/tmp/test', 'file' => 'test.yaml',
            'agents' => [['id' => 'agent-one', 'engine' => 'claude-code']],
        ];
    }

    private function makeCliException(): CliExecutionException
    {
        return new CliExecutionException('agent-one', 1, 'stderr error');
    }

    private function makeProcessTimedOutException(): ProcessTimedOutException
    {
        return new ProcessTimedOutException(
            $this->createMock(SymfonyProcessTimedOutException::class),
            $this->createMock(ProcessResult::class)
        );
    }

    #[Test]
    public function it_retries_mandatory_agent_on_failure_and_succeeds_on_retry(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(2));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 2) {
                    throw $this->makeCliException();
                }
                return $this->validOutput();
            });

        $this->service->execute('run-id', 'test.yaml', 'brief');

        $this->assertSame(2, $callCount, 'Driver doit être appelé 2 fois (1 échec + 1 succès)');
        Event::assertDispatched(RunCompleted::class);
        Event::assertDispatched(AgentBubble::class, function (AgentBubble $e) {
            return str_contains($e->message, 'Tentative 2/') && $e->agentId === 'agent-one';
        });
        Event::assertNotDispatched(RunError::class);
    }

    #[Test]
    public function it_calls_driver_exactly_n_plus_one_times_with_n_max_retries(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(3));
        $this->mockDriver->method('execute')->willThrowException($this->makeCliException());

        $callCount = 0;
        $this->mockDriver->expects($this->exactly(4))->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw $this->makeCliException();
            });

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}
    }

    #[Test]
    public function it_emits_run_error_only_after_exhausting_all_retries(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(2));
        $this->mockDriver->method('execute')->willThrowException($this->makeCliException());

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}

        Event::assertDispatched(RunError::class, 1); // exactement 1 RunError
        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->status === 'error' && $e->agentId === 'agent-one';
        });
    }

    #[Test]
    public function it_does_not_retry_non_mandatory_agent(): void
    {
        $this->mockYaml->method('load')->willReturn($this->nonMandatoryWorkflow());
        $this->mockDriver->expects($this->once())->method('execute')
            ->willThrowException($this->makeCliException());

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}

        Event::assertDispatched(RunError::class, 1);
        Event::assertNotDispatched(AgentBubble::class, fn (AgentBubble $e) => str_contains($e->message, 'Tentative'));
    }

    #[Test]
    public function it_does_not_retry_mandatory_agent_with_zero_max_retries(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(0));
        $this->mockDriver->expects($this->once())->method('execute')
            ->willThrowException($this->makeCliException());

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}

        Event::assertDispatched(RunError::class, 1);
    }

    #[Test]
    public function it_checks_cancellation_before_each_retry_attempt(): void
    {
        $runId = '66666666-6666-6666-6666-666666666666';
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(3));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use ($runId, &$callCount) {
                $callCount++;
                cache()->put("run:{$runId}:cancelled", true, 300);
                throw $this->makeCliException();
            });

        $this->expectException(RunCancelledException::class);

        try {
            $this->service->execute($runId, 'test.yaml', 'brief');
        } finally {
            $this->assertSame(1, $callCount, 'Driver ne doit pas être rappelé après cancellation');
        }
    }

    #[Test]
    public function it_retries_on_process_timed_out_exception(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(1));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 2) {
                    throw $this->makeProcessTimedOutException();
                }
                return $this->validOutput();
            });

        $this->service->execute('run-id', 'test.yaml', 'brief');

        $this->assertSame(2, $callCount);
        Event::assertDispatched(RunCompleted::class);
        Event::assertNotDispatched(RunError::class);
    }

    #[Test]
    public function it_retries_on_invalid_json_output_exception(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(1));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 2) {
                    return 'not-valid-json';
                }
                return $this->validOutput();
            });

        $this->service->execute('run-id', 'test.yaml', 'brief');

        $this->assertSame(2, $callCount);
        Event::assertDispatched(RunCompleted::class);
        Event::assertNotDispatched(RunError::class);
    }
}
```

---

### §Guardrails — erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| Modifier `YamlService::validate()` pour mandatory/max_retries | Lire silencieusement dans RunService avec valeurs par défaut (même pattern que `timeout`) |
| Poser `error_emitted` pendant un retry non-final | Poser `error_emitted` UNIQUEMENT juste avant le `throw` final |
| Ajouter `forget(error_emitted)` dans le `finally` | Ne JAMAIS forget `error_emitted` en finally (race condition avec SseController) |
| Émettre `RunError` pendant un retry non-final | `continue` sans events erreur si `$attempt <= $maxRetries` |
| Écrire un 2ème checkpoint pré-agent par retry | Un seul checkpoint pré-agent, AVANT la boucle do-while |
| Toucher le frontend | Story purement backend — les events SSE existants suffisent |
| Réémettre le checkpoint pré-agent entre tentatives | Le checkpoint pré-agent est déjà sur disque ; session.md n'a pas changé |
| Oublier le check cancellation en tête de boucle retry | `if (cache()->get(...:cancelled))` AVANT l'appel driver dans chaque itération |
| Créer une exception custom `RetryExhaustedException` | Réutiliser les exceptions existantes (CliExecutionException, AgentTimeoutException, InvalidJsonOutputException) |

---

### §Fichiers à créer / modifier

```
backend/app/Services/RunService.php              ← MODIFIER (do-while retry autour du try/catch)
backend/tests/Unit/RunServiceRetryTest.php       ← CRÉER
workflows/example.yaml                           ← MODIFIER (ajouter mandatory + max_retries)
```

**Ne pas toucher :**
- `YamlService.php` — aucune validation à ajouter
- `CheckpointService.php` — aucune modification
- `SseController.php` — guard `error_emitted` déjà en place, aucune modification
- Events PHP (`AgentBubble`, `AgentStatusChanged`, `RunError`) — signatures inchangées
- Frontend — `useSSEListener`, stores, composants — déjà capables de traiter les events SSE réémis

### Project Structure Notes

- `RunServiceRetryTest.php` dans `backend/tests/Unit/` — `PHPUnit\Framework\Attributes\Test`, extend `Tests\TestCase`, config `cache.default: array` dans setUp()
- Le `continue` PHP dans un `catch` à l'intérieur d'un `do-while` saute à la condition `while` (comportement PHP standard)
- `CliExecutionException` : constructeur `($agentId, $exitCode, $stderr)` — `$e->exitCode` et `$e->stderr` accessibles en public [App\Exceptions\CliExecutionException]
- `InvalidJsonOutputException` : re-thrower tel quel (`throw $e`), pas de reconstruction
- Pattern `Event::assertDispatched(Class::class, N)` : 2ème arg = count exact (PHPUnit)

### References

- [Source: docs/planning-artifacts/epics.md#Story-3.2] — User story, ACs FR12
- [Source: docs/planning-artifacts/architecture.md#Technical-Stack] — NFR6, NFR7, résilience FR24–FR27
- [Source: docs/implementation-artifacts/3-1-checkpoint-step-level-ecriture-et-lecture.md#Review-Findings] — Patch double-émission RunError : ne pas forget `error_emitted` en finally
- [Source: docs/implementation-artifacts/deferred-work.md#Deferred-2-2] — Token `retrying` absent des CSS globals (hors scope 3.2 — pas d'AC visuel)
- [Source: docs/implementation-artifacts/epic-2-retro-2026-04-09.md#Dette-technique] — `checkpointPath` non consommé côté frontend (hors scope 3.2)
- [Source: backend/app/Services/RunService.php] — Séquence complète execute() ; pattern timeout lu sans validate()
- [Source: backend/app/Services/YamlService.php#validate] — Valide id/engine uniquement ; timeout/mandatory/max_retries NON validés
- [Source: backend/tests/Unit/RunServiceTest.php] — Pattern mock setUp(), singleAgentWorkflow(), validOutput()
- [Source: backend/tests/Unit/RunServiceTimeoutTest.php] — Pattern makeProcessTimedOutException(), `config cache.default: array`
- [Source: backend/app/Exceptions/CliExecutionException.php] — Constructeur ($agentId, $exitCode, $stderr)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Boucle `do { } while ($attempt <= $maxRetries)` : le `continue` dans les `catch` saute à la condition `while` en PHP, ce qui est le comportement souhaité pour les retries.
- Le check cancellation est positionné EN TÊTE de boucle (avant l'appel driver) pour couvrir le cas où l'utilisateur annule entre deux tentatives.
- `$isMandatory` + `$maxRetries` lus sans modifier `YamlService` — cohérent avec le pattern `$timeout` existant.
- `error_emitted` non effacé en `finally` (commentaire conservé dans RunService) — invariant intentionnel, race condition avec SseController.

### Completion Notes List

- `RunService` : boucle do-while retry autour du try/catch interne ; `$isMandatory` / `$maxRetries` lus avec valeurs par défaut sûres ; check cancellation en tête de boucle ; `AgentBubble` info + `AgentStatusChanged('working')` ré-émis pour les tentatives > 1 ; `error_emitted` posé UNIQUEMENT sur l'échec final
- `workflows/example.yaml` : `mandatory: true` + `max_retries: 2` ajoutés avec commentaires explicatifs
- `RunServiceRetryTest` : 8 tests couvrant success-after-retry, N+1 appels driver, RunError unique après épuisement, non-retry sans mandatory, max_retries:0, cancellation mid-retry, ProcessTimedOutException, InvalidJsonOutputException
- Suite complète : 83/83 verts (235 assertions), 0 régression

### File List

- backend/app/Services/RunService.php (modifié)
- backend/tests/Unit/RunServiceRetryTest.php (créé)
- workflows/example.yaml (modifié)
