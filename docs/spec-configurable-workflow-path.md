# Specification: Configurable Workflow Path

This specification describes the changes required to allow users to configure the directory where workflows are stored via an environment variable (`.env`).

## 1. Objectives

- Allow the user to define a custom host directory for workflows.
- Ensure the backend (Laravel) can dynamically load workflows from this configured path.
- Maintain compatibility with Docker by mounting the custom host path to a fixed internal container path.

## 2. Proposed Changes

### 2.1 Backend Configuration (`backend/config/xu-maestro.php`)

Update the configuration to read from the `WORKFLOWS_PATH` environment variable.

```php
// backend/config/xu-maestro.php

return [
    'default_timeout' => 120,
    'workflows_path'  => env('WORKFLOWS_PATH', base_path('../workflows')),
    'runs_path'       => env('RUNS_PATH', base_path('../runs')),
    'prompts_path'    => env('PROMPTS_PATH', base_path('../prompts')),
    'yolo_mode'       => env('XU_MAESTRO_YOLO_MODE', true),
];
```

### 2.2 Docker Compose (`docker-compose.yml`)

Use Docker Compose variable substitution to allow the host path to be dynamic while keeping the container path stable.

```yaml
services:
  backend:
    # ...
    volumes:
      # Use ${VARIABLE:-default} syntax
      - ${WORKFLOWS_PATH:-./workflows}:/workflows
      - ${RUNS_PATH:-./runs}:/runs
      - ${PROMPTS_PATH:-./prompts}:/prompts
      # ...
    environment:
      # Inside the container, we ALWAYS point to the fixed mount points
      - WORKFLOWS_PATH=/workflows
      - RUNS_PATH=/runs
      - PROMPTS_PATH=/prompts
      # ...
```

### 2.3 Environment Variables (`backend/.env.example` and `.env`)

Add the new variables to the environment files.

```bash
# Path to workflows directory (relative to project root or absolute)
WORKFLOWS_PATH=./workflows
RUNS_PATH=./runs
PROMPTS_PATH=./prompts
```

## 3. Implementation Logic

### Case A: Running with Docker
1. The user sets `WORKFLOWS_PATH=/Users/me/my-custom-workflows` in the root `.env`.
2. `docker-compose` reads this and mounts `/Users/me/my-custom-workflows` to `/workflows` inside the container.
3. The `environment` section in `docker-compose.yml` overrides `WORKFLOWS_PATH` to `/workflows` for the Laravel process.
4. Laravel correctly finds the files at `/workflows`.

### Case B: Running Locally (Native)
1. The user sets `WORKFLOWS_PATH=../my-custom-workflows` in `backend/.env`.
2. Laravel reads this path and uses it directly.

## 4. Verification Plan

1. **Local Test:**
   - Change `WORKFLOWS_PATH` in `backend/.env` to a temporary folder.
   - Verify that `php artisan tinker --execute="config('xu-maestro.workflows_path')"` returns the correct path.
2. **Docker Test:**
   - Change `WORKFLOWS_PATH` in the root `.env`.
   - Run `docker-compose up -d`.
   - Verify that the custom directory is correctly mounted inside the container using `docker-compose exec backend ls /workflows`.
   - Verify that the API returns workflows from the new location.
