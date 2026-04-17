'use client'

import React, { useRef, useState } from 'react'
import { useWorkflowStore } from '@/stores/workflowStore'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import type { Workflow } from '@/types/workflow.types'

interface WorkflowWizardProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

type Step = 1 | 2

interface GenerateResponse {
  yaml: string
  parsed: Record<string, unknown>
  error?: string
  raw_yaml?: string
}

function resetFormState() {
  return {
    step: 1 as Step,
    brief: '',
    yamlContent: '',
    filename: '',
    generateError: '',
    storeError: '',
    isGenerating: false,
    isStoring: false,
    showOverwriteConfirm: false,
  }
}

export function WorkflowWizard({ open, onOpenChange }: WorkflowWizardProps) {
  const { addWorkflow } = useWorkflowStore()
  const abortRef = useRef<AbortController | null>(null)

  const [step, setStep] = useState<Step>(1)
  const [brief, setBrief] = useState('')
  const [yamlContent, setYamlContent] = useState('')
  const [filename, setFilename] = useState('')
  const [generateError, setGenerateError] = useState('')
  const [storeError, setStoreError] = useState('')
  const [isGenerating, setIsGenerating] = useState(false)
  const [isStoring, setIsStoring] = useState(false)
  const [showOverwriteConfirm, setShowOverwriteConfirm] = useState(false)

  const resetState = () => {
    const s = resetFormState()
    setStep(s.step)
    setBrief(s.brief)
    setYamlContent(s.yamlContent)
    setFilename(s.filename)
    setGenerateError(s.generateError)
    setStoreError(s.storeError)
    setIsGenerating(s.isGenerating)
    setIsStoring(s.isStoring)
    setShowOverwriteConfirm(s.showOverwriteConfirm)
  }

  const handleOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
      abortRef.current?.abort()
      resetState()
    }
    onOpenChange(isOpen)
  }

  const handleGenerate = async () => {
    abortRef.current?.abort()
    abortRef.current = new AbortController()

    setIsGenerating(true)
    setGenerateError('')

    try {
      const res = await fetch('/api/workflows/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ brief }),
        signal: abortRef.current.signal,
      })

      const data: GenerateResponse = await res.json()

      if (res.ok) {
        setYamlContent(data.yaml)
        setGenerateError('')
        setStep(2)
      } else {
        // On 422: advance to step 2 with raw_yaml in textarea + show error
        if (data.raw_yaml) {
          setYamlContent(data.raw_yaml)
        }
        setGenerateError(data.error ?? 'Erreur lors de la génération')
        setStep(2)
      }
    } catch (err) {
      if (err instanceof Error && err.name === 'AbortError') {
        return
      }
      setGenerateError('Erreur réseau lors de la génération')
    } finally {
      setIsGenerating(false)
    }
  }

  const handleStore = async (force = false) => {
    abortRef.current?.abort()
    abortRef.current = new AbortController()

    setIsStoring(true)
    setStoreError('')
    setShowOverwriteConfirm(false)

    try {
      const res = await fetch('/api/workflows', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filename, yaml_content: yamlContent, ...(force ? { force: true } : {}) }),
        signal: abortRef.current.signal,
      })

      const data: Workflow & { error?: string } = await res.json()

      if (res.status === 201) {
        addWorkflow(data)
        handleOpenChange(false)
      } else if (res.status === 409) {
        setShowOverwriteConfirm(true)
      } else {
        setStoreError(data.error ?? 'Erreur lors de la sauvegarde')
      }
    } catch (err) {
      if (err instanceof Error && err.name === 'AbortError') {
        return
      }
      setStoreError('Erreur réseau lors de la sauvegarde')
    } finally {
      setIsStoring(false)
    }
  }

  // Parse agent ids from yamlContent for badges display
  const agentIds: string[] = React.useMemo(() => {
    if (!yamlContent) return []
    try {
      const lines = yamlContent.split('\n')
      const ids: string[] = []
      for (const line of lines) {
        const match = line.match(/^\s+-?\s*id:\s*["']?([^"'\n]+)["']?\s*$/)
        if (match) ids.push(match[1].trim())
      }
      return ids
    } catch {
      return []
    }
  }, [yamlContent])

  return (
    <Dialog
      open={open}
      onOpenChange={(isOpen) => handleOpenChange(isOpen)}
    >
      <DialogContent
        className="bg-zinc-900 border border-zinc-800 text-zinc-200 sm:max-w-xl"
        showCloseButton
      >
        <DialogHeader>
          <DialogTitle className="text-zinc-100">
            {step === 1 ? 'Nouveau Workflow' : 'Workflow généré'}
          </DialogTitle>
        </DialogHeader>

        {step === 1 && (
          <div className="flex flex-col gap-4 py-2">
            <p className="text-xs text-zinc-400">
              Décrivez votre objectif en langage naturel. L&apos;IA génèrera un workflow YAML adapté.
            </p>
            <textarea
              className="w-full min-h-[120px] rounded-lg bg-zinc-800 border border-zinc-700 text-zinc-200 text-sm px-3 py-2 resize-y focus:outline-none focus:ring-1 focus:ring-zinc-500 placeholder:text-zinc-600"
              placeholder="Ex: Migrer une application React vers Next.js, analyser le code, effectuer la migration, puis valider les tests."
              value={brief}
              onChange={(e) => setBrief(e.target.value)}
              disabled={isGenerating}
            />
            {generateError && (
              <p className="text-xs text-red-400">{generateError}</p>
            )}
          </div>
        )}

        {step === 2 && (
          <div className="flex flex-col gap-4 py-2">
            {generateError && (
              <p className="text-xs text-red-400">{generateError}</p>
            )}

            {agentIds.length > 0 && (
              <div className="flex flex-wrap gap-2">
                {agentIds.map((id) => (
                  <span
                    key={id}
                    className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-mono bg-zinc-800 border border-zinc-700 text-zinc-300"
                  >
                    {id}
                  </span>
                ))}
              </div>
            )}

            <textarea
              className="w-full min-h-[240px] rounded-lg bg-zinc-800 border border-zinc-700 text-zinc-200 text-xs font-mono px-3 py-2 resize-y focus:outline-none focus:ring-1 focus:ring-zinc-500"
              value={yamlContent}
              onChange={(e) => setYamlContent(e.target.value)}
              disabled={isStoring}
            />

            <div className="flex flex-col gap-1">
              <label className="text-xs text-zinc-400">Nom du fichier</label>
              <input
                type="text"
                className="w-full rounded-lg bg-zinc-800 border border-zinc-700 text-zinc-200 text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-zinc-500 placeholder:text-zinc-600"
                placeholder="mon-workflow (sans extension)"
                value={filename}
                onChange={(e) => setFilename(e.target.value)}
                disabled={isStoring}
              />
            </div>

            {storeError && !showOverwriteConfirm && (
              <p className="text-xs text-red-400">{storeError}</p>
            )}

            {showOverwriteConfirm && (
              <div className="flex flex-col gap-2 rounded-lg bg-zinc-800 border border-amber-700/40 px-3 py-2">
                <p className="text-xs text-amber-400">
                  Un fichier <span className="font-mono">{filename}.yaml</span> existe déjà. Voulez-vous l&apos;écraser ?
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="destructive"
                    size="sm"
                    onClick={() => handleStore(true)}
                    disabled={isStoring}
                  >
                    Écraser
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setShowOverwriteConfirm(false)}
                    className="border-zinc-700 text-zinc-300 hover:bg-zinc-800"
                  >
                    Annuler
                  </Button>
                </div>
              </div>
            )}
          </div>
        )}

        <DialogFooter className="bg-zinc-900 border-t border-zinc-800">
          {step === 1 && (
            <Button
              onClick={handleGenerate}
              disabled={isGenerating || brief.trim().length < 10}
              className="bg-zinc-700 hover:bg-zinc-600 text-zinc-100"
            >
              {isGenerating ? 'Génération…' : 'Générer'}
            </Button>
          )}
          {step === 2 && !showOverwriteConfirm && (
            <Button
              onClick={() => handleStore(false)}
              disabled={isStoring || !filename.trim() || !yamlContent.trim()}
              className="bg-zinc-700 hover:bg-zinc-600 text-zinc-100"
            >
              {isStoring ? 'Création…' : 'Créer'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
