import { create } from 'zustand'
import type { Workflow } from '@/types/workflow.types'

interface WorkflowState {
  workflows: Workflow[]
  selectedWorkflow: Workflow | null
  isLoading: boolean
  error: string | null
  initialized: boolean
  setWorkflows: (workflows: Workflow[]) => void
  setSelectedWorkflow: (workflow: Workflow | null) => void
  setIsLoading: (isLoading: boolean) => void
  setError: (error: string | null) => void
  setInitialized: (initialized: boolean) => void
}

export const useWorkflowStore = create<WorkflowState>((set) => ({
  workflows: [],
  selectedWorkflow: null,
  isLoading: false,
  error: null,
  initialized: false,
  setWorkflows: (workflows) => set({ workflows }),
  setSelectedWorkflow: (selectedWorkflow) => set({ selectedWorkflow }),
  setIsLoading: (isLoading) => set({ isLoading }),
  setError: (error) => set({ error }),
  setInitialized: (initialized) => set({ initialized }),
}))
