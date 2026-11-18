import React, { useState, useRef, useEffect, useMemo, useCallback } from 'react';
import axios from 'axios';
import PreviewModal from './PreviewModal';
import VisualTimeline from './VisualTimeline';
import { announceExport } from '../mothershipBridge';
import { getApiBaseUrl } from '../config';

const clampTime = (time, maxDuration) => {
    if (!Number.isFinite(time)) return 0;
    return Math.min(Math.max(time, 0), Math.max(maxDuration, 0));
};

const formatTime = (time) => `${Number(time || 0).toFixed(3)}s`;
const hasPreviewCutTime = (time) => time !== null && time !== undefined;
const analysisLabels = ['Sampling the search range…', 'Comparing frame structure…', 'Checking motion continuity…', 'Ranking the cleanest seams…'];

const scoreTone = (score) => {
    if (score === null || score === undefined) return 'text-gray-500 border-gray-200 bg-gray-50 dark:text-gray-400 dark:border-[#493d55] dark:bg-[#19151f]';
    if (score >= 85) return 'text-emerald-700 border-emerald-200 bg-emerald-50 dark:text-emerald-300 dark:border-emerald-800 dark:bg-emerald-900/20';
    if (score >= 70) return 'text-amber-700 border-amber-200 bg-amber-50 dark:text-amber-300 dark:border-amber-800 dark:bg-amber-900/20';
    return 'text-red-700 border-red-200 bg-red-50 dark:text-red-300 dark:border-red-800 dark:bg-red-900/20';
};

const LoopEditor = ({ videoData, onReset, embedded = false }) => {
    const videoRef = useRef(null);
    const autoMatchStartedRef = useRef(false);
    const loopStartRef = useRef(0);
    const loopEndRef = useRef(0);
    const loopEnabledRef = useRef(false);

    const [currentTime, setCurrentTime] = useState(0);
    const [cutTime, setCutTime] = useState(0);
    const [duration, setDuration] = useState(0);
    const [trimming, setTrimming] = useState(false);
    const [trimmedUrl, setTrimmedUrl] = useState(null);
    const [trimmedFilename, setTrimmedFilename] = useState(null);
    const [showPreview, setShowPreview] = useState(false);
    const [previewCutTime, setPreviewCutTime] = useState(null);
    const [fps, setFps] = useState(30);
    const [startTime, setStartTime] = useState(0);
    const [matchScore, setMatchScore] = useState(null);
    const [isAnalyzing, setIsAnalyzing] = useState(false);
    const [candidates, setCandidates] = useState([]);
    const [showAllCandidates, setShowAllCandidates] = useState(false);
    const [isPlaying, setIsPlaying] = useState(false);
    const [isMuted, setIsMuted] = useState(true);
    const [seamDetails, setSeamDetails] = useState(null);
    const [analysisStage, setAnalysisStage] = useState(0);
    const [autoSummary, setAutoSummary] = useState(null);
    const [videoReady, setVideoReady] = useState(false);
    const [metadataReady, setMetadataReady] = useState(false);
    const [initialAnalysisComplete, setInitialAnalysisComplete] = useState(false);

    const { video_url, reference_frame_url, filename, original_name, fps: uploadedFps, duration: uploadedDuration } = videoData;
    const apiBase = getApiBaseUrl();
    const fullVideoUrl = `${apiBase}${video_url}`;
    const currentRefUrl = `${apiBase}${reference_frame_url}`;
    const frameDuration = useMemo(() => (fps > 0 ? 1 / fps : 1 / 30), [fps]);
    const cutFrame = useMemo(() => Math.max(0, Math.round(cutTime * fps)), [cutTime, fps]);
    const cutFrameTime = useMemo(() => cutFrame / fps, [cutFrame, fps]);

    const seekToTime = useCallback((time) => {
        const nextTime = clampTime(time, duration);
        if (videoRef.current) videoRef.current.currentTime = nextTime;
        setCurrentTime(nextTime);
    }, [duration]);

    const setStart = useCallback((time) => {
        setStartTime(clampTime(time, duration));
        setCandidates([]);
        setShowAllCandidates(false);
        setAutoSummary(null);
        setSeamDetails(null);
    }, [duration]);

    const openPreview = (previewEndTime = null) => {
        videoRef.current?.pause();
        setPreviewCutTime(previewEndTime);
        setShowPreview(true);
        window.history.pushState({ modal: 'preview' }, '');
    };

    useEffect(() => {
        const handlePopState = () => {
            if (showPreview) {
                setShowPreview(false);
                setPreviewCutTime(null);
            }
        };
        window.addEventListener('popstate', handlePopState);
        return () => window.removeEventListener('popstate', handlePopState);
    }, [showPreview]);

    useEffect(() => {
        const video = videoRef.current;
        if (!video) return undefined;

        const fetchMetadata = async () => {
            if (uploadedFps && uploadedDuration) {
                setFps(uploadedFps);
                setDuration(uploadedDuration);
                setMetadataReady(true);
                return;
            }
            try {
                const response = await axios.get(`${apiBase}/metadata/${encodeURIComponent(filename)}`);
                setFps(response.data.fps || 30);
                if (response.data.duration) setDuration(response.data.duration);
            } catch (err) {
                console.error('Failed to fetch metadata:', err);
            } finally {
                setMetadataReady(true);
            }
        };
        fetchMetadata();

        const handleTimeUpdate = () => {
            const nextTime = video.currentTime;
            if (loopEnabledRef.current && loopEndRef.current > loopStartRef.current && nextTime >= loopEndRef.current - 0.012) {
                video.currentTime = loopStartRef.current;
                setCurrentTime(loopStartRef.current);
                return;
            }
            setCurrentTime(nextTime);
        };
        const handleLoadedMetadata = () => {
            setVideoReady(true);
            setDuration(video.duration);
            const initialEnd = Math.max(video.duration - frameDuration, 0);
            video.currentTime = initialEnd;
            setCurrentTime(initialEnd);
            setCutTime(initialEnd);
        };

        const handlePlay = () => setIsPlaying(true);
        const handlePause = () => setIsPlaying(false);
        video.addEventListener('timeupdate', handleTimeUpdate);
        video.addEventListener('loadedmetadata', handleLoadedMetadata);
        video.addEventListener('play', handlePlay);
        video.addEventListener('pause', handlePause);
        video.addEventListener('ended', handlePause);
        return () => {
            video.removeEventListener('timeupdate', handleTimeUpdate);
            video.removeEventListener('loadedmetadata', handleLoadedMetadata);
            video.removeEventListener('play', handlePlay);
            video.removeEventListener('pause', handlePause);
            video.removeEventListener('ended', handlePause);
        };
    }, [apiBase, filename, frameDuration, uploadedDuration, uploadedFps]);

    useEffect(() => {
        loopStartRef.current = startTime;
        loopEndRef.current = cutTime;
        loopEnabledRef.current = initialAnalysisComplete;
    }, [cutTime, initialAnalysisComplete, startTime]);

    useEffect(() => {
        const video = videoRef.current;
        if (!video) return undefined;
        let callbackId;
        let stopped = false;
        const checkBoundary = () => {
            if (stopped) return;
            if (loopEnabledRef.current && !video.paused && loopEndRef.current > loopStartRef.current && video.currentTime >= loopEndRef.current - (frameDuration * 0.5)) {
                video.currentTime = loopStartRef.current;
                setCurrentTime(loopStartRef.current);
            }
            callbackId = video.requestVideoFrameCallback
                ? video.requestVideoFrameCallback(checkBoundary)
                : window.requestAnimationFrame(checkBoundary);
        };
        checkBoundary();
        return () => {
            stopped = true;
            if (video.cancelVideoFrameCallback && callbackId) video.cancelVideoFrameCallback(callbackId);
            else if (callbackId) window.cancelAnimationFrame(callbackId);
        };
    }, [frameDuration]);

    useEffect(() => {
        let ignore = false;
        const fetchScore = async () => {
            if (!initialAnalysisComplete || isAnalyzing || isPlaying || seamDetails?.frame_number === cutFrame) return;
            try {
                const response = await axios.get(`${apiBase}/match-score/${encodeURIComponent(filename)}`, {
                    params: { ref_time: startTime, target_time: cutFrameTime },
                });
                if (!ignore) {
                    setMatchScore(response.data.score);
                    setSeamDetails(response.data);
                }
            } catch (err) {
                if (!ignore) {
                    console.error('Failed to fetch match score:', err);
                    setMatchScore(null);
                    setSeamDetails(null);
                }
            }
        };
        const timeoutId = setTimeout(fetchScore, 260);
        return () => {
            ignore = true;
            clearTimeout(timeoutId);
        };
    }, [apiBase, startTime, cutFrame, cutFrameTime, filename, initialAnalysisComplete, isAnalyzing, isPlaying, seamDetails?.frame_number]);

    useEffect(() => {
        if (!isAnalyzing) {
            setAnalysisStage(0);
            return undefined;
        }
        const interval = setInterval(() => setAnalysisStage((stage) => (stage + 1) % analysisLabels.length), 1400);
        return () => clearInterval(interval);
    }, [isAnalyzing]);

    useEffect(() => {
        const handleKeyDown = (e) => {
            if (!videoRef.current || e.target?.tagName === 'INPUT') return;
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                e.preventDefault();
                const direction = e.key === 'ArrowLeft' ? -1 : 1;
                const nextTime = clampTime(cutTime + direction * frameDuration, duration);
                videoRef.current.currentTime = nextTime;
                setCurrentTime(nextTime);
                setCutTime(nextTime);
            }
            if (e.key.toLowerCase() === 's') setStart(currentTime);
            if (e.key === ' ') {
                e.preventDefault();
                videoRef.current.paused ? videoRef.current.play() : videoRef.current.pause();
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [currentTime, cutTime, frameDuration, duration, setStart]);

    const handleStep = (direction) => {
        const nextTime = clampTime(cutTime + direction * frameDuration, duration);
        setCutTime(nextTime);
        seekToTime(nextTime);
    };

    const chooseCandidate = (candidate) => {
        setMatchScore(candidate.score);
        setSeamDetails(candidate);
        setCutTime(candidate.time);
        seekToTime(candidate.time);
    };

    const togglePlayback = () => {
        const video = videoRef.current;
        if (!video) return;
        if (video.paused) {
            if (video.currentTime < startTime || video.currentTime >= cutTime - frameDuration) {
                video.currentTime = startTime;
                setCurrentTime(startTime);
            }
            video.play().catch(() => {});
        } else video.pause();
    };

    const handleTrim = async () => {
        setTrimming(true);
        try {
            const response = await axios.post(`${apiBase}/trim`, { filename, start_time: startTime, cut_time: cutTime });
            const downloadUrl = `${apiBase}/download/${encodeURIComponent(response.data.filename)}`;
            setTrimmedUrl(`${apiBase}${response.data.trimmed_video_url}`);
            setTrimmedFilename(response.data.filename);
            announceExport({ filename: response.data.filename, downloadUrl, startTime, endTime: cutTime, score: matchScore });
            openPreview();
        } catch (err) {
            console.error(err);
            alert(err.response?.data?.detail || 'Trim failed');
        } finally {
            setTrimming(false);
        }
    };

    const handleAutoMatch = useCallback(async () => {
        setIsAnalyzing(true);
        setCandidates([]);
        setShowAllCandidates(false);
        setAutoSummary(null);
        try {
            const minTime = Math.max(startTime + frameDuration, duration * 0.55);
            const response = await axios.get(`${apiBase}/match-candidates/${encodeURIComponent(filename)}`, {
                params: { ref_time: startTime, min_time: minTime, max_time: Math.max(duration - frameDuration, minTime), count: 8 },
            });
            const nextCandidates = response.data.candidates || [];
            setCandidates(nextCandidates);
            setAutoSummary({ searchedFrames: response.data.searched_frames, method: response.data.method });
            if (nextCandidates[0]) {
                const bestCandidate = nextCandidates[0];
                setMatchScore(bestCandidate.score);
                setSeamDetails(bestCandidate);
                setCutTime(bestCandidate.time);
                setIsMuted(true);
                loopStartRef.current = startTime;
                loopEndRef.current = bestCandidate.time;
                loopEnabledRef.current = true;
                const video = videoRef.current;
                if (video) {
                    video.muted = true;
                    video.currentTime = startTime;
                    setCurrentTime(startTime);
                    video.play().catch(() => {});
                }
            }
        } catch (err) {
            console.error(err);
            alert(err.response?.data?.detail || 'Auto-match failed');
        } finally {
            setIsAnalyzing(false);
            setInitialAnalysisComplete(true);
        }
    }, [apiBase, duration, filename, frameDuration, startTime]);

    useEffect(() => {
        if (!videoReady || !metadataReady || duration <= frameDuration || autoMatchStartedRef.current) return;
        autoMatchStartedRef.current = true;
        void handleAutoMatch();
    }, [videoReady, metadataReady, duration, frameDuration, handleAutoMatch]);

    return (
        <div className={`grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_23rem] ${embedded ? 'min-h-[640px]' : 'min-h-[calc(100vh-12rem)]'}`}>
            {showPreview && (
                <PreviewModal
                    videoUrl={hasPreviewCutTime(previewCutTime) ? fullVideoUrl : trimmedUrl}
                    cutTime={previewCutTime}
                    startTime={hasPreviewCutTime(previewCutTime) ? startTime : 0}
                    onClose={() => window.history.back()}
                />
            )}

            <section className="border-b border-gray-200 p-3 dark:border-[#3a3044] sm:p-4 lg:border-b-0 lg:border-r lg:p-5">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div className="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Loaded asset</div>
                        <h2 className="max-w-[65vw] truncate text-base font-semibold text-gray-800 dark:text-white sm:max-w-2xl sm:text-lg">{original_name || filename}</h2>
                    </div>
                    <button onClick={onReset} className="shrink-0 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-[#493d55] dark:text-gray-300 dark:hover:bg-[#2a2432] sm:px-4">New clip</button>
                </div>

                <div className="group relative aspect-[4/5] w-full overflow-hidden rounded-xl border border-gray-300 bg-[#15111a] shadow-sm dark:border-[#493d55] sm:aspect-video">
                    { !initialAnalysisComplete && <img src={currentRefUrl} alt="" className="absolute inset-0 h-full w-full scale-105 object-cover opacity-30 blur-md" /> }
                    <video ref={videoRef} src={fullVideoUrl} poster={currentRefUrl} preload="metadata" muted={isMuted} className={`absolute inset-0 h-full w-full object-contain transition-opacity duration-500 ${initialAnalysisComplete ? 'opacity-100' : 'opacity-0'}`} controls={false} playsInline />
                    {!initialAnalysisComplete && (
                        <div className="absolute inset-0 z-30 flex items-center justify-center bg-[#17131d]/75 px-6 text-white backdrop-blur-sm">
                            <div className="w-full max-w-md text-center">
                                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-500/20 ring-1 ring-primary-300/30">
                                    <svg className="h-7 w-7 animate-spin text-primary-300" viewBox="0 0 24 24"><circle className="opacity-20" cx="12" cy="12" r="9" fill="none" stroke="currentColor" strokeWidth="3" /><path d="M21 12a9 9 0 00-9-9" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>
                                </div>
                                <h3 className="mt-4 text-lg font-semibold">Optimizing your loop</h3>
                                <p className="mt-1 text-sm text-white/65">{!videoReady ? 'Preparing the video player…' : !metadataReady ? 'Reading frame timing…' : analysisLabels[analysisStage]}</p>
                                <div className="mt-5 h-1.5 overflow-hidden rounded-full bg-white/10">
                                    <div className="h-full rounded-full bg-gradient-to-r from-primary-500 to-fuchsia-400 transition-all duration-700" style={{ width: `${!videoReady ? 18 : !metadataReady ? 28 : [42, 62, 79, 92][analysisStage]}%` }} />
                                </div>
                                <div className="mt-3 flex items-center justify-center gap-2 text-[11px] text-white/45">
                                    <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" />
                                    Local frame analysis · longer clips may take a moment over remote connections
                                </div>
                            </div>
                        </div>
                    )}
                    <button
                        type="button"
                        onClick={togglePlayback}
                        disabled={!initialAnalysisComplete}
                        aria-label={isPlaying ? 'Pause video' : 'Play video'}
                        className="absolute bottom-3 left-3 z-20 flex h-10 w-10 items-center justify-center rounded-full bg-black/70 text-white shadow-lg backdrop-blur transition hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-300"
                    >
                        {isPlaying ? (
                            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M6 4h4v16H6zM14 4h4v16h-4z" /></svg>
                        ) : (
                            <svg className="ml-0.5 h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
                        )}
                    </button>
                    {initialAnalysisComplete && isPlaying && (
                        <div className="absolute left-3 top-3 z-20 flex items-center gap-2 rounded-full bg-black/65 px-3 py-1.5 text-[11px] font-semibold text-white backdrop-blur">
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" />
                            Looping preview
                        </div>
                    )}
                    <button type="button" onClick={() => setIsMuted((muted) => !muted)} disabled={!initialAnalysisComplete} aria-label={isMuted ? 'Unmute preview' : 'Mute preview'} className="absolute bottom-3 left-16 z-20 flex h-10 items-center justify-center rounded-full bg-black/70 px-3 text-xs font-semibold text-white backdrop-blur transition hover:bg-black/85 disabled:opacity-0">
                        {isMuted ? 'Muted' : 'Sound on'}
                    </button>
                    <div className="absolute bottom-3 right-3 z-20 rounded-md bg-black/70 px-2 py-1 text-xs font-medium text-white/90 backdrop-blur">
                        {Math.round(fps)} fps · {formatTime(currentTime)}
                    </div>
                </div>

                <div className="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-[#3a3044] dark:bg-[#19151f] sm:mt-4 sm:p-4">
                    <VisualTimeline filename={filename} duration={duration} currentTime={currentTime} endTime={cutTime} startTime={startTime} onSeek={(time) => { setCutTime(time); seekToTime(time); }} enabled={initialAnalysisComplete} />
                </div>

                {candidates.length > 0 && (
                    <div className="mt-3 rounded-xl border border-primary-200 bg-primary-50/60 p-3 dark:border-primary-900 dark:bg-primary-900/10 sm:mt-4 sm:p-4">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-100">Alternative seams</h3>
                            <span className="text-xs text-gray-500 dark:text-gray-500">Best match is selected</span>
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {(showAllCandidates ? candidates : candidates.slice(0, 4)).map((candidate, index) => (
                                <button
                                    key={`${candidate.time}-${index}`}
                                    onClick={() => chooseCandidate(candidate)}
                                    className={`overflow-hidden rounded-lg border text-left transition hover:border-primary-400 ${Math.abs(candidate.time - cutTime) < frameDuration / 2 ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-gray-200 bg-white dark:border-[#493d55] dark:bg-[#2a2432]'}`}
                                >
                                    <img src={`${apiBase}${candidate.frame_url}`} alt="Candidate frame" loading="lazy" decoding="async" className="h-20 w-full object-cover opacity-80" />
                                    <div className="p-2 text-xs">
                                        <div className="flex items-center justify-between gap-1">
                                            <div className="font-semibold text-gray-800 dark:text-white">#{index + 1} · {candidate.score.toFixed(1)}%</div>
                                            <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[9px] font-medium text-gray-500 dark:bg-[#19151f] dark:text-gray-400">{candidate.quality}</span>
                                        </div>
                                        <div className="mt-1 text-gray-500 dark:text-gray-400">Frame {candidate.frame_number} · {formatTime(candidate.time)}</div>
                                    </div>
                                </button>
                            ))}
                        </div>
                        {candidates.length > 4 && (
                            <button onClick={() => setShowAllCandidates((shown) => !shown)} className="mt-3 text-xs font-semibold text-primary-700 hover:text-primary-600 dark:text-primary-300">
                                {showAllCandidates ? 'Show fewer options' : `Show ${candidates.length - 4} more options`}
                            </button>
                        )}
                    </div>
                )}
            </section>

            <aside className="space-y-4 bg-gray-50 p-4 pb-28 dark:bg-[#19151f] lg:sticky lg:top-0 lg:self-start lg:p-5">
                <div className="hidden rounded-xl border border-primary-200 bg-white p-5 shadow-sm dark:border-primary-900 dark:bg-[#211c28] lg:block">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h3 className="font-semibold text-gray-800 dark:text-white">Export loop</h3>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{formatTime(startTime)} → {formatTime(cutTime)}</p>
                        </div>
                        <span className="rounded-full bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">MP4</span>
                    </div>
                    <button onClick={handleTrim} disabled={trimming || cutTime <= startTime || !initialAnalysisComplete} className="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14" /></svg>
                        {trimming ? 'Creating MP4…' : 'Export MP4'}
                    </button>
                    <button onClick={() => openPreview(cutTime)} disabled={!initialAnalysisComplete} className="mt-2 w-full rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-[#493d55] dark:bg-[#2a2432] dark:text-gray-200">Open focused preview</button>
                    {trimmedUrl && (
                        <div className="mt-3 grid grid-cols-2 gap-2">
                            <button onClick={() => openPreview()} className="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 dark:border-[#493d55] dark:text-gray-200">View file</button>
                            <a href={`${apiBase}/download/${encodeURIComponent(trimmedFilename)}`} download className="rounded-lg bg-emerald-600 px-3 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-500">Download</a>
                        </div>
                    )}
                </div>

                <div className={`rounded-xl border p-5 ${scoreTone(matchScore)}`}>
                    <div className="flex items-center justify-between gap-3">
                        <div className="text-xs font-medium uppercase tracking-wide opacity-70">Loop confidence</div>
                        {seamDetails?.quality && <span className="rounded-full border border-current/20 px-2 py-0.5 text-[10px] font-semibold">{seamDetails.quality}</span>}
                    </div>
                    <div className="mt-2 text-4xl font-bold">{matchScore === null ? '—' : `${matchScore.toFixed(1)}%`}</div>
                    {seamDetails?.appearance_score !== undefined ? (
                        <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                            <div className="rounded-lg bg-white/50 p-2 dark:bg-black/10"><div className="opacity-60">Visual match</div><div className="mt-0.5 font-semibold">{seamDetails.appearance_score.toFixed(1)}%</div></div>
                            <div className="rounded-lg bg-white/50 p-2 dark:bg-black/10"><div className="opacity-60">Motion flow</div><div className="mt-0.5 font-semibold">{seamDetails.motion_score.toFixed(1)}%</div></div>
                        </div>
                    ) : null}
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-[#3a3044] dark:bg-[#211c28]">
                    <h3 className="font-semibold text-gray-800 dark:text-white">Fine tune</h3>
                    <div className="mt-4 grid grid-cols-2 gap-2">
                        <button onClick={() => handleStep(-1)} className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-[#493d55] dark:bg-[#2a2432] dark:text-gray-200 dark:hover:bg-[#332b3d]">← -1 frame</button>
                        <button onClick={() => handleStep(1)} className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-[#493d55] dark:bg-[#2a2432] dark:text-gray-200 dark:hover:bg-[#332b3d]">+1 frame →</button>
                    </div>
                    <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-[#493d55] dark:bg-[#19151f]"><div className="text-gray-500 dark:text-gray-500">Start</div><div className="font-mono text-gray-700 dark:text-gray-200">{formatTime(startTime)}</div></div>
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-[#493d55] dark:bg-[#19151f]"><div className="text-gray-500 dark:text-gray-500">End</div><div className="font-mono text-gray-700 dark:text-gray-200">{formatTime(cutTime)}</div></div>
                    </div>
                    <button onClick={() => setStart(currentTime)} className="mt-3 w-full rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm font-semibold text-primary-700 hover:bg-primary-100 dark:border-primary-800 dark:bg-primary-900/20 dark:text-primary-300">Set current frame as start</button>
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-5 dark:border-[#3a3044] dark:bg-[#211c28]">
                    <h3 className="font-semibold text-gray-800 dark:text-white">Auto Match</h3>
                    <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">Runs automatically after upload. Rerun it after choosing a different start frame.</p>
                    <button onClick={handleAutoMatch} disabled={isAnalyzing || duration <= 0} className="mt-3 w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50">
                        {isAnalyzing ? analysisLabels[analysisStage] : autoSummary ? 'Run Auto Match again' : 'Auto-find cleanest seam'}
                    </button>
                    {autoSummary && !isAnalyzing && (
                        <div className="mt-3 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300">
                            <svg className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" /></svg>
                            Analyzed {autoSummary.searchedFrames} frames and selected the strongest seam.
                        </div>
                    )}
                </div>

                <div className="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 p-3 shadow-[0_-8px_30px_rgba(15,23,42,0.14)] backdrop-blur dark:border-[#493d55] dark:bg-[#211c28]/95 lg:hidden" style={{ paddingBottom: 'max(0.75rem, env(safe-area-inset-bottom))' }}>
                    <div className="mx-auto flex max-w-xl items-center gap-2">
                        <button onClick={() => openPreview(cutTime)} disabled={!initialAnalysisComplete} aria-label="Preview loop" className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-gray-200 text-gray-700 disabled:opacity-50 dark:border-[#493d55] dark:text-gray-200">
                            <svg className="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
                        </button>
                        {trimmedUrl ? (
                            <a href={`${apiBase}/download/${encodeURIComponent(trimmedFilename)}`} download className="flex h-12 flex-1 items-center justify-center rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white">Download MP4</a>
                        ) : (
                            <button onClick={handleTrim} disabled={trimming || cutTime <= startTime || !initialAnalysisComplete} className="h-12 flex-1 rounded-xl bg-primary-600 px-4 text-sm font-semibold text-white shadow-sm disabled:opacity-50">
                                {trimming ? 'Creating MP4…' : `Export loop · ${formatTime(cutTime - startTime)}`}
                            </button>
                        )}
                    </div>
                </div>
            </aside>
        </div>
    );
};

export default LoopEditor;
