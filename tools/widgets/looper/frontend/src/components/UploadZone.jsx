import React, { useState } from 'react';
import axios from 'axios';
import { getApiBaseUrl } from '../config';

const UploadZone = ({ onUploadComplete, embedded = false }) => {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState(null);
    const [isDragging, setIsDragging] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(0);

    const handleUpload = async (file) => {
        if (!file) return;
        if (!file.type.startsWith('video/')) {
            setError('Choose a video file such as MP4, MOV, or WebM.');
            return;
        }

        setUploading(true);
        setUploadProgress(0);
        setError(null);
        const formData = new FormData();
        formData.append('file', file);

        try {
            const response = await axios.post(`${getApiBaseUrl()}/upload`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
                onUploadProgress: ({ loaded, total }) => {
                    const size = total || file.size || 1;
                    setUploadProgress(Math.min(100, Math.round((loaded / size) * 100)));
                },
            });
            onUploadComplete({ ...response.data, original_name: file.name });
        } catch (err) {
            console.error(err);
            setError(err.response?.data?.detail || 'The video could not be prepared. Please try another file.');
        } finally {
            setUploading(false);
        }
    };

    const onDrop = (event) => {
        event.preventDefault();
        setIsDragging(false);
        handleUpload(event.dataTransfer.files?.[0]);
    };

    return (
        <div className={`grid gap-6 ${embedded ? '' : 'lg:grid-cols-[minmax(0,1fr)_20rem]'}`}>
            <label
                htmlFor="video-upload"
                className={`group flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-5 text-center transition-colors sm:p-8 ${embedded ? 'min-h-[280px] sm:min-h-[300px]' : 'min-h-[320px] sm:min-h-[420px]'} ${isDragging
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/10'
                    : 'border-gray-300 bg-gray-50 hover:border-primary-400 hover:bg-primary-50/50 dark:border-[#493d55] dark:bg-[#19151f] dark:hover:border-primary-500 dark:hover:bg-primary-900/10'
                    }`}
                onDragOver={(event) => { event.preventDefault(); setIsDragging(true); }}
                onDragLeave={(event) => { event.preventDefault(); setIsDragging(false); }}
                onDrop={onDrop}
            >
                <input
                    type="file"
                    accept="video/mp4,video/quicktime,video/webm,video/x-m4v,video/x-matroska,video/x-msvideo"
                    onChange={(event) => handleUpload(event.target.files?.[0])}
                    className="hidden"
                    id="video-upload"
                    disabled={uploading}
                />

                <div className="mb-5 flex h-14 w-14 items-center justify-center rounded-xl bg-primary-100 text-primary-600 dark:bg-primary-900/30 dark:text-primary-300">
                    {uploading ? (
                        <svg className="h-6 w-6 animate-spin" viewBox="0 0 24 24">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                        </svg>
                    ) : (
                        <svg className="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M7 16a4 4 0 01-.88-7.9A5 5 0 0115.9 6H16a5 5 0 011 9.9M12 12v9m0-9l-3 3m3-3l3 3" />
                        </svg>
                    )}
                </div>

                <h2 className="text-xl font-semibold text-gray-800 dark:text-white">
                    {uploading ? (uploadProgress < 100 ? `Uploading video · ${uploadProgress}%` : 'Preparing preview and timing…') : isDragging ? 'Drop video to continue' : 'Add a video to refine'}
                </h2>
                <p className="mt-2 max-w-lg text-sm leading-6 text-gray-500 dark:text-gray-400">
                    {uploading ? 'Keep this page open while the secure transfer finishes.' : 'Uploading creates a temporary sandbox copy. Your original file is never modified.'}
                </p>
                {uploading && (
                    <div className="mt-5 h-2 w-full max-w-sm overflow-hidden rounded-full bg-gray-200 dark:bg-[#332b3d]">
                        <div className="h-full rounded-full bg-gradient-to-r from-primary-600 to-fuchsia-500 transition-all duration-300" style={{ width: `${Math.max(uploadProgress, 4)}%` }} />
                    </div>
                )}
                {!uploading && (
                    <span className="mt-5 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition group-hover:bg-primary-500">
                        Choose video
                    </span>
                )}
                <p className="mt-4 text-xs text-gray-400 dark:text-gray-500">MP4, MOV, WebM, AVI, MKV · best for short clips</p>
                {error && (
                    <div role="alert" className="mt-5 max-w-lg rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                        {error}
                    </div>
                )}
            </label>

            {!embedded && (
                <aside className="hidden rounded-xl border border-gray-200 bg-white p-5 dark:border-[#3a3044] dark:bg-[#211c28] lg:block">
                    <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-100">How it works</h3>
                    <ol className="mt-5 space-y-5">
                        {[
                            ['1', 'Choose a start frame', 'Set the frame the loop should return to.'],
                            ['2', 'Automatic seam search', 'The cleanest endpoint is selected as soon as the upload is ready.'],
                            ['3', 'Preview and export', 'Confirm the repeat before creating the MP4.'],
                        ].map(([number, title, detail]) => (
                            <li key={number} className="flex gap-3">
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">{number}</span>
                                <div>
                                    <p className="text-sm font-medium text-gray-700 dark:text-gray-200">{title}</p>
                                    <p className="mt-0.5 text-xs leading-5 text-gray-500 dark:text-gray-500">{detail}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                    <div className="mt-6 rounded-lg bg-gray-50 p-3 text-xs leading-5 text-gray-500 dark:bg-[#19151f] dark:text-gray-400">
                        Future Mothership mode will replace this uploader with the selected asset context and offer an approved “Save to Mothership” action.
                    </div>
                </aside>
            )}
        </div>
    );
};

export default UploadZone;
