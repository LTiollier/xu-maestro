'use client'

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import dynamic from 'next/dynamic'
import { useRunStore } from '@/stores/runStore'
import { useAgentStatusStore } from '@/stores/agentStatusStore'
import { useSSEListener } from '@/hooks/useSSEListener'
import { useWorkflowStore } from '@/stores/workflowStore'
import { isParallelGroup } from '@/types/workflow.types'
import { TerminalHeader } from './Terminal/TerminalHeader'
import { LogViewer } from './Terminal/LogViewer'
import type { LogAgent, LogStep } from './Terminal/LogViewer'
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

  const [autoScroll, setAutoScroll] = useState(true)

  const scrollRef = useRef<HTMLDivElement>(null)
  const bottomRef = useRef<HTMLDivElement>(null)

  const { logChunks } = useSSEListener(runId, retryKey)
  const logContent = useMemo(() => logChunks.join(''), [logChunks])

  const waitingAgentId = useMemo(
    () => Object.keys(agentStatuses).find(id => agentStatuses[id].status === 'waiting_for_input'),
    [agentStatuses],
  )
  const waitingAgent = waitingAgentId ? agentStatuses[waitingAgentId] : null

  // Update browser tab title based on run status (Step-based progression)
  useEffect(() => {
    const total = selectedWorkflow ? selectedWorkflow.agents.length : 0
    const activeAgents = Object.values(agentStatuses).filter(a => a.status !== 'idle')
    const currentStepIndex = activeAgents.length > 0
      ? Math.max(...activeAgents.map(a => a.step))
      : 0
    const progressCount = activeAgents.length > 0 ? currentStepIndex + 1 : 0

    let title = 'XuMaestro'
    if (status === 'idle') {
      title = '🟡 XuMaestro'
    } else if (status === 'running') {
      title = `🟢 ${progressCount}/${total} · XuMaestro`
    } else if (status === 'error') {
      title = '🔴 XuMaestro'
    } else if (status === 'completed') {
      title = '🔵 XuMaestro'
    }

    document.title = title
  }, [status, agentStatuses, selectedWorkflow])

  useEffect(() => {
    if (status === 'running' && autoScroll) {
      bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
    }
  }, [logChunks, status, waitingAgentId, autoScroll])

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

  const logSteps = useMemo((): LogStep[] => {
    if (!logContent) return []
    const parts = logContent.split('---\n## Agent:')
    const flatAgents: LogAgent[] = parts.slice(1).map(p => {
      const [nameLine, ...rest] = p.split('\n')
      return { name: nameLine.trim(), output: rest.join('\n').trim() }
    })

    if (!selectedWorkflow) {
      return flatAgents.map(a => ({ type: 'sequential', ...a }))
    }

    const agentMap = new Map(flatAgents.map(a => [a.name, a]))
    const result: LogStep[] = []

    for (const step of selectedWorkflow.agents) {
      if (isParallelGroup(step)) {
        const groupAgents = step.parallel
          .map(a => agentMap.get(a.id))
          .filter((a): a is LogAgent => a !== undefined)
        if (groupAgents.length > 0) {
          result.push({ type: 'parallel', agents: groupAgents })
        }
      } else {
        const la = agentMap.get(step.id)
        if (la) result.push({ type: 'sequential', ...la })
      }
    }

    // Fallback: agents not found in workflow definition
    const processedNames = new Set(
      result.flatMap(s => s.type === 'parallel' ? s.agents.map(a => a.name) : [s.name])
    )
    for (const agent of flatAgents) {
      if (!processedNames.has(agent.name)) result.push({ type: 'sequential', ...agent })
    }

    return result
  }, [logContent, selectedWorkflow])

  return (
    <div className="flex-1 flex flex-col bg-black min-w-0 overflow-hidden">
      <TerminalHeader
        runId={runId}
        status={status}
        waitingAgentId={waitingAgentId}
        onHistoryOpen={() => setIsHistoryOpen(true)}
      />
      <LogViewer
        logSteps={logSteps}
        agentStatuses={agentStatuses}
        selectedWorkflow={selectedWorkflow}
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
        autoScroll={autoScroll}
        onAutoScrollChange={setAutoScroll}
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
