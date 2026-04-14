---
title: 'Streaming live logs des agents dans RunSidebar'
type: 'feature'
created: '2026-04-14'
status: 'done'
baseline_commit: '412c772d30c64b09f9a9c2ba902f582f16ae6d4c'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Pendant l'exécution d'un agent, l'utilisateur attend en silence : aucun feedback n'est affiché dans le terminal output jusqu'à la complétion de l'agent, rendant les longues exécutions opaques.

**Approach:** Streamer le stdout de Claude CLI ligne par ligne via un nouveau SSE `agent.log_line` ; afficher la dernière ligne en italique dans RunSidebar avec une animation CSS fade-swap entre chaque nouvelle ligne.

## Boundaries & Constraints

**Always:**
- Le format de sortie finale et le parsing `validateJsonOutput` sont inchangés.
- `GeminiDriver` reste fonctionnel — il accepte le paramètre callback mais ne l'appelle pas.
- La zone live est purement transiente : elle disparaît dès que l'agent passe à `done`/`error`/`skipped`.
- Aucun changement aux `AgentCard` ni aux `BubbleBox` existants.

**Ask First:**
- Si `--output-format stream-json` ne produit pas de ligne `type=result` contenant le JSON attendu par `validateJsonOutput` (à vérifier en test manuel), HALT avant d'implémenter la nouvelle extraction du résultat final.

**Never:**
- Ne pas accumuler les lignes live (une seule visible à la fois).
- Ne pas modifier les événements SSE existants (`agent.bubble`, `agent.status.changed`).
- Ne pas changer le comportement du polling `session.md` dans RunSidebar.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Claude stream normal | Agent `working`, Claude émet des blocs texte | Chaque bloc → `agent.log_line` SSE → zone live mise à jour avec fade | N/A |
| Gemini agent | Driver `gemini`, callback non appelé | Aucun `agent.log_line` émis, zone live reste vide | N/A |
| Agent sans output texte (only tool calls) | Claude n'émet que des appels d'outil | Aucun `agent.log_line` émis | Zone live affiche le spinner par défaut |
| Agent complété | `agent.status.changed` → `done` | `liveLogLine` effacé, bloc final apparaît dans RunSidebar | N/A |
| Ligne partielle dans buffer | Chunk TCP coupé au milieu d'un JSONL | Buffering côté PHP : attendre `\n` avant de parser | Ligne malformée ignorée silencieusement |

</frozen-after-approval>

## Code Map

- `backend/app/Drivers/DriverInterface.php` — contrat de l'interface, ajouter `?callable $onOutput = null`
- `backend/app/Drivers/ClaudeDriver.php` — switch `--output-format stream-json`, JSONL buffer + callback, extract résultat final
- `backend/app/Drivers/GeminiDriver.php` — ajouter `?callable $onOutput = null` (no-op)
- `backend/app/Events/AgentLogLine.php` — nouvel event avec `runId`, `agentId`, `line`, `step`
- `backend/app/Listeners/SseEmitter.php` — ajouter `handleAgentLogLine()` émettant `agent.log_line`
- `backend/app/Services/RunService.php` — passer le callback `fn($line) => event(new AgentLogLine(...))` à `driver->execute()`
- `backend/app/Providers/AppServiceProvider.php` — enregistrer `AgentLogLine → SseEmitter::handleAgentLogLine`
- `frontend/src/types/sse.types.ts` — ajouter `AGENT_LOG_LINE` et interface `AgentLogLineEvent`
- `frontend/src/lib/sseEventParser.ts` — ajouter `parseAgentLogLine()`
- `frontend/src/stores/agentStatusStore.ts` — ajouter `liveLogLine` à `AgentState`, action `setAgentLiveLog()`
- `frontend/src/hooks/useSSEListener.ts` — écouter `agent.log_line`, appeler `setAgentLiveLog`
- `frontend/src/components/RunSidebar.tsx` — zone live en haut du scroll area (visible si agent `working`), italic + fade animation

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Drivers/DriverInterface.php` -- ajouter `?callable $onOutput = null` à la signature `execute()` -- rétrocompatibilité obligatoire
- [x] `backend/app/Drivers/ClaudeDriver.php` -- ajouter `--output-format stream-json` à la commande ; utiliser `Process::run($cmd, callback)` avec buffer JSONL ; extraire blocs `type=assistant/text` pour appeler `$onOutput` ; extraire `type=result` pour retourner le résultat final ; conserver le fallback `$result->output()` si aucun `result` trouvé
- [x] `backend/app/Drivers/GeminiDriver.php` -- ajouter `?callable $onOutput = null` sans logique supplémentaire
- [x] `backend/app/Events/AgentLogLine.php` -- créer l'event `readonly class AgentLogLine` avec champs `runId`, `agentId`, `line`, `step`
- [x] `backend/app/Listeners/SseEmitter.php` -- ajouter `handleAgentLogLine(AgentLogLine $event)` : émettre `event: agent.log_line` avec payload JSON `{runId, agentId, line, step, timestamp}`
- [x] `backend/app/Services/RunService.php` -- dans `executeAgents()`, construire le callback `$logCallback = fn(string $line) => event(new AgentLogLine($runId, $agentId, $line, $stepIndex))` et le passer à `$driver->execute()`
- [x] `backend/app/Providers/AppServiceProvider.php` -- enregistrer `Event::listen(AgentLogLine::class, [SseEmitter::class, 'handleAgentLogLine'])`
- [x] `frontend/src/types/sse.types.ts` -- ajouter `AGENT_LOG_LINE: 'agent.log_line'` dans `SSE_EVENT_TYPES` et l'interface `AgentLogLineEvent { runId, agentId, line, step, timestamp }`
- [x] `frontend/src/lib/sseEventParser.ts` -- ajouter `parseAgentLogLine(raw: string): AgentLogLineEvent | null`
- [x] `frontend/src/stores/agentStatusStore.ts` -- ajouter `liveLogLine: string` à `AgentState` (défaut `''`) ; action `setAgentLiveLog(agentId, line)` ; dans `setAgentStatus`, vider `liveLogLine` si status passe à `done`/`error`/`skipped`
- [x] `frontend/src/hooks/useSSEListener.ts` -- ajouter listener pour `AGENT_LOG_LINE`, appeler `useAgentStatusStore.getState().setAgentLiveLog(payload.agentId, payload.line)`
- [x] `frontend/src/components/RunSidebar.tsx` -- si status `running` et qu'un agent est `working` avec `liveLogLine` non vide, afficher une zone live sous le header : nom de l'agent + texte en italic avec `key={liveLogLine}` + `animate-in fade-in duration-300` pour l'effet de remplacement

**Acceptance Criteria:**
- Given un agent Claude est `working`, when il émet des blocs texte, then des événements `agent.log_line` sont reçus via SSE et la zone live de RunSidebar se met à jour avec le dernier texte en italique
- Given une nouvelle ligne live arrive, when elle remplace la précédente, then le `key` prop change → React recrée l'élément → `animate-in fade-in` produit l'effet de fade
- Given un agent passe à `done`, when RunSidebar se re-render, then la zone live disparaît et seul le bloc final (depuis session.md polling) est affiché
- Given un agent Gemini est `working`, when il s'exécute, then aucun `agent.log_line` n'est émis et aucune erreur ne survient

## Design Notes

**Extraction du résultat final depuis stream-json :**
```php
// Dans le callback Process::run() :
$buffer .= $chunk; // accumuler
while (($pos = strpos($buffer, "\n")) !== false) {
    $line = trim(substr($buffer, 0, $pos));
    $buffer = substr($buffer, $pos + 1);
    $data = json_decode($line, true);
    if (!$data) continue;
    if ($data['type'] === 'assistant') {
        foreach ($data['message']['content'] ?? [] as $block) {
            if ($block['type'] === 'text' && $onOutput && trim($block['text'])) {
                $onOutput(trim($block['text']));
            }
        }
    }
    if ($data['type'] === 'result') {
        $finalResult = $data['result'] ?? null;
    }
}
// Après Process::run() :
return $finalResult ?? $result->output(); // fallback si pas de result event
```

**Fade animation côté React :**
Utiliser `key={liveLogLine}` sur l'élément contenant le texte italic. Chaque changement de valeur recrée le nœud DOM, déclenchant Tailwind `animate-in fade-in duration-300`. Pas de bibliothèque d'animation supplémentaire nécessaire.

## Verification

**Manual checks:**
- Lancer un workflow avec un agent Claude et observer que des lignes de log apparaissent progressivement en italic dans RunSidebar, en se remplaçant avec un fade visible
- Vérifier qu'à la fin du run, la zone live a disparu et que les blocs finaux s'affichent normalement
- Lancer un workflow avec un agent Gemini et vérifier l'absence d'erreurs

## Suggested Review Order

**Streaming backend — cœur du changement**

- Point d'entrée : logic JSONL + extraction `result` + try/catch `$onOutput`
  [`ClaudeDriver.php:22`](../../backend/app/Drivers/ClaudeDriver.php#L22)

- Sentinel `$resultFound` distingue "pas de ligne result" vs "result null"
  [`ClaudeDriver.php:79`](../../backend/app/Drivers/ClaudeDriver.php#L79)

- Flush du buffer final après process (EOF sans `\n`)
  [`ClaudeDriver.php:71`](../../backend/app/Drivers/ClaudeDriver.php#L71)

**Propagation du callback dans l'exécution**

- Construction et injection de `$logCallback` dans la boucle retry
  [`RunService.php:197`](../../backend/app/Services/RunService.php#L197)

- Nouvel event `AgentLogLine` émis depuis le callback
  [`AgentLogLine.php:1`](../../backend/app/Events/AgentLogLine.php#L1)

- Handler SSE : `echo agent.log_line` + `flush()`
  [`SseEmitter.php:29`](../../backend/app/Listeners/SseEmitter.php#L29)

**Store et réception SSE frontend**

- `setAgentLiveLog` avec guard état terminal (évite les late deliveries)
  [`agentStatusStore.ts:47`](../../frontend/src/stores/agentStatusStore.ts#L47)

- `liveLogLine` effacé sur `done`/`error`/`skipped`/`waiting_for_input`
  [`agentStatusStore.ts:41`](../../frontend/src/stores/agentStatusStore.ts#L41)

- Listener SSE `agent.log_line` dans le hook
  [`useSSEListener.ts:37`](../../frontend/src/hooks/useSSEListener.ts#L37)

**UI — zone live avec fade**

- Zone live : `key={liveLineKey.current}` + `animate-in fade-in duration-300`
  [`RunSidebar.tsx:117`](../../frontend/src/components/RunSidebar.tsx#L117)

- Compteur de clé incrémenté uniquement quand la ligne change (pas à chaque render)
  [`RunSidebar.tsx:20`](../../frontend/src/components/RunSidebar.tsx#L20)

**Périphériques**

- Interface mise à jour : `?callable $onOutput = null` optionnel
  [`DriverInterface.php:16`](../../backend/app/Drivers/DriverInterface.php#L16)

- GeminiDriver : param accepté, no-op
  [`GeminiDriver.php:11`](../../backend/app/Drivers/GeminiDriver.php#L11)

- Types SSE et parser frontend
  [`sse.types.ts:17`](../../frontend/src/types/sse.types.ts#L17)
  [`sseEventParser.ts:30`](../../frontend/src/lib/sseEventParser.ts#L30)
