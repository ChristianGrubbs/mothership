import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { getApiBaseUrl } from '../config';

const MIN_CHUNK_COUNT = 8;
const MAX_CHUNK_COUNT = 40;
const BASE_CHUNK_WIDTH = 88;
const RESIZE_DEBOUNCE_MS = 180;

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

const formatTime = (timeInSeconds) => `${timeInSeconds.toFixed(3)}s`;

const getAdaptiveChunkCount = (width, duration) => {
    if (!width) {
        return MIN_CHUNK_COUNT;
    }

    const durationFactor = clamp(duration / 18, 0, 1);
    const targetChunkWidth = 104 - (durationFactor * 24);
    return clamp(
        Math.round(width / Math.max(BASE_CHUNK_WIDTH * 0.64, targetChunkWidth)),
        MIN_CHUNK_COUNT,
        MAX_CHUNK_COUNT
    );
};

const VisualTimeline = ({ filename, duration, currentTime, endTime = currentTime, startTime, onSeek, enabled = true }) => {
    const timelineRef = useRef(null);
    const [timelineWidth, setTimelineWidth] = useState(0);
    const [debouncedWidth, setDebouncedWidth] = useState(0);
    const [samples, setSamples] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isDragging, setIsDragging] = useState(false);

    useEffect(() => {
        const node = timelineRef.current;
        if (!node || typeof ResizeObserver === 'undefined') {
            return undefined;
        }

        const observer = new ResizeObserver((entries) => {
            const nextWidth = Math.round(entries[0]?.contentRect?.width || 0);
            setTimelineWidth((previousWidth) => (
                previousWidth === nextWidth ? previousWidth : nextWidth
            ));
        });

        observer.observe(node);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (!timelineWidth) {
            return undefined;
        }

        const timeoutId = window.setTimeout(() => {
            setDebouncedWidth(timelineWidth);
        }, RESIZE_DEBOUNCE_MS);

        return () => window.clearTimeout(timeoutId);
    }, [timelineWidth]);

    const chunkCount = getAdaptiveChunkCount(debouncedWidth, duration);

    useEffect(() => {
        if (!enabled || !filename || !debouncedWidth) {
            return undefined;
        }

        let ignore = false;

        const fetchSamples = async () => {
            setIsLoading(true);
            try {
                const response = await axios.get(
                    `${getApiBaseUrl()}/timeline-samples/${encodeURIComponent(filename)}`,
                    { params: { count: chunkCount } }
                );

                if (!ignore) {
                    setSamples(response.data.samples || []);
                }
            } catch (error) {
                if (!ignore) {
                    console.error('Failed to load timeline samples:', error);
                    setSamples([]);
                }
            } finally {
                if (!ignore) {
                    setIsLoading(false);
                }
            }
        };

        fetchSamples();

        return () => {
            ignore = true;
        };
    }, [chunkCount, debouncedWidth, enabled, filename]);

    const seekFromClientX = (clientX) => {
        if (!timelineRef.current || duration <= 0) {
            return;
        }

        const rect = timelineRef.current.getBoundingClientRect();
        const percent = clamp((clientX - rect.left) / rect.width, 0, 1);
        onSeek(percent * duration);
    };

    const handlePointerDown = (event) => {
        setIsDragging(true);
        event.currentTarget.setPointerCapture?.(event.pointerId);
        seekFromClientX(event.clientX);
    };

    const handlePointerMove = (event) => {
        if (!isDragging) {
            return;
        }

        seekFromClientX(event.clientX);
    };

    const handlePointerEnd = (event) => {
        if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }
        setIsDragging(false);
    };

    const currentPercent = duration > 0 ? clamp((currentTime / duration) * 100, 0, 100) : 0;
    const endPercent = duration > 0 ? clamp((endTime / duration) * 100, 0, 100) : 0;
    const startPercent = duration > 0 ? clamp((startTime / duration) * 100, 0, 100) : 0;
    const loopLeft = Math.min(endPercent, startPercent);
    const loopWidth = Math.abs(endPercent - startPercent);
    const sampleWidth = samples.length > 0 ? `${100 / samples.length}%` : `${100 / chunkCount}%`;

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-600 dark:text-gray-400">
                <div className="flex items-center gap-3">
                    <span className="font-medium text-gray-700 dark:text-gray-200">Timeline</span>
                    <span className="hidden text-xs text-gray-500 dark:text-gray-500 sm:inline">
                        {samples.length || chunkCount} frame samples
                    </span>
                </div>
                <div className="flex items-center gap-4 text-xs">
                    <span className="text-emerald-700 dark:text-emerald-400">Start {formatTime(startTime)}</span>
                    <span className="text-primary-700 dark:text-primary-300">End {formatTime(endTime)}</span>
                </div>
            </div>

            <div
                ref={timelineRef}
                role="slider"
                aria-label="Video timeline"
                aria-valuemin={0}
                aria-valuemax={duration || 0}
                aria-valuenow={currentTime}
                tabIndex={0}
                className="relative h-20 w-full cursor-pointer touch-none select-none overflow-hidden rounded-lg border border-gray-300 bg-gray-900 shadow-inner dark:border-[#493d55] sm:h-24"
                onPointerDown={handlePointerDown}
                onPointerMove={handlePointerMove}
                onPointerUp={handlePointerEnd}
                onPointerCancel={handlePointerEnd}
                onKeyDown={(event) => {
                    const step = duration > 0 ? Math.max(duration / 100, 0.01) : 0.01;
                    if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
                        event.preventDefault();
                        onSeek(clamp(currentTime - step, 0, duration));
                    } else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        onSeek(clamp(currentTime + step, 0, duration));
                    } else if (event.key === 'Home') {
                        event.preventDefault();
                        onSeek(0);
                    } else if (event.key === 'End') {
                        event.preventDefault();
                        onSeek(duration);
                    }
                }}
            >
                <div className="absolute inset-0 flex">
                    {(samples.length > 0 ? samples : Array.from({ length: chunkCount })).map((sample, index) => (
                        <div
                            key={sample?.frame_url || index}
                            className="relative h-full border-r border-white/15 last:border-r-0"
                            style={{
                                width: sampleWidth,
                                backgroundImage: sample?.frame_url
                                    ? `url(${getApiBaseUrl()}${sample.frame_url})`
                                    : 'linear-gradient(135deg, rgba(148,163,184,0.20), rgba(17,24,39,0.80))',
                                backgroundSize: 'cover',
                                backgroundPosition: 'center',
                            }}
                        >
                            <div className="absolute inset-0 bg-gray-950/45" />
                            <div className="absolute inset-0 bg-gradient-to-b from-white/10 via-transparent to-primary-950/25" />
                        </div>
                    ))}
                </div>

                <div
                    className="absolute top-0 h-full rounded-md bg-primary-400/20"
                    style={{ left: `${loopLeft}%`, width: `${loopWidth}%` }}
                />

                <div
                    className="absolute top-0 h-full w-px bg-emerald-400 shadow-[0_0_0_1px_rgba(16,185,129,0.4)]"
                    style={{ left: `${startPercent}%` }}
                />
                <div
                    className="absolute top-0 h-full w-0.5 bg-white shadow-[0_0_0_1px_rgba(255,255,255,0.45)]"
                    style={{ left: `${currentPercent}%` }}
                />
                <div
                    className="absolute top-0 h-full w-0.5 bg-primary-300 shadow-[0_0_0_1px_rgba(167,139,250,0.45)]"
                    style={{ left: `${endPercent}%` }}
                />

                <div
                    className="absolute top-2 -translate-x-1/2 rounded-full border border-primary-200 bg-primary-300 px-2 py-0.5 text-[10px] font-semibold text-gray-950 shadow"
                    style={{ left: `${currentPercent}%` }}
                >
                    {formatTime(currentTime)}
                </div>

                <div
                    className="absolute bottom-2 -translate-x-1/2 rounded-full border border-emerald-200 bg-emerald-300/90 px-2 py-0.5 text-[10px] font-semibold text-gray-950 shadow"
                    style={{ left: `${startPercent}%` }}
                >
                    Start
                </div>

                {(!enabled || isLoading) && (
                    <div className="absolute inset-0 flex items-center justify-center bg-gray-950/50 text-xs font-medium text-white/75 backdrop-blur-[1px]">
                        {enabled ? 'Loading timeline frames…' : 'Timeline prepares after seam analysis…'}
                    </div>
                )}
            </div>

            <div className="flex justify-between text-xs text-gray-500 dark:text-gray-500 dark:text-gray-500">
                <span>0.000s</span>
                <span>{formatTime(duration || 0)}</span>
            </div>
        </div>
    );
};

export default VisualTimeline;
