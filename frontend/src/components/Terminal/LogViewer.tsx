'use client'

import React, { useMemo } from 'react'
import dynamic from 'next/dynamic'
import { Terminal as TerminalIcon, Loader2, Play, Pause } from 'lucide-react'
import type { AgentState, RunStatus } from '@/types/run.types'
import type { Workflow } from '@/types/workflow.types'
import { isParallelGroup } from '@/types/workflow.types'
import { ErrorBanner } from './ErrorBanner'
import { cn } from '@/lib/utils'

const QuestionInteraction = dynamic(
  () => import('@/components/QuestionInteraction'),
  { ssr: false },
)

// --- Exported types (used by Terminal.tsx) ---

export interface LogAgent {
  name: string
  output: string
}

export type LogStep =
  | { type: 'sequential'; name: string; output: string }
  | { type: 'parallel'; agents: LogAgent[] }

// --- AgentOutputBlock ---

const AgentOutputBlock = React.memo(function AgentOutputBlock({ content }: { content: string }) {
  if (content.trim().startsWith('{')) {
    try {
      const parsed = JSON.parse(content)
      if (parsed.output) {
        return <AgentOutputBlock content={parsed.output} />
      }
    } catch {
      // fallback to raw text
    }
  }

  return (
    <>
      {content.split('\n').map((line, i) => {
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
      })}
    </>
  )
})

// --- Block sub-components ---

const CompletedAgentBlock = React.memo(function CompletedAgentBlock({ name, output }: LogAgent) {
  return (
    <div className="flex flex-col gap-2 min-w-0">
      <div className="flex items-center gap-3 mb-1">
        <span className="text-blue-500 font-bold">[{name}]</span>
        <div className="h-px flex-1 bg-zinc-900" />
      </div>
      <div className="text-zinc-300 leading-relaxed whitespace-pre-wrap pl-4 border-l border-zinc-800">
        <AgentOutputBlock content={output} />
      </div>
    </div>
  )
})

const LiveAgentBlock = React.memo(function LiveAgentBlock({ id, liveLogLine }: { id: string; liveLogLine: string[] }) {
  return (
    <div className="flex flex-col gap-2 min-w-0">
      <div className="flex items-center gap-3 mb-1">
        <span className="text-blue-400 font-bold">[{id}]</span>
        <div className="h-px flex-1 bg-zinc-900" />
        <Loader2 className="w-3 h-3 text-blue-500 animate-spin flex-shrink-0" />
      </div>
      <div className="text-zinc-400 leading-relaxed whitespace-pre-wrap pl-4 border-l border-zinc-800 italic text-[12px]">
        {liveLogLine.join('')}
      </div>
    </div>
  )
})

// --- Static JSX hoisted at module level (rendering-hoist-jsx) ---

const EMPTY_STATE = (
  <div className="flex-col items-center justify-center py-40 opacity-20 text-center gap-4 flex">
    <TerminalIcon className="w-12 h-12" />
    <p className="text-sm font-mono tracking-tight">READY FOR INPUT...</p>
  </div>
)

// --- Internal type for live step grouping ---

type LiveStep = { type: 'sequential'; id: string } | { type: 'parallel'; ids: string[] }

// --- Props ---

interface LogViewerProps {
  logSteps: LogStep[]
  agentStatuses: Record<string, AgentState>
  selectedWorkflow: Workflow | null
  runId: string | null
  waitingAgentId: string | undefined
  waitingAgent: AgentState | null
  answer: string
  isAnswering: boolean
  onAnswerChange: (value: string) => void
  onSend: () => void
  status: RunStatus
  errorMessage: string | null
  onRetry: () => void
  bottomRef: React.RefObject<HTMLDivElement | null>
  scrollRef: React.RefObject<HTMLDivElement | null>
  autoScroll: boolean
  onAutoScrollChange: (value: boolean) => void
}

export function LogViewer({
  logSteps,
  agentStatuses,
  selectedWorkflow,
  runId,
  waitingAgentId,
  waitingAgent,
  answer,
  isAnswering,
  onAnswerChange,
  onSend,
  status,
  errorMessage,
  onRetry,
  bottomRef,
  scrollRef,
  autoScroll,
  onAutoScrollChange,
}: LogViewerProps) {
  const completedNames = useMemo(() => {
    const names = new Set<string>()
    for (const step of logSteps) {
      if (step.type === 'parallel') step.agents.forEach(a => names.add(a.name))
      else names.add(step.name)
    }
    return names
  }, [logSteps])

  const liveSteps = useMemo((): LiveStep[] => {
    const activeEntries = Object.entries(agentStatuses).filter(
      ([id, a]) => a.status === 'working' && a.liveLogLine.length > 0 && !completedNames.has(id)
    )
    if (activeEntries.length === 0) return []

    const activeIds = new Set(activeEntries.map(([id]) => id))
    const result: LiveStep[] = []
    const processedIds = new Set<string>()

    if (selectedWorkflow) {
      for (const step of selectedWorkflow.agents) {
        if (isParallelGroup(step)) {
          const groupIds = step.parallel.map(a => a.id).filter(id => activeIds.has(id))
          if (groupIds.length > 0) {
            result.push({ type: 'parallel', ids: groupIds })
            groupIds.forEach(id => processedIds.add(id))
          }
        } else {
          if (activeIds.has(step.id) && !processedIds.has(step.id)) {
            result.push({ type: 'sequential', id: step.id })
            processedIds.add(step.id)
          }
        }
      }
    }

    for (const [id] of activeEntries) {
      if (!processedIds.has(id)) result.push({ type: 'sequential', id })
    }

    return result
  }, [agentStatuses, completedNames, selectedWorkflow])

  return (
    <div className="flex-1 relative overflow-hidden group">
      <div 
        className="absolute inset-0 overflow-y-auto font-mono text-[13px] p-6 custom-scrollbar" 
        ref={scrollRef}
      >
        <div className="max-w-4xl mx-auto flex flex-col gap-8">

          {logSteps.length > 0 ? (
            logSteps.map((step, i) => {
              if (step.type === 'parallel') {
                return (
                  <div key={i} className="pl-3 border-l-2 border-violet-500/30 flex flex-col gap-3">
                    <div className="grid grid-cols-2 gap-6">
                      {step.agents.map((agent, j) => (
                        <CompletedAgentBlock key={j} {...agent} />
                      ))}
                    </div>
                  </div>
                )
              }
              return <CompletedAgentBlock key={i} name={step.name} output={step.output} />
            })
          ) : !runId ? EMPTY_STATE : null}

          {liveSteps.map((step, i) => {
            if (step.type === 'parallel') {
              return (
                <div key={i} className="pl-3 border-l-2 border-violet-500/30 flex flex-col gap-3">
                  <div className="grid grid-cols-2 gap-6">
                    {step.ids.map(id => (
                      <LiveAgentBlock key={id} id={id} liveLogLine={agentStatuses[id].liveLogLine} />
                    ))}
                  </div>
                </div>
              )
            }
            return (
              <LiveAgentBlock
                key={step.id}
                id={step.id}
                liveLogLine={agentStatuses[step.id].liveLogLine}
              />
            )
          })}

          {waitingAgent && waitingAgentId && (
            <QuestionInteraction
              agentId={waitingAgentId}
              question={waitingAgent.question}
              answer={answer}
              isAnswering={isAnswering}
              onAnswerChange={onAnswerChange}
              onSend={onSend}
            />
          )}

          {status === 'error' && (
            <ErrorBanner errorMessage={errorMessage} onRetry={onRetry} />
          )}

          <div ref={bottomRef} className="h-20" />
        </div>
      </div>

      {/* Auto-scroll toggle button */}
      <div className="absolute bottom-6 right-6 z-10">
        <button
          onClick={() => onAutoScrollChange(!autoScroll)}
          className={cn(
            "flex items-center gap-2 px-3 py-1.5 rounded-full border text-[11px] font-bold uppercase tracking-wider transition-all duration-200 shadow-lg",
            autoScroll 
              ? "bg-blue-500/10 border-blue-500/20 text-blue-400 hover:bg-blue-500/20 hover:border-blue-500/40" 
              : "bg-zinc-900 border-zinc-800 text-zinc-500 hover:text-zinc-400 hover:border-zinc-700"
          )}
          title={autoScroll ? "Pause auto-scroll" : "Resume auto-scroll"}
        >
          {autoScroll ? (
            <>
              <Pause className="w-3 h-3" />
              <span>Auto-scroll ON</span>
            </>
          ) : (
            <>
              <Play className="w-3 h-3" />
              <span>Auto-scroll OFF</span>
            </>
          )}
        </button>
      </div>
    </div>
  )
}
