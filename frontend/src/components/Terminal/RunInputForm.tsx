'use client'

import { Play, Square } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import type { RunStatus } from '@/types/run.types'
import type { Workflow } from '@/types/workflow.types'

interface RunInputFormProps {
  brief: string
  onBriefChange: (value: string) => void
  status: RunStatus
  selectedWorkflow: Workflow | null
  isSubmitting: boolean
  onLancer: () => void
  onAnnuler: () => void
}

export function RunInputForm({
  brief,
  onBriefChange,
  status,
  selectedWorkflow,
  isSubmitting,
  onLancer,
  onAnnuler,
}: RunInputFormProps) {
  return (
    <div className="h-24 border-t border-zinc-900 bg-[#09090b] p-4 flex items-center gap-4 shrink-0">
      <div className="flex-1 relative">
        <Textarea
          value={brief}
          onChange={(e) => onBriefChange(e.target.value)}
          placeholder={selectedWorkflow ? "Décris ta mission..." : "Sélectionne un workflow d'abord"}
          disabled={status === 'running' || !selectedWorkflow}
          className="w-full bg-black border-zinc-800 focus:border-blue-500/50 focus:ring-0 text-zinc-200 resize-none h-14 py-3 rounded-xl font-sans text-sm"
        />
      </div>

      <div className="flex flex-col gap-2">
        {status === 'running' ? (
          <Button
            onClick={onAnnuler}
            variant="destructive"
            className="h-14 px-6 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20"
          >
            <Square className="w-4 h-4 mr-2 fill-current" />
            Stop
          </Button>
        ) : (
          <Button
            onClick={onLancer}
            disabled={!selectedWorkflow || !brief.trim() || isSubmitting}
            className="h-14 px-8 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold shadow-[0_0_20px_rgba(37,99,235,0.2)]"
          >
            <Play className="w-4 h-4 mr-2 fill-current" />
            Lancer
          </Button>
        )}
      </div>
    </div>
  )
}
