import { useEffect, useState } from 'react'
import { useAgentStatusStore } from '@/stores/agentStatusStore'
import { useRunStore } from '@/stores/runStore'
import { SSE_EVENT_TYPES } from '@/types/sse.types'
import {
  parseAgentStatusChanged,
  parseAgentLogLine,
  parseAgentWaitingForInput,
  parseRunCompleted,
  parseRunError,
} from '@/lib/sseEventParser'

type ConnectionStatus = 'idle' | 'connected' | 'reconnecting' | 'error'

const MAX_RECONNECT = 5
const MAX_LOG_CHARS = 500_000

export function useSSEListener(
  runId: string | null,
  retryKey = 0,
): { connectionStatus: ConnectionStatus; logChunks: string[] } {
  const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>('idle')
  const [logChunks, setLogChunks] = useState<string[]>([])

  useEffect(() => {
    if (!runId) {
      setLogChunks([])
      setConnectionStatus('idle')
      return
    }

    const currentStatus = useRunStore.getState().status
    const abortController = new AbortController()

    // Cas d'un run chargé depuis l'historique (déjà terminé/en erreur/annulé)
    if (currentStatus !== 'running' && currentStatus !== 'idle') {
      setLogChunks([])
      const fetchLogs = async () => {
        try {
          const res = await fetch(`/api/runs/${runId}/logs`, {
            signal: abortController.signal,
          })
          if (res.ok) {
            const data = await res.json()
            setLogChunks([data.logs])
            setConnectionStatus('idle')
          } else {
            setConnectionStatus('error')
          }
        } catch (e) {
          if ((e as Error).name !== 'AbortError') {
            console.error('[useSSEListener] Failed to fetch historical logs', e)
            setConnectionStatus('error')
          }
        }
      }
      fetchLogs()
      return () => {
        abortController.abort()
      }
    }

    // Cas d'un run actif (idle is a transition state just before running)
    // If it's already running or about to, we want SSE.

    let es: EventSource | null = null
    let reconnectAttempts = 0
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null
    let destroyed = false
    let isFirstOpen = true

    // Réinitialiser le log à chaque nouveau run ou retry
    setLogChunks([])

    const setupEventSource = () => {
      if (destroyed) return

      es = new EventSource(`/api/runs/${runId}/events`)

      es.onopen = () => {
        if (!destroyed) {
          if (!isFirstOpen) {
            // Reconnexion : le backend rejoue depuis le début — réinitialiser pour éviter les doublons
            setLogChunks([])
          }
          isFirstOpen = false
          setConnectionStatus('connected')
          reconnectAttempts = 0
        }
      }

      es.addEventListener(SSE_EVENT_TYPES.AGENT_STATUS_CHANGED, (e: MessageEvent) => {
        const payload = parseAgentStatusChanged(e.data)
        if (!payload) return
        useAgentStatusStore.getState().setAgentStatus(
          payload.agentId,
          payload.status,
          payload.step,
          payload.message,
        )
      })

      es.addEventListener(SSE_EVENT_TYPES.AGENT_LOG_LINE, (e: MessageEvent) => {
        const payload = parseAgentLogLine(e.data)
        if (!payload) return
        useAgentStatusStore.getState().setAgentLiveLog(payload.agentId, payload.line)
      })

      es.addEventListener(SSE_EVENT_TYPES.AGENT_WAITING_FOR_INPUT, (e: MessageEvent) => {
        const payload = parseAgentWaitingForInput(e.data)
        if (!payload) return
        useAgentStatusStore.getState().setAgentQuestion(payload.agentId, payload.question)
        useAgentStatusStore.getState().setAgentStatus(
          payload.agentId,
          'waiting_for_input',
          payload.step,
          payload.question,
        )
      })

      es.addEventListener(SSE_EVENT_TYPES.RUN_COMPLETED, (e: MessageEvent) => {
        const payload = parseRunCompleted(e.data)
        if (!payload) return
        useRunStore.getState().setRunCompleted(payload.duration, payload.runFolder)
        es?.close()
        if (!destroyed) setConnectionStatus('idle')
      })

      es.addEventListener(SSE_EVENT_TYPES.RUN_ERROR, (e: MessageEvent) => {
        const payload = parseRunError(e.data)
        if (!payload) return
        if (payload.agentId) {
          useAgentStatusStore.getState().setAgentStatus(
            payload.agentId,
            'error',
            payload.step,
            payload.message,
          )
        }
        useRunStore.getState().setRunError(payload.message)
        es?.close()
        if (!destroyed) setConnectionStatus('error')
      })

      es.addEventListener('log.append', (e: MessageEvent) => {
        try {
          const { chunk } = JSON.parse(e.data) as { chunk: string }
          if (!destroyed) {
            setLogChunks(prev => {
              const next = [...prev, chunk]
              // Cap: drop leading chunks until total is under MAX_LOG_CHARS
              let start = 0
              let total = next.reduce((s, c) => s + c.length, 0)
              while (start < next.length - 1 && total > MAX_LOG_CHARS) {
                total -= next[start++].length
              }
              return start > 0 ? next.slice(start) : next
            })
          }
        } catch (e) {
          console.error('[SSE log.append] Failed to parse event data', e)
        }
      })

      es.addEventListener('log.done', () => {
        es?.close()
        if (!destroyed) setConnectionStatus('idle')
      })

      es.onerror = () => {
        if (destroyed) return

        // Run déjà terminal (run.completed/run.error déjà reçu) — fermer proprement
        if (useRunStore.getState().status !== 'running') {
          es?.close()
          return
        }

        es?.close()

        if (reconnectAttempts < MAX_RECONNECT) {
          reconnectAttempts++
          const delay = Math.min(1000 * Math.pow(2, reconnectAttempts - 1), 30_000)
          setConnectionStatus('reconnecting')
          reconnectTimer = setTimeout(setupEventSource, delay)
        } else {
          // Abandon après MAX_RECONNECT tentatives — signaler l'erreur
          const agents = useAgentStatusStore.getState().agents
          const workingEntry = Object.entries(agents).find(([, s]) => s.status === 'working')
          if (workingEntry) {
            const [agentId] = workingEntry
            useAgentStatusStore.getState().setAgentStatus(agentId, 'error', 0, 'Connexion SSE perdue')
          } else {
            useRunStore.getState().setRunError('Connexion SSE perdue')
          }
          setConnectionStatus('error')
        }
      }
    }

    // Small delay so the backend SSE handler is registered before the client connects.
    // Without it, the EventSource request can arrive before the run's event stream is ready,
    // causing an immediate onerror and a needless reconnect cycle.
    const SSE_CONNECT_DELAY_MS = 100
    const timeoutId = setTimeout(setupEventSource, SSE_CONNECT_DELAY_MS)

    return () => {
      destroyed = true
      clearTimeout(timeoutId)
      if (reconnectTimer) clearTimeout(reconnectTimer)
      es?.close()
      abortController.abort()
    }
  }, [runId, retryKey])

  return { connectionStatus, logChunks }
}
