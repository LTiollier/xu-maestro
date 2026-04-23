export interface Agent {
  id: string
  engine: string
  timeout: number
  steps: string[]
  mandatory?: boolean
  max_retries?: number
  skippable?: boolean
  interactive?: boolean
  system_prompt?: string
  loop?: {
    over: string
    as: string
  }
}

export interface ParallelGroup {
  parallel: Agent[]
}

export type WorkflowStep = Agent | ParallelGroup

export function isParallelGroup(step: WorkflowStep): step is ParallelGroup {
  return 'parallel' in step
}

export function countAgents(steps: WorkflowStep[]): number {
  return steps.reduce((n, s) => n + (isParallelGroup(s) ? s.parallel.length : 1), 0)
}

export interface Workflow {
  name: string
  file: string
  agents: WorkflowStep[]
}
