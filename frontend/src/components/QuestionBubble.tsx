'use client'

import { useState } from 'react'
import { MessageCircleQuestion, Send, CheckCircle2, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useRunStore } from '@/stores/runStore'

interface QuestionBubbleProps {
  agentId: string
  question: string
}

/** Splits a question string into renderable segments.
 *  Numbered items like "1. foo" or "1) foo" become list entries.
 *  Remaining text is returned as paragraphs. */
function parseQuestion(raw: string): { type: 'paragraph' | 'list'; items: string[] }[] {
  const lines = raw.split('\n').map((l) => l.trim()).filter(Boolean)
  const segments: { type: 'paragraph' | 'list'; items: string[] }[] = []
  let listBuffer: string[] = []

  const flush = () => {
    if (listBuffer.length) {
      segments.push({ type: 'list', items: [...listBuffer] })
      listBuffer = []
    }
  }

  for (const line of lines) {
    const isListItem = /^\d+[.)]\s+/.test(line) || /^[-•]\s+/.test(line)
    if (isListItem) {
      listBuffer.push(line.replace(/^\d+[.)]\s+/, '').replace(/^[-•]\s+/, ''))
    } else {
      flush()
      segments.push({ type: 'paragraph', items: [line] })
    }
  }
  flush()
  return segments
}

export function QuestionBubble({ agentId, question }: QuestionBubbleProps) {
  const runId = useRunStore((s) => s.runId)
  const [answer, setAnswer] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async () => {
    if (!runId || !answer.trim() || isSubmitting || submitted) return
    setIsSubmitting(true)
    setError(null)
    try {
      const res = await fetch(`/api/runs/${runId}/answer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ agentId, answer: answer.trim() }),
      })
      if (res.ok) {
        setSubmitted(true)
      } else {
        const body = await res.json().catch(() => ({}))
        setError((body as { message?: string }).message ?? "Impossible d'envoyer la réponse")
      }
    } catch {
      setError('Erreur réseau — impossible de joindre le serveur')
    } finally {
      setIsSubmitting(false)
    }
  }

  const segments = parseQuestion(question)

  return (
    <div className="rounded-xl border border-violet-500/40 bg-violet-500/10 mt-1 shadow-inner backdrop-blur-md overflow-hidden">
      {/* Header */}
      <div className="flex items-center gap-2 px-3 py-2 border-b border-violet-500/20 bg-violet-500/10">
        <MessageCircleQuestion className="w-3.5 h-3.5 text-violet-400 shrink-0" />
        <span className="text-[10px] font-bold uppercase tracking-widest text-violet-400">
          Question
        </span>
        <span className="ml-auto text-[10px] text-violet-500/70 font-medium truncate max-w-[120px]">
          {agentId}
        </span>
      </div>

      {/* Question body */}
      <div className="relative">
        <div className="px-3 pt-2.5 pb-2 space-y-1.5 max-h-48 overflow-y-auto scrollbar-thin scrollbar-thumb-violet-500/30 scrollbar-track-transparent" onWheel={e => e.stopPropagation()}>
          {segments.map((seg, i) =>
            seg.type === 'list' ? (
              <ol key={i} className="space-y-1 pl-1">
                {seg.items.map((item, j) => (
                  <li key={j} className="flex gap-2 text-[11px] text-violet-100 leading-relaxed">
                    <span className="shrink-0 w-4 text-violet-400/70 font-semibold text-right">
                      {j + 1}.
                    </span>
                    <span>{item}</span>
                  </li>
                ))}
              </ol>
            ) : (
              <p key={i} className="text-[11px] text-violet-100 leading-relaxed">
                {seg.items[0]}
              </p>
            )
          )}
        </div>
        {/* Fade gradient to hint at scrollable content */}
        <div className="pointer-events-none absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-zinc-900/95 to-transparent" />
      </div>

      {/* Answer area */}
      <div className="px-3 pb-3 pt-1">
        {submitted ? (
          <div className="flex items-start gap-2 rounded-lg bg-violet-500/10 border border-violet-500/20 px-3 py-2">
            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400 mt-0.5 shrink-0" />
            <p className="text-[11px] text-zinc-300 leading-relaxed">{answer}</p>
          </div>
        ) : (
          <>
            <div className="relative">
              <textarea
                className="w-full rounded-lg border border-violet-500/25 bg-zinc-900/70 px-3 py-2 pr-10 text-xs text-zinc-100 placeholder:text-zinc-500 resize-none focus:outline-none focus:ring-1 focus:ring-violet-500/60 transition-shadow"
                rows={2}
                placeholder="Répondez ici… (Entrée pour envoyer)"
                value={answer}
                onChange={(e) => setAnswer(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault()
                    handleSubmit()
                  }
                }}
                disabled={isSubmitting}
              />
              <Button
                variant="ghost"
                size="icon"
                className="absolute right-1.5 bottom-1.5 h-6 w-6 text-violet-400 hover:text-white hover:bg-violet-500/30 rounded-md disabled:opacity-30"
                onClick={handleSubmit}
                disabled={!answer.trim() || isSubmitting}
                aria-label="Envoyer la réponse"
              >
                {isSubmitting ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Send className="w-3.5 h-3.5" />
                )}
              </Button>
            </div>
            {error && (
              <p className="text-[10px] text-red-400 mt-1.5">{error}</p>
            )}
          </>
        )}
      </div>
    </div>
  )
}
