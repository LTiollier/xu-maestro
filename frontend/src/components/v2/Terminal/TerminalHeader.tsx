'use client'

import { Terminal as TerminalIcon, CheckCircle2, AlertCircle, History, Loader2, MessageSquare } from 'lucide-react'
import { Button } from '@/components/ui/button'
import type { RunStatus } from '@/types/run.types'

interface TerminalHeaderProps {
  runId: string | null
  status: RunStatus
  waitingAgentId: string | undefined
  onHistoryOpen: () => void
}

export function TerminalHeader({ runId, status, waitingAgentId, onHistoryOpen }: TerminalHeaderProps) {
  return (
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
          onClick={onHistoryOpen}
          className="h-8 w-8 text-zinc-500 hover:text-white"
        >
          <History className="w-4 h-4" />
        </Button>
      </div>
    </div>
  )
}
