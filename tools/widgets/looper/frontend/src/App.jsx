import React, { useEffect, useState } from 'react';
import UploadZone from './components/UploadZone';
import LoopEditor from './components/LoopEditor';
import { announceHeight, announceReady, subscribeToHost } from './mothershipBridge';
import { getRequestedTheme, getWidgetMode } from './config';

function App() {
  const [videoData, setVideoData] = useState(null);
  const widgetMode = getWidgetMode();
  const requestedTheme = getRequestedTheme();
  const embedded = widgetMode === 'embedded';
  const [systemDark, setSystemDark] = useState(() => window.matchMedia('(prefers-color-scheme: dark)').matches);
  const [themeMode, setThemeMode] = useState(() => {
    if (requestedTheme !== 'system') return requestedTheme;
    try {
      return window.localStorage.getItem('loop-refiner-theme') || 'system';
    } catch {
      return 'system';
    }
  });
  const resolvedTheme = themeMode === 'system' ? (systemDark ? 'dark' : 'light') : themeMode;

  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const handleChange = (event) => setSystemDark(event.matches);
    media.addEventListener?.('change', handleChange);
    return () => media.removeEventListener?.('change', handleChange);
  }, []);

  useEffect(() => {
    document.documentElement.classList.toggle('dark', resolvedTheme === 'dark');
    document.documentElement.style.colorScheme = resolvedTheme;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', resolvedTheme === 'dark' ? '#17141d' : '#f9fafb');
    try {
      window.localStorage.setItem('loop-refiner-theme', themeMode);
    } catch {
      // Embedded sandbox hosts may disable storage; theme still works for this session.
    }
  }, [resolvedTheme, themeMode]);

  useEffect(() => {
    announceReady();
    const unsubscribe = subscribeToHost((type, payload) => {
      if (type === 'reset') setVideoData(null);
      if (type === 'theme' && ['system', 'light', 'dark'].includes(payload.theme)) setThemeMode(payload.theme);
    });
    const observer = new ResizeObserver(announceHeight);
    observer.observe(document.body);
    return () => {
      unsubscribe();
      observer.disconnect();
    };
  }, []);

  const cycleTheme = () => setThemeMode((current) => ({ system: 'light', light: 'dark', dark: 'system' }[current]));

  return (
    <main className="min-h-screen bg-gray-50 text-gray-800 dark:bg-[#17141d] dark:text-slate-100">
      <section className={`mx-auto flex min-h-screen max-w-[1480px] flex-col ${embedded ? 'p-2 sm:p-3' : 'p-3 sm:px-6 sm:py-5 lg:px-8'}`}>
        {!embedded && (
          <header className="mb-3 flex items-start justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-[#3a3044] dark:bg-[#241f2b] sm:mb-5 sm:p-5">
            <div>
              <div className="mb-2 inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-primary-700 dark:bg-violet-500/10 dark:text-violet-300">
                Mothership utility sandbox
              </div>
              <h1 className="text-2xl font-bold tracking-tight text-gray-800 dark:text-white sm:text-3xl">
                Video Loop Refiner
              </h1>
              <p className="mt-2 hidden max-w-2xl text-sm leading-6 text-gray-500 dark:text-gray-400 sm:block">
                Find a clean repeat point with frame comparison, ranked candidates, seam preview, and frame-accurate export.
              </p>
            </div>
            <button onClick={cycleTheme} aria-label={`Theme: ${themeMode}`} title={`Theme: ${themeMode}`} className="flex h-10 shrink-0 items-center gap-2 rounded-lg border border-gray-200 px-3 text-xs font-semibold capitalize text-gray-600 hover:bg-gray-50 dark:border-[#493d55] dark:text-gray-300 dark:hover:bg-[#332b3d]">
              {resolvedTheme === 'dark' ? (
                <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M20 15.5A8.5 8.5 0 118.5 4 7 7 0 0020 15.5z" /></svg>
              ) : (
                <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></svg>
              )}
              <span className="hidden sm:inline">{themeMode}</span>
            </button>
          </header>
        )}

        <div className="flex-1 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-[#3a3044] dark:bg-[#211c28]">
          {!videoData ? (
            <div className="p-5 sm:p-7">
              <UploadZone onUploadComplete={setVideoData} embedded={embedded} />
            </div>
          ) : (
            <LoopEditor videoData={videoData} onReset={() => setVideoData(null)} embedded={embedded} />
          )}
        </div>
      </section>
    </main>
  );
}

export default App;
