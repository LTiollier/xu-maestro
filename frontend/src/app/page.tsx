import { Sidebar } from '@/components/Sidebar'
import { Terminal } from '@/components/Terminal'
import { ErrorBoundary } from '@/components/ErrorBoundary'

export default function Home() {
  return (
    <div className="flex h-screen overflow-hidden bg-black text-zinc-100 font-sans selection:bg-blue-500/30">
      {/* Panneau de Gauche : L'Équipe / Pipeline */}
      <ErrorBoundary>
        <Sidebar />
      </ErrorBoundary>

      {/* Panneau de Droite : Le Terminal de contrôle */}
      <main className="flex-1 flex flex-col min-w-0">
        <ErrorBoundary>
          <Terminal />
        </ErrorBoundary>
      </main>
    </div>
  )
}
