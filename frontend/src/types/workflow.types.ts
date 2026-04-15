export interface Agent {
  id: string
  engine: string
  timeout: number
  steps: string[]
}

export interface Workflow {
  name: string
  file: string
  agents: Agent[]
}
