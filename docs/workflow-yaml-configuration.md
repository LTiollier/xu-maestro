# Spec: XuMaestro Workflow YAML

## Root Schema
- `name`: (string, req) Workflow name.
- `project_path`: (string, req) Absolute path for execution.
- `git_checkpoints`: (object, opt) Global auto-commit config (see Git section).
- `agents`: (array, req) List of agents or `parallel` blocks.

## Agent Definition
- `id`: (string, req) Unique identifier.
- `engine`: `gemini-cli` | `claude-code` | `sub-workflow` (User-only: do not generate automatically).
- `timeout`: (int, default: 120) Seconds before kill.
- `mandatory`: (bool, default: false) Auto-retry on fail.
- `max_retries`: (int, default: 0) Requires `mandatory: true`.
- `skippable`: (bool, default: false) Allows previous agent to trigger `next_action: "skip_next"`.
- `interactive`: (bool, default: false) Enables `waiting_for_input` status.
- `system_prompt`: (string) Role/instructions.
- `system_prompt_file`: (string) (User-only: do not generate automatically). Filename in `prompts/`.
- `steps`: (string[]) Tasks added to context under `## Task`.
- `loop`: (object, opt) Iteration config (see Loops).
- `git_checkpoint`: (bool|object, opt) Agent-level override.

**CRITICAL**: All configuration keys (`skippable`, `mandatory`, `timeout`, `interactive`, etc.) MUST be top-level keys of the agent object. **NEVER** put them inside the `steps` array.

## Parallel Blocks (`parallel:`)
- **Action**: Runs group simultaneously. Waits for all to finish.
- **Context**: All agents get same starting snapshot; outputs merged into `session.md` in YAML order.
- **Failures**: If any `mandatory: true` agent fails, group fails.
- **Constraints**: 
    - No `interactive: true`.
    - No `loop:`.
    - No nesting.
    - Min 2 agents.

## Loops (`loop:`)
- `over`: (string, req) Glob pattern (e.g., `src/*.ts`).
- `as`: (string, req) Variable name (use as `{{ var }}`).
- **Behavior**: Fresh context per iteration. Previous iterations NOT visible. All outputs merged post-loop.
- **Sub-workflow**: Repeats entire sequence for each item.

## Git Checkpoints (Auto-Commit)
- **Workflow-level**: `git_checkpoints: { enabled: true, engine: "...", prompt: "..." }`
- **Agent-level**: `git_checkpoint: true` OR object (overrides global).
- **Workflow**: `git add .` -> IA Message Generation -> `git commit`.

## Sub-Workflow Engine
- `workflow_file`: (string, req) Target YAML in `workflows/`.
- **Prefixing**: Sub-agent IDs become `parent_id--child_id`.
- **Constraint**: No recursion (max 1 level depth).

## Expected Agent Output (JSON)
Agents MUST return valid JSON in their stdout:
```json
{
  "step": "Short summary",
  "status": "done" | "waiting_for_input",
  "output": "Main content",
  "question": "Only if waiting_for_input",
  "next_action": "skip_next" | null,
  "errors": []
}
```

## Best Practices (AI Strategy)
- **`parallel`**: Use for decoupled tech stacks (e.g., `frontend` vs `backend`) or simultaneous documentation/test generation.
- **`loop` over tickets**: Use when `project_path` contains batch tasks (e.g., `tickets/*.md`). Enables scaling without modifying YAML.
- **`mandatory`**: Critical path only (initial scaffolding, core business logic, final build). Avoid on experimental/optional steps.
- **`interactive`**: Trigger when requirements are underspecified, or for high-impact decisions (e.g., choosing a UI library).
- **`skippable`**: Use for "polishing" steps or non-critical linting that should be bypassed if a previous agent identifies a shortcut.
- **`git_checkpoint`**: Enable for traceability. Use at workflow level for safety, or agent level after major architectural changes.
- **`sub-workflow` & `system_prompt_file`**: Restricted. Users manage these; AI must stick to inline `system_prompt` and primary engines.

## Example (Dense)
```yaml
name: "Dev Pipeline"
project_path: "/app"
git_checkpoints: { enabled: true }
agents:
  - id: setup
    engine: gemini-cli
    mandatory: true
    steps: ["Init project"]
  - parallel:
    - id: frontend
      engine: claude-code
      system_prompt: "UI Expert"
      steps: ["Generate UI components"]
    - id: backend
      engine: claude-code
      loop: { over: "api/*.yaml", as: "spec" }
      system_prompt: "API for {{ spec }}"
      steps: ["Implement endpoints"]
  - id: linter
    engine: gemini-cli
    skippable: true
    steps: ["Run linting"]
  - id: review
    engine: sub-workflow
    workflow_file: QA.yaml
```
