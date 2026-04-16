'use client'

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import dynamic from 'next/dynamic'
import { useRunStore } from '@/stores/runStore'
import { useAgentStatusStore } from '@/stores/agentStatusStore'
import { useSSEListener } from '@/hooks/useSSEListener'
import { useWorkflowStore } from '@/stores/workflowStore'
import { TerminalHeader } from './Terminal/TerminalHeader'
import { LogViewer } from './Terminal/LogViewer'
import { RunInputForm } from './Terminal/RunInputForm'

const RunHistory = dynamic(
  () => import('@/components/RunHistory').then(m => ({ default: m.RunHistory })),
  { ssr: false },
)

export function Terminal() {
  const { runId, status, retryKey, setRunId, resetRun, setRetrying, errorMessage } = useRunStore()
  const { selectedWorkflow } = useWorkflowStore()
  const agentStatuses = useAgentStatusStore((s) => s.agents)

  const [brief, setBrief] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isHistoryOpen, setIsHistoryOpen] = useState(false)
  const [answer, setAnswer] = useState('')
  const [isAnswering, setIsAnswering] = useState(false)

  const scrollRef = useRef<HTMLDivElement>(null)
  const bottomRef = useRef<HTMLDivElement>(null)

  const { logChunks } = useSSEListener(runId, retryKey)
  const logContent = useMemo(() => logChunks.join(''), [logChunks])

  const waitingAgentId = useMemo(
    () => Object.keys(agentStatuses).find(id => agentStatuses[id].status === 'waiting_for_input'),
    [agentStatuses],
  )
  const waitingAgent = waitingAgentId ? agentStatuses[waitingAgentId] : null

  // Update browser tab title based on run status
  useEffect(() => {
    const total = selectedWorkflow?.agents.length ?? 0
    const workingCount = Object.values(agentStatuses).filter(a => a.status === 'working').length
    const hasQuestion = Object.values(agentStatuses).some(a => a.status === 'waiting_for_input')

    let title = 'xu-workflow'
    if (status === 'running' && hasQuestion) {
      title = '🟡 xu-workflow'
    } else if (status === 'running') {
      title = `🟢 ${workingCount}/${total} · xu-workflow`
    } else if (status === 'error') {
      title = '🔴 xu-workflow'
    } else if (status === 'completed') {
      title = '🔵 xu-workflow'
    }

    document.title = title
  }, [status, agentStatuses, selectedWorkflow])

  useEffect(() => {
    if (status === 'running') {
      bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
    }
  }, [logChunks, status, waitingAgentId])

  const handleLancer = useCallback(async () => {
    if (!selectedWorkflow || !brief.trim() || isSubmitting) return
    setIsSubmitting(true)
    useAgentStatusStore.getState().resetAgents()

    try {
      const res = await fetch('/api/runs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workflowFile: selectedWorkflow.file, brief }),
      })
      const body = await res.json()
      if (body.runId) setRunId(body.runId)
    } catch (e) {
      console.error(e)
    } finally {
      setIsSubmitting(false)
    }
  }, [selectedWorkflow, brief, isSubmitting, setRunId])

  const handleAnnuler = useCallback(async () => {
    if (!runId) return
    try {
      const res = await fetch(`/api/runs/${runId}`, { method: 'DELETE' })
      if (res.ok) {
        resetRun()
        useAgentStatusStore.getState().resetAgents()
      }
    } catch (e) {
      console.error(e)
    }
  }, [runId, resetRun])

  const handleRetry = useCallback(async () => {
    if (!runId) return
    try {
      const res = await fetch(`/api/runs/${runId}/retry-step`, { method: 'POST' })
      if (res.ok) {
        setRetrying()
      }
    } catch (e) {
      console.error(e)
    }
  }, [runId, setRetrying])

  const handleSendAnswer = useCallback(async () => {
    if (!runId || !waitingAgentId || !answer.trim() || isAnswering) return
    setIsAnswering(true)
    try {
      const res = await fetch(`/api/runs/${runId}/answer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ agentId: waitingAgentId, answer: answer.trim() }),
      })
      if (res.ok) {
        setAnswer('')
      }
    } catch (e) {
      console.error(e)
    } finally {
      setIsAnswering(false)
    }
  }, [runId, waitingAgentId, answer, isAnswering])

  const logAgents = useMemo(() => {
    if (!logContent) return []
    const parts = logContent.split('---\n## Agent:')
    return parts.slice(1).map(p => {
      const [nameLine, ...rest] = p.split('\n')
      return { name: nameLine.trim(), output: rest.join('\n').trim() }
    })
  }, [logContent])

  return (
    <div className="flex-1 flex flex-col bg-black min-w-0 overflow-hidden">
      <TerminalHeader
        runId={runId}
        status={status}
        waitingAgentId={waitingAgentId}
        onHistoryOpen={() => setIsHistoryOpen(true)}
      />
      <LogViewer
        logAgents={logAgents}
        agentStatuses={agentStatuses}
        runId={runId}
        waitingAgentId={waitingAgentId}
        waitingAgent={waitingAgent}
        answer={answer}
        isAnswering={isAnswering}
        onAnswerChange={setAnswer}
        onSend={handleSendAnswer}
        status={status}
        errorMessage={errorMessage}
        onRetry={handleRetry}
        bottomRef={bottomRef}
        scrollRef={scrollRef}
      />
      <RunInputForm
        brief={brief}
        onBriefChange={setBrief}
        status={status}
        selectedWorkflow={selectedWorkflow}
        isSubmitting={isSubmitting}
        onLancer={handleLancer}
        onAnnuler={handleAnnuler}
      />
      <RunHistory open={isHistoryOpen} onOpenChange={setIsHistoryOpen} />
    </div>
  )
}
