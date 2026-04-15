'use client'

import React from 'react'
import { motion } from 'framer-motion'
import { Check, Loader2, AlertCircle, Circle, MessageSquare, FastForward } from 'lucide-react'
import { cn } from '@/lib/utils'

interface AgentSidebarItemProps {
  id: string
  engine: string
  status: 'idle' | 'working' | 'done' | 'error' | 'skipped' | 'waiting_for_input'
  isCurrent?: boolean
}

export function AgentSidebarItem({ id, engine, status, isCurrent }: AgentSidebarItemProps) {
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
      <div className="flex flex-col min-w-0">
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

      {/* Active Indicator Glow */}
      {(isWorking || isWaiting) && (
        <div className={cn(
          "absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 rounded-full blur-[2px]",
          isWorking ? "bg-blue-500" : "bg-amber-500"
        )} />
      )}
    </motion.div>
  )
}
