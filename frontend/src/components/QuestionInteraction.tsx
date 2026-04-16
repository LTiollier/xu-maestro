'use client'

import { Loader2, MessageSquare, Send } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'

interface QuestionInteractionProps {
  agentId: string
  question: string
  answer: string
  isAnswering: boolean
  onAnswerChange: (value: string) => void
  onSend: () => void
}

export default function QuestionInteraction({
  agentId,
  question,
  answer,
  isAnswering,
  onAnswerChange,
  onSend,
}: QuestionInteractionProps) {
  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center gap-3 mb-1">
        <span className="text-amber-500 font-bold">[{agentId}]</span>
        <div className="h-px flex-1 bg-zinc-900" />
        <MessageSquare className="w-3 h-3 text-amber-500 animate-pulse" />
      </div>
      <div className="pl-4 border-l border-amber-500/30 flex flex-col gap-4">
        <div className="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20">
          <span className="text-[10px] font-bold text-amber-400 uppercase tracking-widest font-mono block mb-2">
            Agent Question
          </span>
          <div className="text-sm text-amber-100 leading-relaxed whitespace-pre-wrap">
            {question}
          </div>
        </div>

        <div className="relative group">
          <Textarea
            value={answer}
            onChange={(e) => onAnswerChange(e.target.value)}
            placeholder="Tape ta réponse ici..."
            className="w-full bg-zinc-900/50 border-zinc-800 focus:border-amber-500/50 focus:ring-0 text-zinc-200 resize-none h-24 p-4 rounded-xl font-sans text-sm"
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault()
                onSend()
              }
            }}
          />
          <Button
            size="icon"
            onClick={onSend}
            disabled={!answer.trim() || isAnswering}
            className="absolute bottom-3 right-3 h-10 w-10 rounded-lg bg-amber-600 hover:bg-amber-500 text-white shadow-lg shadow-amber-900/20"
          >
            {isAnswering ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
          </Button>
        </div>
      </div>
    </div>
  )
}
