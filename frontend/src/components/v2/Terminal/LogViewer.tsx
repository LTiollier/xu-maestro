'use client'

import React from 'react'
import dynamic from 'next/dynamic'
import { Terminal as TerminalIcon, Loader2 } from 'lucide-react'
import type { AgentState, RunStatus } from '@/types/run.types'
import { ErrorBanner } from './ErrorBanner'

const QuestionInteraction = dynamic(
  () => import('@/components/v2/QuestionInteraction'),
  { ssr: false },
)

const AgentOutputBlock = React.memo(function AgentOutputBlock({ content }: { content: string }) {
  if (content.trim().startsWith('{')) {
    try {
      const parsed = JSON.parse(content)
      if (parsed.output) {
        return <AgentOutputBlock content={parsed.output} />
      }
    } catch {
      // Fallback to raw text if parsing fails
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

interface LogAgent {
  name: string
  output: string
}

interface LogViewerProps {
  logAgents: LogAgent[]
  agentStatuses: Record<string, AgentState>
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
  bottomRef: React.RefObject<HTMLDivElement>
  scrollRef: React.RefObject<HTMLDivElement>
}

export function LogViewer({
  logAgents,
  agentStatuses,
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
}: LogViewerProps) {
  return (
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
                <AgentOutputBlock content={agent.output} />
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
          if (agent.status !== 'working' || agent.liveLogLine.length === 0) return null
          if (logAgents.some(la => la.name === id)) return null

          return (
            <div key={id} className="flex flex-col gap-2">
              <div className="flex items-center gap-3 mb-1">
                <span className="text-blue-400 font-bold">[{id}]</span>
                <div className="h-px flex-1 bg-zinc-900" />
                <Loader2 className="w-3 h-3 text-blue-500 animate-spin" />
              </div>
              <div className="text-zinc-400 leading-relaxed whitespace-pre-wrap pl-4 border-l border-zinc-800 italic text-[12px]">
                {agent.liveLogLine.join('')}
              </div>
            </div>
          )
        })}

        {/* Interaction Area: Question from Agent */}
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

        {/* Global Error Banner and Retry */}
        {status === 'error' && (
          <ErrorBanner errorMessage={errorMessage} onRetry={onRetry} />
        )}

        <div ref={bottomRef} className="h-20" />
      </div>
    </div>
  )
}
