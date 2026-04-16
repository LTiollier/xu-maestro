'use client'

import { AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'

interface ErrorBannerProps {
  errorMessage: string | null
  onRetry: () => void
}

export function ErrorBanner({ errorMessage, onRetry }: ErrorBannerProps) {
  return (
    <div className="p-6 rounded-2xl bg-red-500/5 border border-red-500/20 flex flex-col gap-4">
      <div className="flex items-center gap-3">
        <AlertCircle className="w-5 h-5 text-red-500" />
        <span className="text-sm font-bold text-red-200">Execution Error</span>
      </div>
      <p className="text-sm text-red-400/80 leading-relaxed italic">
        {errorMessage || "Une erreur inconnue s'est produite lors de l'exécution."}
      </p>
      <div className="flex justify-end">
        <Button
          onClick={onRetry}
          className="bg-red-500 hover:bg-red-400 text-black font-bold h-10 px-6 rounded-lg"
        >
          [RETRY STEP]
        </Button>
      </div>
    </div>
  )
}
