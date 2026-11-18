import React from 'react';

const PreviewModal = ({ videoUrl, onClose, cutTime, startTime = 0 }) => {
    const videoRef = React.useRef(null);
    const [speed, setSpeed] = React.useState(1);
    const hasCustomCutTime = cutTime !== null && cutTime !== undefined;
    const normalizedCutTime = hasCustomCutTime ? Math.max(cutTime, 0) : null;
    const normalizedStartTime = hasCustomCutTime ? Math.max(0, Math.min(startTime, normalizedCutTime)) : 0;

    React.useEffect(() => {
        if (videoRef.current) videoRef.current.playbackRate = speed;
    }, [speed]);

    React.useEffect(() => {
        const video = videoRef.current;
        if (!video) return undefined;

        const restart = () => {
            video.currentTime = normalizedStartTime;
            if (hasCustomCutTime && normalizedCutTime <= normalizedStartTime) return video.pause();
            video.play().catch(() => {});
        };
        const handleTimeUpdate = () => {
            if (normalizedCutTime !== null && video.currentTime >= normalizedCutTime) restart();
        };
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
        };

        restart();
        video.addEventListener('loadedmetadata', restart);
        if (hasCustomCutTime && normalizedCutTime > 0) video.addEventListener('timeupdate', handleTimeUpdate);
        window.addEventListener('keydown', handleKeyDown);
        return () => {
            video.removeEventListener('loadedmetadata', restart);
            video.removeEventListener('timeupdate', handleTimeUpdate);
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [hasCustomCutTime, normalizedCutTime, normalizedStartTime, onClose, videoUrl]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/80 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="Loop preview">
            <div className="flex w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-[#493d55] dark:bg-[#211c28]">
                <div className="flex items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-[#3a3044]">
                    <div>
                        <h3 className="text-base font-semibold text-gray-800 dark:text-white">{hasCustomCutTime ? 'Seam preview' : 'Export preview'}</h3>
                        <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Watch the transition several times before exporting.</p>
                    </div>
                    <button onClick={onClose} className="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-[#332b3d] dark:hover:text-white" aria-label="Close preview">
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div className="relative aspect-video bg-black">
                    <video ref={videoRef} src={videoUrl} className="h-full w-full object-contain" controls autoPlay loop={!hasCustomCutTime} muted playsInline />
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 bg-gray-50 px-5 py-3 dark:bg-[#19151f]">
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {hasCustomCutTime ? `Looping from ${normalizedStartTime.toFixed(3)}s to ${normalizedCutTime.toFixed(3)}s` : 'Playing the generated export'}
                    </p>
                    <div className="flex items-center gap-1 rounded-lg border border-gray-200 bg-white p-1 dark:border-[#493d55] dark:bg-[#2a2432]">
                        <span className="px-2 text-xs text-gray-400">Speed</span>
                        {[0.5, 1, 1.5, 2].map((rate) => (
                            <button key={rate} onClick={() => setSpeed(rate)} className={`rounded-md px-2 py-1 text-xs font-semibold ${speed === rate ? 'bg-primary-600 text-white' : 'text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-[#3a3044]'}`}>{rate}×</button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default PreviewModal;
