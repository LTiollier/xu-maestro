'use client'

import { useEffect } from 'react'
import { Loader2 } from 'lucide-react'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import { Badge } from '@/components/ui/badge'
import { useRunHistory } from '@/hooks/useRunHistory'
import { useRunStore } from '@/stores/runStore'
import { useWorkflowStore } from '@/stores/workflowStore'
import { useAgentStatusStore } from '@/stores/agentStatusStore'
import type { RunHistoryItem } from '@/types/run.types'

interface RunHistoryProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

const statusBadgeClass: Record<RunHistoryItem['status'], string> = {
  completed: 'bg-emerald-500 text-white',
  error:     'bg-red-500 text-white',
  cancelled: 'bg-zinc-500 text-white',
}

const statusLabel: Record<RunHistoryItem['status'], string> = {
  completed: 'Terminé',
  error:     'Erreur',
  cancelled: 'Annulé',
}

function formatDuration(duration: number | null): string {
  if (duration == null) return '—'
  const totalSeconds = Math.round(duration / 1000)
  if (totalSeconds < 60) return `${totalSeconds}s`
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return `${minutes}min${seconds.toString().padStart(2, '0')}`
}

function formatDate(createdAt: string): string {
  try {
    return new Date(createdAt).toLocaleString('fr-FR')
  } catch {
    return createdAt
  }
}

export function RunHistory({ open, onOpenChange }: RunHistoryProps) {
  const { runs, isLoading, error, reload } = useRunHistory()
  const loadHistoryRun = useRunStore((s) => s.loadHistoryRun)
  const { workflows, setSelectedWorkflow } = useWorkflowStore()
  const loadHistoryAgents = useAgentStatusStore((s) => s.loadHistoryAgents)

  useEffect(() => {
    if (open) {
      reload()
    }
  }, [open, reload])

  const handleSelectRun = (item: RunHistoryItem) => {
    const workflow = workflows.find(w => w.file === item.workflowFile)
    if (workflow) setSelectedWorkflow(workflow)
    loadHistoryAgents(item.completedAgents, item.currentAgent, item.status)
    loadHistoryRun(item.runId, item.status, item.duration, item.runFolder)
    onOpenChange(false)
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="bg-zinc-900 border-zinc-700 text-zinc-100 w-96 sm:max-w-none flex flex-col">
        <SheetHeader>
          <SheetTitle className="text-zinc-100">Historique des runs</SheetTitle>
          <SheetDescription className="text-zinc-400">
            Cliquez sur un run pour le charger dans l&apos;interface
          </SheetDescription>
        </SheetHeader>

        <div className="flex-1 overflow-y-auto px-4 pb-4">
          {isLoading && (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-5 w-5 animate-spin text-zinc-400" />
            </div>
          )}

          {error && (
            <p className="text-red-400 text-sm py-4">{error}</p>
          )}

          {!isLoading && !error && runs.length === 0 && (
            <p className="text-zinc-400 text-sm py-8 text-center">
              Aucun run pour l&apos;instant
            </p>
          )}

          {!isLoading && runs.length > 0 && (
            <ul className="flex flex-col gap-2 pt-2">
              {runs.map((item) => (
                <li
                  key={item.runId}
                  onClick={() => handleSelectRun(item)}
                  className="flex flex-col gap-1.5 rounded-md bg-zinc-800 border border-zinc-700 px-3 py-2.5 cursor-pointer hover:bg-zinc-700/80 hover:border-zinc-600 transition-colors"
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-zinc-100 text-sm font-medium truncate">
                      {item.workflowFile}
                    </span>
                    <Badge className={statusBadgeClass[item.status]}>
                      {statusLabel[item.status]}
                    </Badge>
                  </div>

                  <div className="flex items-center justify-between text-xs text-zinc-400">
                    <span>{formatDate(item.createdAt)}</span>
                    <span>
                      {item.agentCount} agent{item.agentCount !== 1 ? 's' : ''} · {formatDuration(item.duration)}
                    </span>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </SheetContent>
    </Sheet>
  )
}
