import { create } from 'zustand'
import type { RunStatus } from '@/types/run.types'

interface RunStoreState {
  runId: string | null
  status: RunStatus
  duration: number | null
  runFolder: string | null
  errorMessage: string | null
  retryKey: number
  setRunId: (runId: string | null) => void
  setRunCompleted: (duration: number, runFolder: string) => void
  setRunError: (message: string) => void
  setRetrying: () => void
  resetRun: () => void
  loadHistoryRun: (runId: string, status: 'completed' | 'error' | 'cancelled', duration: number | null, runFolder: string) => void
}

export const useRunStore = create<RunStoreState>((set) => ({
  runId: null,
  status: 'idle',
  duration: null,
  runFolder: null,
  errorMessage: null,
  retryKey: 0,
  setRunId: (runId) => set({ runId, status: runId ? 'running' : 'idle' }),
  setRunCompleted: (duration, runFolder) => set({ status: 'completed', duration, runFolder, errorMessage: null }),
  setRunError: (message) => set({ status: 'error', errorMessage: message }),
  setRetrying: () => set((state) => ({
    status: 'running',
    errorMessage: null,
    retryKey: state.retryKey + 1,
  })),
  resetRun: () => set({
    runId: null,
    status: 'idle',
    duration: null,
    runFolder: null,
    errorMessage: null,
    retryKey: 0,
  }),
  loadHistoryRun: (runId, historyStatus, duration, runFolder) => set({
    runId,
    status: historyStatus === 'completed' ? 'completed' : 'error',
    duration,
    runFolder,
    errorMessage: null,
    retryKey: 0,
  }),
}))
