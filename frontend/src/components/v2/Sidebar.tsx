'use client'

import React from 'react'
import { useWorkflowStore } from '@/stores/workflowStore'
import { useAgentStatusStore } from '@/stores/agentStatusStore'
import { AgentSidebarItem } from './AgentSidebarItem'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue
} from '@/components/ui/select'
import { Layers, Users, RefreshCw, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useWorkflows } from '@/hooks/useWorkflows'
import type { AgentStatus } from '@/types/run.types'

const AgentSidebarItemConnected = React.memo(function AgentSidebarItemConnected({
  agentId,
  engine,
}: {
  agentId: string
  engine: string
}) {
  const status = useAgentStatusStore((s): AgentStatus => s.agents[agentId]?.status ?? 'idle')
  return <AgentSidebarItem id={agentId} engine={engine} status={status} />
})

export function Sidebar() {
  const { workflows, selectedWorkflow, setSelectedWorkflow, isLoading } = useWorkflowStore()
  const { reload } = useWorkflows()

  const handleSelect = (file: string | null) => {
    if (!file) {
      setSelectedWorkflow(null)
      return
    }
    const workflow = workflows.find((w) => w.file === file) ?? null
    setSelectedWorkflow(workflow)
  }

  return (
    <aside className="w-80 border-r border-zinc-800 bg-[#09090b] flex flex-col h-full shrink-0">
      {/* Sidebar Header with Workflow Selector */}
      <div className="p-4 border-b border-zinc-800 flex flex-col gap-4">
        <div className="flex items-center justify-between px-1">
          <div className="flex items-center gap-2">
            <Layers className="w-4 h-4 text-zinc-400" />
            <span className="text-xs font-bold text-zinc-500 uppercase tracking-widest font-mono">
              Pipeline / Team
            </span>
          </div>
          <Button
            variant="ghost"
            size="icon"
            onClick={reload}
            disabled={isLoading}
            className="h-6 w-6 text-zinc-600 hover:text-zinc-400"
          >
            {isLoading ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
          </Button>
        </div>

        <Select 
          value={selectedWorkflow?.file ?? ''} 
          onValueChange={handleSelect}
        >
          <SelectTrigger className="w-full bg-zinc-900 border-zinc-800 text-zinc-200 h-10 rounded-xl focus:ring-0">
            <SelectValue placeholder="Sélectionner un workflow" />
          </SelectTrigger>
          <SelectContent className="bg-zinc-900 border-zinc-800 text-zinc-200">
            {workflows.map((w) => (
              <SelectItem key={w.file} value={w.file}>
                {w.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/* Agents List */}
      <div className="flex-1 overflow-y-auto p-4 flex flex-col gap-2">
        {selectedWorkflow ? (
          <>
            <div className="flex items-center gap-2 px-1 mb-2">
              <Users className="w-3 h-3 text-zinc-600" />
              <span className="text-[10px] font-bold text-zinc-600 uppercase tracking-wider">
                Agents ({selectedWorkflow.agents.length})
              </span>
            </div>
            {selectedWorkflow.agents.map((agent) => (
              <AgentSidebarItemConnected key={agent.id} agentId={agent.id} engine={agent.engine} />
            ))}
          </>
        ) : (
          <div className="flex flex-col items-center justify-center h-full py-10 opacity-30 text-center gap-4">
            <Layers className="w-10 h-10 text-zinc-500" />
            <p className="text-xs font-medium text-zinc-500 max-w-[200px]">
              Veuillez sélectionner un workflow pour voir les agents.
            </p>
          </div>
        )}
      </div>

      {/* Footer Meta (Optionnel) */}
      <div className="p-4 border-t border-zinc-800 bg-black/20">
        <div className="flex items-center justify-between text-[10px] text-zinc-600 font-bold uppercase tracking-widest font-mono">
          <span>v2.0 Beta</span>
          <span className="text-emerald-500/50">Online</span>
        </div>
      </div>
    </aside>
  )
}
