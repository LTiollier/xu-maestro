import { useState, useCallback } from 'react'
import type { RunHistoryItem } from '@/types/run.types'

export function useRunHistory() {
  const [runs, setRuns] = useState<RunHistoryItem[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const reload = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    try {
      const res = await fetch('/api/runs')
      if (!res.ok) throw new Error(`Erreur HTTP ${res.status}`)
      const data: RunHistoryItem[] = await res.json()
      setRuns(data)
    } catch (err) {
      if (err instanceof Error && err.name !== 'AbortError') {
        setError('Impossible de charger l\'historique')
      }
    } finally {
      setIsLoading(false)
    }
  }, [])

  return { runs, isLoading, error, reload }
}
