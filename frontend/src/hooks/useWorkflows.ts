'use client'

import { useEffect, useCallback } from 'react'
import { useWorkflowStore } from '@/stores/workflowStore'
import type { Workflow } from '@/types/workflow.types'

export function useWorkflows() {
  const { setWorkflows, setIsLoading, setError, initialized, setInitialized } = useWorkflowStore()

  const fetchWorkflows = useCallback(async (signal?: AbortSignal) => {
    setIsLoading(true)
    setError(null)
    try {
      const res = await fetch('/api/workflows', { signal })
      if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
      const data: Workflow[] = await res.json()
      setWorkflows(data)
      // Réconciliation : déselectionner si le workflow sélectionné a disparu de la liste
      const { selectedWorkflow, setSelectedWorkflow } = useWorkflowStore.getState()
      if (selectedWorkflow && !data.some((w) => w.file === selectedWorkflow.file)) {
        setSelectedWorkflow(null)
      }
    } catch (err) {
      if (err instanceof Error && err.name === 'AbortError') return
      setError('Impossible de charger les workflows')
    } finally {
      setIsLoading(false)
    }
  }, [setWorkflows, setIsLoading, setError])

  useEffect(() => {
    // Garantit un seul fetch automatique quel que soit le nombre de composants
    // qui appellent useWorkflows() simultanément
    if (initialized) return
    setInitialized(true)
    const controller = new AbortController()
    fetchWorkflows(controller.signal)
    return () => { controller.abort() }
  }, [initialized, setInitialized, fetchWorkflows])

  return { reload: () => fetchWorkflows() }
}
