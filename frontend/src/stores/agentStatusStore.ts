import { create } from 'zustand'
import type { AgentState, AgentStatus } from '@/types/run.types'

const MAX_LIVE_LOG_CHARS = 100_000

interface AgentStatusStoreState {
  agents: Record<string, AgentState>
  setAgentStatus: (agentId: string, status: AgentStatus, step: number, message: string) => void
  setAgentBubble: (agentId: string, message: string) => void
  setAgentLiveLog: (agentId: string, line: string) => void
  setAgentQuestion: (agentId: string, question: string) => void
  resetAgents: (agentIds?: string[]) => void
  loadHistoryAgents: (completedAgents: string[], currentAgent: string | null, runStatus: 'completed' | 'error' | 'cancelled') => void
}

const DEFAULT_AGENT_STATE: AgentState = {
  status: 'idle',
  step: 0,
  bubbleMessage: '',
  errorMessage: '',
  progress: 0,
  currentTask: '',
  question: '',
  liveLogLine: [],
}

export const useAgentStatusStore = create<AgentStatusStoreState>((set) => ({
  agents: {},
  setAgentStatus: (agentId, status, step, message) =>
    set((state) => {
      const currentAgent = state.agents[agentId] ?? DEFAULT_AGENT_STATE
      const isTerminal = status === 'done' || status === 'error' || status === 'skipped' || status === 'waiting_for_input'
      return {
        agents: {
          ...state.agents,
          [agentId]: {
            ...currentAgent,
            status,
            step,
            currentTask: status === 'working' ? message : currentAgent.currentTask,
            errorMessage: status === 'error' ? message : currentAgent.errorMessage,
            question: status === 'waiting_for_input' ? message : currentAgent.question,
            liveLogLine: isTerminal ? [] : currentAgent.liveLogLine,
            // Simple logic: if status is done, progress 100; skipped stays at 0
            progress: status === 'done' ? 100 : (status === 'working' ? Math.max(10, currentAgent.progress) : 0),
          },
        },
      }
    }),
  setAgentLiveLog: (agentId, line) =>
    set((state) => {
      const current = state.agents[agentId]
      // Drop if agent is already in a terminal state (late SSE delivery)
      if (current && current.status !== 'working') return state
      const next = [...(current?.liveLogLine ?? []), line]
      // Cap: drop leading chunks until total is under MAX_LIVE_LOG_CHARS
      let start = 0
      let total = next.reduce((s, c) => s + c.length, 0)
      while (start < next.length - 1 && total > MAX_LIVE_LOG_CHARS) {
        total -= next[start++].length
      }
      return {
        agents: {
          ...state.agents,
          [agentId]: {
            ...(current ?? DEFAULT_AGENT_STATE),
            liveLogLine: start > 0 ? next.slice(start) : next,
          },
        },
      }
    }),
  setAgentBubble: (agentId, message) =>
    set((state) => ({
      agents: {
        ...state.agents,
        [agentId]: {
          ...(state.agents[agentId] ?? DEFAULT_AGENT_STATE),
          bubbleMessage: message,
          currentTask: message, // Sync bubble message with current task for the glimpse
        },
      },
    })),
  setAgentQuestion: (agentId, question) =>
    set((state) => ({
      agents: {
        ...state.agents,
        [agentId]: {
          ...(state.agents[agentId] ?? DEFAULT_AGENT_STATE),
          question,
        },
      },
    })),
  resetAgents: (agentIds) => set((state) => {
    if (!agentIds) return { agents: {} }
    const newAgents = { ...state.agents }
    agentIds.forEach(id => {
      newAgents[id] = DEFAULT_AGENT_STATE
    })
    return { agents: newAgents }
  }),
  loadHistoryAgents: (completedAgents, currentAgent, runStatus) => set(() => {
    const agents: Record<string, AgentState> = {}
    for (const id of completedAgents) {
      agents[id] = { ...DEFAULT_AGENT_STATE, status: 'done', progress: 100 }
    }
    if (currentAgent && !completedAgents.includes(currentAgent)) {
      agents[currentAgent] = {
        ...DEFAULT_AGENT_STATE,
        status: runStatus === 'completed' ? 'done' : 'error',
        progress: runStatus === 'completed' ? 100 : 0,
      }
    }
    return { agents }
  }),
}))
