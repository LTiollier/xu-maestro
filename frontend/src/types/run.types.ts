import type { AgentStatus } from '@/types/sse.types'

export type { AgentStatus } from '@/types/sse.types'
export type RunStatus = 'idle' | 'running' | 'completed' | 'error'

export interface AgentState {
  status: AgentStatus
  question: string
  liveLogLine: string[]
}

export interface RunState {
  runId: string | null
  status: RunStatus
  duration: number | null
  runFolder: string | null
  errorMessage: string | null
}

export interface RunHistoryItem {
  runId: string
  workflowFile: string
  status: 'completed' | 'error' | 'cancelled'
  duration: number | null
  agentCount: number
  runFolder: string
  createdAt: string
  completedAgents: string[]
  currentAgent: string | null
}
