export type AgentStatus = 'idle' | 'working' | 'done' | 'error' | 'skipped' | 'waiting_for_input'

export interface AgentStatusChangedEvent {
  runId: string
  agentId: string
  status: AgentStatus
  step: number
  message: string
  timestamp: string
}

export interface AgentLogLineEvent {
  runId: string
  agentId: string
  line: string
  step: number
  timestamp: string
}

export interface AgentWaitingForInputEvent {
  runId: string
  agentId: string
  question: string
  step: number
  timestamp: string
}

export interface RunCompletedEvent {
  runId: string
  duration: number
  agentCount: number
  status: 'completed'
  runFolder: string
  timestamp: string
}

export interface RunErrorEvent {
  runId: string
  agentId: string
  step: number
  message: string
  checkpointPath: string
  timestamp: string
}

export const SSE_EVENT_TYPES = {
  AGENT_STATUS_CHANGED: 'agent.status.changed',
  AGENT_LOG_LINE: 'agent.log_line',
  AGENT_WAITING_FOR_INPUT: 'agent.waiting_for_input',
  RUN_COMPLETED: 'run.completed',
  RUN_ERROR: 'run.error',
} as const

export type SseEventType = (typeof SSE_EVENT_TYPES)[keyof typeof SSE_EVENT_TYPES]
