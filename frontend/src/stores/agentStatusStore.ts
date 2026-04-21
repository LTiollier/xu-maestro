import { create } from 'zustand'
import type { AgentState, AgentStatus } from '@/types/run.types'

const MAX_LIVE_LOG_CHARS = 100_000

interface AgentStatusStoreState {
  agents: Record<string, AgentState>
  setAgentStatus: (agentId: string, status: AgentStatus, step: number, message: string) => void
  setAgentLiveLog: (agentId: string, line: string) => void
  setAgentQuestion: (agentId: string, question: string) => void
  resetAgents: (agentIds?: string[]) => void
  loadHistoryAgents: (completedAgents: string[], currentAgent: string | null, runStatus: 'completed' | 'error' | 'cancelled') => void
}

const DEFAULT_AGENT_STATE: AgentState = {
  status: 'idle',
  step: 0,
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
            question: status === 'waiting_for_input' ? message : currentAgent.question,
            liveLogLine: isTerminal ? [] : currentAgent.liveLogLine,
          },
        },
      }
    }),
  setAgentLiveLog: (agentId, line) =>
    set((state) => {
      const current = state.agents[agentId]
      const next = [...(current?.liveLogLine ?? []), line]
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
      agents[id] = { ...DEFAULT_AGENT_STATE, status: 'done' }
    }
    if (currentAgent && !completedAgents.includes(currentAgent)) {
      agents[currentAgent] = {
        ...DEFAULT_AGENT_STATE,
        status: runStatus === 'completed' ? 'done' : 'error',
      }
    }
    return { agents }
  }),
}))
