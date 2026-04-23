'use client'

import React from 'react'
import { motion } from 'framer-motion'
import { Check, Loader2, AlertCircle, Circle, MessageSquare, FastForward, Info } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Popover, PopoverContent, PopoverTrigger } from './ui/popover'
import type { Agent } from '@/types/workflow.types'

interface AgentSidebarItemProps {
  agent: Agent
  status: 'idle' | 'working' | 'done' | 'error' | 'skipped' | 'waiting_for_input'
}

export const AgentSidebarItem = React.memo(function AgentSidebarItem({ agent, status }: AgentSidebarItemProps) {
  const { id, engine, timeout, mandatory, skippable, interactive, system_prompt, steps, max_retries, loop } = agent
  const isWorking = status === 'working'
  const isDone = status === 'done'
  const isError = status === 'error'
  const isIdle = status === 'idle'
  const isWaiting = status === 'waiting_for_input'
  const isSkipped = status === 'skipped'

  return (
    <motion.div
      initial={{ opacity: 0.5 }}
      animate={{ opacity: isIdle ? 0.5 : 1 }}
      className={cn(
        "group relative flex items-center gap-3 p-3 rounded-xl transition-all duration-300",
        "border border-transparent",
        isWorking && "bg-blue-500/5 border-blue-500/30 ring-1 ring-blue-500/20 shadow-[0_0_15px_rgba(59,130,246,0.1)]",
        isDone && "bg-emerald-500/5 border-emerald-500/20",
        isWaiting && "bg-amber-500/5 border-amber-500/30 ring-1 ring-amber-500/20 shadow-[0_0_15px_rgba(245,158,11,0.1)]",
        isError && "bg-red-500/5 border-red-500/30 animate-pulse",
        isSkipped && "bg-zinc-500/5 border-zinc-500/10 opacity-40",
        !isWorking && !isDone && !isError && !isWaiting && !isSkipped && "hover:bg-white/5"
      )}
    >
      {/* Status Icon */}
      <div className="flex-shrink-0">
        {isWorking && <Loader2 className="w-4 h-4 text-blue-500 animate-spin" />}
        {isDone && <Check className="w-4 h-4 text-emerald-500" />}
        {isWaiting && <MessageSquare className="w-4 h-4 text-amber-500 animate-pulse" />}
        {isError && <AlertCircle className="w-4 h-4 text-red-500" />}
        {isSkipped && <FastForward className="w-4 h-4 text-zinc-500" />}
        {isIdle && <Circle className="w-4 h-4 text-zinc-600" />}
      </div>

      {/* Info */}
      <div className="flex flex-col min-w-0 flex-1">
        <span className={cn(
          "text-sm font-medium transition-colors",
          isWorking ? "text-blue-100" : 
          isDone ? "text-emerald-100/70" : 
          isWaiting ? "text-amber-100" :
          "text-zinc-400"
        )}>
          {id}
        </span>
        <span className="text-[10px] uppercase tracking-wider text-zinc-600 font-bold font-mono">
          {engine}
        </span>
      </div>

      {/* Detail Popover */}
      <Popover>
        <PopoverTrigger
          className="p-1.5 rounded-lg text-zinc-600 hover:text-zinc-400 hover:bg-white/5 transition-colors opacity-0 group-hover:opacity-100"
          onClick={(e) => e.stopPropagation()}
        >
          <Info className="w-4 h-4" />
        </PopoverTrigger>
        <PopoverContent side="right" className="w-80">
          <div className="space-y-4">
            <div>
              <h4 className="text-xs font-bold text-zinc-500 uppercase tracking-widest font-mono mb-2">
                Agent Config: <span className="text-zinc-200">{id}</span>
              </h4>
              <div className="grid grid-cols-2 gap-2">
                <div className="bg-white/5 p-2 rounded-lg border border-white/5">
                  <span className="block text-[10px] text-zinc-500 uppercase font-bold">Engine</span>
                  <span className="text-xs text-zinc-300 font-mono">{engine}</span>
                </div>
                <div className="bg-white/5 p-2 rounded-lg border border-white/5">
                  <span className="block text-[10px] text-zinc-500 uppercase font-bold">Timeout</span>
                  <span className="text-xs text-zinc-300 font-mono">{timeout}s</span>
                </div>
              </div>
            </div>

            <div className="flex flex-wrap gap-2">
              {mandatory && (
                <div className="px-2 py-1 bg-amber-500/10 border border-amber-500/20 rounded-full">
                  <span className="text-[10px] text-amber-500 font-bold uppercase">Mandatory {max_retries ? `(${max_retries} retries)` : ''}</span>
                </div>
              )}
              {skippable && (
                <div className="px-2 py-1 bg-blue-500/10 border border-blue-500/20 rounded-full">
                  <span className="text-[10px] text-blue-500 font-bold uppercase">Skippable</span>
                </div>
              )}
              {interactive && (
                <div className="px-2 py-1 bg-emerald-500/10 border border-emerald-500/20 rounded-full">
                  <span className="text-[10px] text-emerald-500 font-bold uppercase">Interactive</span>
                </div>
              )}
              {loop && (
                <div className="px-2 py-1 bg-purple-500/10 border border-purple-500/20 rounded-full">
                  <span className="text-[10px] text-purple-500 font-bold uppercase">Loop: {loop.over} as {loop.as}</span>
                </div>
              )}
            </div>

            {system_prompt && (
              <div>
                <span className="block text-[10px] text-zinc-500 uppercase font-bold mb-1">System Prompt</span>
                <div className="bg-black/40 p-3 rounded-lg border border-white/5">
                  <p className="text-xs text-zinc-400 leading-relaxed italic line-clamp-6 hover:line-clamp-none transition-all cursor-default">
                    "{system_prompt}"
                  </p>
                </div>
              </div>
            )}

            <div>
              <span className="block text-[10px] text-zinc-500 uppercase font-bold mb-1">Steps</span>
              <ul className="space-y-1">
                {steps.map((step, i) => (
                  <li key={i} className="text-xs text-zinc-400 flex gap-2">
                    <span className="text-zinc-600">•</span>
                    {step}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </PopoverContent>
      </Popover>

      {/* Active Indicator Glow */}
      {(isWorking || isWaiting) && (
        <div className={cn(
          "absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-full blur-[2px]",
          isWorking ? "bg-blue-500" : "bg-amber-500"
        )} />
      )}
    </motion.div>
  )
})
