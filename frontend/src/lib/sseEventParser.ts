import type {
  AgentStatusChangedEvent,
  AgentLogLineEvent,
  AgentWaitingForInputEvent,
  RunCompletedEvent,
  RunErrorEvent,
} from '@/types/sse.types'

const VALID_AGENT_STATUSES = ['idle', 'working', 'done', 'error', 'waiting_for_input'] as const

export function parseAgentStatusChanged(raw: string): AgentStatusChangedEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || !data.agentId) return null
    if (!VALID_AGENT_STATUSES.includes(data.status)) return null
    return data as AgentStatusChangedEvent
  } catch {
    return null
  }
}

export function parseAgentLogLine(raw: string): AgentLogLineEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || !data.agentId || !data.line) return null
    return data as AgentLogLineEvent
  } catch {
    return null
  }
}

export function parseAgentWaitingForInput(raw: string): AgentWaitingForInputEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || !data.agentId || !data.question) return null
    return data as AgentWaitingForInputEvent
  } catch {
    return null
  }
}

export function parseRunCompleted(raw: string): RunCompletedEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || data.duration === undefined) return null
    return data as RunCompletedEvent
  } catch {
    return null
  }
}

export function parseRunError(raw: string): RunErrorEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || !data.message) return null
    return data as RunErrorEvent
  } catch {
    return null
  }
}
