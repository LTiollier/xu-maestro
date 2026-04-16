'use client'

import { Sidebar } from '@/components/v2/Sidebar'
import { Terminal } from '@/components/v2/Terminal'

export default function Home() {
  return (
    <div className="flex h-screen overflow-hidden bg-black text-zinc-100 font-sans selection:bg-blue-500/30">
      {/* Panneau de Gauche : L'Équipe / Pipeline */}
      <Sidebar />

      {/* Panneau de Droite : Le Terminal de contrôle */}
      <main className="flex-1 flex flex-col min-w-0">
        <Terminal />
      </main>
    </div>
  )
}
