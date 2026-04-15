'use client'

import { Button } from '@/components/ui/button'

interface BubbleBoxProps {
  variant: 'info' | 'error' | 'success'
  message: string
  onRetry?: () => void
}

const styles: Record<BubbleBoxProps['variant'], string> = {
  info:    'bg-blue-500/10 border-blue-500/30 text-blue-200 backdrop-blur-md',
  error:   'bg-red-500/10 border-red-500/30 text-red-200 backdrop-blur-md',
  success: 'bg-emerald-500/10 border-emerald-500/30 text-emerald-200 backdrop-blur-md',
}

export function BubbleBox({ variant, message, onRetry }: BubbleBoxProps) {
  return (
    <div className={`rounded-xl border px-4 py-3 text-[11px] mt-1 shadow-inner ${styles[variant]}`}>
      <p className="font-medium leading-relaxed">{message}</p>
      {variant === 'error' && (
        <Button
          variant="ghost"
          size="sm"
          className="mt-2.5 h-7 px-3 text-[10px] uppercase tracking-wider font-bold text-red-200 hover:text-white hover:bg-red-500/20 rounded-full border border-red-500/20"
          onClick={onRetry}
          disabled={!onRetry}
          aria-label="Relancer cette étape"
        >
          Relancer cette étape
        </Button>
      )}
    </div>
  )
}
