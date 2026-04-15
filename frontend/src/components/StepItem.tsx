'use client'

import { cn } from '@/lib/utils'

type StepStatus = 'pending' | 'working' | 'done' | 'error'

const icons: Record<StepStatus, string> = {
  pending: '○',
  working: '⚙',
  done:    '✓',
  error:   '✗',
}

const textColors: Record<StepStatus, string> = {
  pending: 'text-zinc-500',
  working: 'text-blue-400',
  done:    'text-emerald-400',
  error:   'text-red-400',
}

export function StepItem({ label, status }: { label: string; status: StepStatus }) {
  return (
    <div className={cn('flex items-center gap-2 text-xs py-0.5', textColors[status])}>
      <span className="shrink-0 w-3 text-center">{icons[status]}</span>
      <span className="truncate">{label}</span>
    </div>
  )
}
