'use client'

import React, { useEffect, useRef, useState } from 'react'
import { useRunStore } from '@/stores/runStore'
import { useAgentStatusStore } from '@/stores/agentStatusStore'
import { useSSEListener } from '@/hooks/useSSEListener'
import { Terminal as TerminalIcon, CheckCircle2, AlertCircle, Play, Square, History, Loader2, Send, MessageSquare } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { useWorkflowStore } from '@/stores/workflowStore'
import { RunHistory } from '@/components/RunHistory'
import { cn } from '@/lib/utils'

export function Terminal() {
  const { runId, status, retryKey, setRunId, resetRun, setRetrying, duration, runFolder, errorMessage } = useRunStore()
  const { selectedWorkflow } = useWorkflowStore()
  const agentStatuses = useAgentStatusStore((s) => s.agents)
  
  const [logContent, setLogContent] = useState('')
  const [brief, setBrief] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isHistoryOpen, setIsHistoryOpen] = useState(false)
  const [answer, setAnswer] = useState('')
  const [isAnswering, setIsAnswering] = useState(false)
  
  const scrollRef = useRef<HTMLDivElement>(null)
  const bottomRef = useRef<HTMLDivElement>(null)

  // SSE Listener
  useSSEListener(runId, retryKey)

  // Detect agent waiting for input
  const waitingAgentId = Object.keys(agentStatuses).find(id => agentStatuses[id].status === 'waiting_for_input')
  const waitingAgent = waitingAgentId ? agentStatuses[waitingAgentId] : null

  // Update browser tab title based on run status
  useEffect(() => {
    const total = selectedWorkflow?.agents.length ?? 0
    const workingCount = Object.values(agentStatuses).filter(a => a.status === 'working').length
    const hasQuestion = Object.values(agentStatuses).some(a => a.status === 'waiting_for_input')

    let title = 'xu-workflow'
    if (status === 'running' && hasQuestion) {
      title = '🟡 xu-workflow'
    } else if (status === 'running') {
      title = `🟢 ${workingCount}/${total} · xu-workflow`
    } else if (status === 'error') {
      title = '🔴 xu-workflow'
    } else if (status === 'completed') {
      title = '🔵 xu-workflow'
    }

    document.title = title
  }, [status, agentStatuses, selectedWorkflow])

  useEffect(() => {
    if (!runId) {
      setLogContent('')
      return
    }

    let es: EventSource | null = null
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null
    let reconnectAttempts = 0
    let isFirstOpen = true
    let destroyed = false

    const setupEs = () => {
      if (destroyed) return

      es = new EventSource(`/api/runs/${runId}/log-stream`)

      es.onopen = () => {
        if (!isFirstOpen && !destroyed) {
          // Le backend rejoue depuis le début — réinitialiser pour éviter les doublons
          setLogContent('')
        }
        isFirstOpen = false
      }

      es.addEventListener('log.append', (e: MessageEvent) => {
        try {
          const { chunk } = JSON.parse(e.data) as { chunk: string }
          if (!destroyed) setLogContent(prev => prev + chunk)
        } catch {}
      })

      es.addEventListener('log.done', () => es?.close())

      es.onerror = () => {
        if (destroyed) return
        es?.close()
        if (reconnectAttempts < 5) {
          reconnectAttempts++
          const delay = Math.min(1000 * Math.pow(2, reconnectAttempts - 1), 30_000)
          reconnectTimer = setTimeout(setupEs, delay)
        }
      }
    }

    setupEs()

    return () => {
      destroyed = true
      if (reconnectTimer) clearTimeout(reconnectTimer)
      es?.close()
    }
  }, [runId, retryKey])

  useEffect(() => {
    if (status === 'running') {
      bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
    }
  }, [logContent, status, waitingAgentId])

  const handleLancer = async () => {
    if (!selectedWorkflow || !brief.trim() || isSubmitting) return
    setIsSubmitting(true)
    useAgentStatusStore.getState().resetAgents()

    try {
      const res = await fetch('/api/runs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workflowFile: selectedWorkflow.file, brief }),
      })
      const body = await res.json()
      if (body.runId) setRunId(body.runId)
    } catch (e) {
      console.error(e)
    } finally {
      setIsSubmitting(false)
    }
  }

  const handleAnnuler = async () => {
    if (!runId) return
    try {
      const res = await fetch(`/api/runs/${runId}`, { method: 'DELETE' })
      if (res.ok) {
        resetRun()
        useAgentStatusStore.getState().resetAgents()
      }
    } catch (e) {
      console.error(e)
    }
  }

  const handleRetry = async () => {
    if (!runId) return
    try {
      const res = await fetch(`/api/runs/${runId}/retry-step`, { method: 'POST' })
      if (res.ok) {
        setRetrying()
      }
    } catch (e) {
      console.error(e)
    }
  }

  const handleSendAnswer = async () => {
    if (!runId || !waitingAgentId || !answer.trim() || isAnswering) return
    setIsAnswering(true)
    try {
      const res = await fetch(`/api/runs/${runId}/answer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ agentId: waitingAgentId, answer: answer.trim() }),
      })
      if (res.ok) {
        setAnswer('')
      }
    } catch (e) {
      console.error(e)
    } finally {
      setIsAnswering(false)
    }
  }

  const parseLogs = () => {
    if (!logContent) return []
    const parts = logContent.split('---\n## Agent:')
    return parts.slice(1).map(p => {
      const [nameLine, ...rest] = p.split('\n')
      return { name: nameLine.trim(), output: rest.join('\n').trim() }
    })
  }

  const logAgents = parseLogs()

  const renderContent = (content: string) => {
    // Try to parse as JSON if it looks like it
    if (content.trim().startsWith('{')) {
      try {
        const parsed = JSON.parse(content)
        if (parsed.output) {
          return renderContent(parsed.output)
        }
      } catch {
        // Fallback to raw text if parsing fails
      }
    }

    return content.split('\n').map((line, i) => {
      if (line.startsWith('**Question :**')) {
        return (
          <div key={i} className="flex flex-col gap-2 my-4 p-4 rounded-xl bg-blue-500/10 border border-blue-500/20">
            <span className="text-[10px] font-bold text-blue-400 uppercase tracking-widest font-mono">Agent Question</span>
            <span className="text-sm text-blue-100">{line.replace('**Question :**', '').trim()}</span>
          </div>
        )
      }
      if (line.startsWith('**Réponse utilisateur :**')) {
        return (
          <div key={i} className="flex flex-col gap-2 my-4 p-4 rounded-xl bg-violet-500/10 border border-violet-500/20 items-end">
            <span className="text-[10px] font-bold text-violet-400 uppercase tracking-widest font-mono">Your Response</span>
            <span className="text-sm text-violet-100 italic">{line.replace('**Réponse utilisateur :**', '').trim()}</span>
          </div>
        )
      }
      return line ? <div key={i}>{line}</div> : <div key={i} className="h-2" />
    })
  }

  return (
    <div className="flex-1 flex flex-col bg-black min-w-0 overflow-hidden">
      {/* Terminal Header */}
      <div className="h-12 border-b border-zinc-900 bg-[#09090b] flex items-center justify-between px-6 shrink-0">
        <div className="flex items-center gap-2">
          <TerminalIcon className="w-4 h-4 text-zinc-500" />
          <span className="text-[11px] font-bold text-zinc-500 uppercase tracking-widest font-mono">
            {runId ? `RUN: ${runId.slice(0, 8)}` : 'Terminal'}
          </span>
        </div>
        
        <div className="flex items-center gap-4">
          {waitingAgentId && (
            <div className="flex items-center gap-2">
              <MessageSquare className="w-3 h-3 text-amber-500 animate-pulse" />
              <span className="text-[10px] font-bold text-amber-500 uppercase tracking-wider">
                Waiting for you...
              </span>
            </div>
          )}
          {status === 'running' && !waitingAgentId && (
            <div className="flex items-center gap-2">
              <Loader2 className="w-3 h-3 text-blue-500 animate-spin" />
              <span className="text-[10px] font-bold text-blue-500 uppercase tracking-wider animate-pulse">
                Executing...
              </span>
            </div>
          )}
          {status === 'error' && (
            <div className="flex items-center gap-2">
              <AlertCircle className="w-3 h-3 text-red-500" />
              <span className="text-[10px] font-bold text-red-500 uppercase tracking-wider">
                Execution Halted
              </span>
            </div>
          )}
          {status === 'completed' && (
            <div className="flex items-center gap-2">
              <CheckCircle2 className="w-3 h-3 text-emerald-500" />
              <span className="text-[10px] font-bold text-emerald-500 uppercase tracking-wider">
                Completed
              </span>
            </div>
          )}
          <Button
            variant="ghost"
            size="icon"
            onClick={() => setIsHistoryOpen(true)}
            className="h-8 w-8 text-zinc-500 hover:text-white"
          >
            <History className="w-4 h-4" />
          </Button>
        </div>
      </div>

      {/* Main Log Area */}
      <div className="flex-1 overflow-y-auto font-mono text-[13px] p-6 custom-scrollbar" ref={scrollRef}>
        <div className="max-w-4xl mx-auto flex flex-col gap-8">
          {logAgents.length > 0 ? (
            logAgents.map((agent, i) => (
              <div key={i} className="flex flex-col gap-2">
                <div className="flex items-center gap-3 mb-1">
                  <span className="text-blue-500 font-bold">[{agent.name}]</span>
                  <div className="h-px flex-1 bg-zinc-900" />
                </div>
                <div className="text-zinc-300 leading-relaxed whitespace-pre-wrap pl-4 border-l border-zinc-800">
                  {renderContent(agent.output)}
                </div>
              </div>
            ))
          ) : !runId ? (
            <div className="flex-col items-center justify-center py-40 opacity-20 text-center gap-4 flex">
              <TerminalIcon className="w-12 h-12" />
              <p className="text-sm font-mono tracking-tight">
                READY FOR INPUT...
              </p>
            </div>
          ) : null}

          {/* Current Working Agent Live Log */}
          {Object.entries(agentStatuses).map(([id, agent]) => {
            if (agent.status !== 'working' || !agent.liveLogLine) return null
            // Only show live log if not already in logAgents (though names might differ slightly)
            if (logAgents.some(la => la.name === id)) return null
            
            return (
              <div key={id} className="flex flex-col gap-2">
                <div className="flex items-center gap-3 mb-1">
                  <span className="text-blue-400 font-bold">[{id}]</span>
                  <div className="h-px flex-1 bg-zinc-900" />
                  <Loader2 className="w-3 h-3 text-blue-500 animate-spin" />
                </div>
                <div className="text-zinc-400 leading-relaxed whitespace-pre-wrap pl-4 border-l border-zinc-800 italic text-[12px]">
                  {agent.liveLogLine}
                </div>
              </div>
            )
          })}

          {/* Interaction Area: Question from Agent */}
          {waitingAgent && (
            <div className="flex flex-col gap-4">
              <div className="flex items-center gap-3 mb-1">
                <span className="text-amber-500 font-bold">[{waitingAgentId}]</span>
                <div className="h-px flex-1 bg-zinc-900" />
                <MessageSquare className="w-3 h-3 text-amber-500 animate-pulse" />
              </div>
              <div className="pl-4 border-l border-amber-500/30 flex flex-col gap-4">
                <div className="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20">
                  <span className="text-[10px] font-bold text-amber-400 uppercase tracking-widest font-mono block mb-2">Agent Question</span>
                  <div className="text-sm text-amber-100 leading-relaxed whitespace-pre-wrap">
                    {waitingAgent.question}
                  </div>
                </div>

                <div className="relative group">
                  <Textarea
                    value={answer}
                    onChange={(e) => setAnswer(e.target.value)}
                    placeholder="Tape ta réponse ici..."
                    className="w-full bg-zinc-900/50 border-zinc-800 focus:border-amber-500/50 focus:ring-0 text-zinc-200 resize-none h-24 p-4 rounded-xl font-sans text-sm"
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault()
                        handleSendAnswer()
                      }
                    }}
                  />
                  <Button
                    size="icon"
                    onClick={handleSendAnswer}
                    disabled={!answer.trim() || isAnswering}
                    className="absolute bottom-3 right-3 h-10 w-10 rounded-lg bg-amber-600 hover:bg-amber-500 text-white shadow-lg shadow-amber-900/20"
                  >
                    {isAnswering ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                  </Button>
                </div>
              </div>
            </div>
          )}

          {/* Global Error Banner and Retry */}
          {status === 'error' && (
            <div className="p-6 rounded-2xl bg-red-500/5 border border-red-500/20 flex flex-col gap-4">
              <div className="flex items-center gap-3">
                <AlertCircle className="w-5 h-5 text-red-500" />
                <span className="text-sm font-bold text-red-200">Execution Error</span>
              </div>
              <p className="text-sm text-red-400/80 leading-relaxed italic">
                {errorMessage || "Une erreur inconnue s'est produite lors de l'exécution."}
              </p>
              <div className="flex justify-end">
                <Button 
                  onClick={handleRetry}
                  className="bg-red-500 hover:bg-red-400 text-black font-bold h-10 px-6 rounded-lg"
                >
                  [RETRY STEP]
                </Button>
              </div>
            </div>
          )}

          <div ref={bottomRef} className="h-20" />
        </div>
      </div>

      {/* Action Area (Fixed Footer) */}
      <div className="h-24 border-t border-zinc-900 bg-[#09090b] p-4 flex items-center gap-4 shrink-0">
        <div className="flex-1 relative">
          <Textarea
            value={brief}
            onChange={(e) => setBrief(e.target.value)}
            placeholder={selectedWorkflow ? "Décris ta mission..." : "Sélectionne un workflow d'abord"}
            disabled={status === 'running' || !selectedWorkflow}
            className="w-full bg-black border-zinc-800 focus:border-blue-500/50 focus:ring-0 text-zinc-200 resize-none h-14 py-3 rounded-xl font-sans text-sm"
          />
        </div>
        
        <div className="flex flex-col gap-2">
          {status === 'running' ? (
            <Button
              onClick={handleAnnuler}
              variant="destructive"
              className="h-14 px-6 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20"
            >
              <Square className="w-4 h-4 mr-2 fill-current" />
              Stop
            </Button>
          ) : (
            <Button
              onClick={handleLancer}
              disabled={!selectedWorkflow || !brief.trim() || isSubmitting}
              className="h-14 px-8 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold shadow-[0_0_20px_rgba(37,99,235,0.2)]"
            >
              <Play className="w-4 h-4 mr-2 fill-current" />
              Lancer
            </Button>
          )}
        </div>
      </div>

      <RunHistory open={isHistoryOpen} onOpenChange={setIsHistoryOpen} />
    </div>
  )
}
