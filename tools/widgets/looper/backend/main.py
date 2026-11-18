from fastapi import FastAPI, UploadFile, File, HTTPException, Query
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import os
import json
import subprocess
from pathlib import Path
import uuid
from typing import Optional

app = FastAPI()

# CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Directories
UPLOAD_DIR = Path("uploads")
UPLOAD_DIR.mkdir(exist_ok=True)
ALLOWED_VIDEO_EXTENSIONS = {".mp4", ".mov", ".m4v", ".webm", ".avi", ".mkv"}
MAX_UPLOAD_BYTES = 512 * 1024 * 1024
MAX_MATCH_SAMPLES = 120

app.mount("/static", StaticFiles(directory="uploads"), name="static")


def safe_filename(filename: str) -> str:
    clean_name = Path(filename).name
    if not filename or clean_name != filename:
        raise HTTPException(status_code=400, detail="Invalid filename")
    return clean_name


def get_upload_path(filename: str) -> Path:
    clean_name = safe_filename(filename)
    file_path = (UPLOAD_DIR / clean_name).resolve()
    upload_root = UPLOAD_DIR.resolve()
    if upload_root not in file_path.parents and file_path != upload_root:
        raise HTTPException(status_code=400, detail="Invalid filename")
    if not file_path.exists() or not file_path.is_file():
        raise HTTPException(status_code=404, detail="File not found")
    return file_path


def get_video_metadata(file_path: Path) -> tuple[float, float]:
    """Read frame rate and duration in one ffprobe process."""
    cmd = [
        "ffprobe",
        "-v", "error",
        "-select_streams", "v:0",
        "-show_entries", "stream=r_frame_rate:format=duration",
        "-of", "json",
        str(file_path),
    ]
    result = subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    payload = json.loads(result.stdout.decode() or "{}")
    fps_str = str((payload.get("streams") or [{}])[0].get("r_frame_rate") or "0")
    if "/" in fps_str:
        num, den = map(int, fps_str.split("/"))
        fps = num / den if den else 0.0
    else:
        fps = float(fps_str)
    duration = float((payload.get("format") or {}).get("duration") or 0.0)
    return fps, duration


def ensure_video_frame(file_path: Path, filename: str, time: float) -> str:
    safe_time = max(0.0, float(time))
    frame_filename = f"{Path(filename).stem}_{safe_time:.3f}.jpg"
    frame_path = UPLOAD_DIR / frame_filename

    if not frame_path.exists():
        cmd = [
            "ffmpeg",
            "-ss", f"{safe_time:.3f}",
            "-i", str(file_path),
            "-frames:v", "1",
            "-vf", "scale=320:-2:force_original_aspect_ratio=decrease",
            "-q:v", "4",
            str(frame_path),
            "-y",
        ]
        subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    return frame_filename

@app.post("/upload")
def upload_video(file: UploadFile = File(...)):
    try:
        # Generate unique filename
        file_id = str(uuid.uuid4())
        extension = os.path.splitext(file.filename or "")[1].lower()
        if extension not in ALLOWED_VIDEO_EXTENSIONS:
            raise HTTPException(status_code=400, detail="Unsupported video type. Use MP4, MOV, WebM, AVI, or MKV.")
        video_filename = f"{file_id}{extension}"
        video_path = UPLOAD_DIR / video_filename

        # Save in bounded chunks so a malformed upload cannot fill the sandbox disk.
        bytes_written = 0
        with video_path.open("wb") as buffer:
            while chunk := file.file.read(1024 * 1024):
                bytes_written += len(chunk)
                if bytes_written > MAX_UPLOAD_BYTES:
                    buffer.close()
                    video_path.unlink(missing_ok=True)
                    raise HTTPException(status_code=413, detail="Video exceeds the 512 MB sandbox limit")
                buffer.write(chunk)

        # Extract reference frame (00:00:01)
        frame_filename = f"{file_id}_ref.jpg"
        frame_path = UPLOAD_DIR / frame_filename

        # FFmpeg command to extract frame at 1s
        cmd = [
            "ffmpeg",
            "-ss", "00:00:00",
            "-i", str(video_path),
            "-frames:v", "1",
            "-vf", "scale=640:-2:force_original_aspect_ratio=decrease",
            "-q:v", "3",
            str(frame_path),
            "-y"
        ]

        subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        fps, duration = get_video_metadata(video_path)

        return {
            "video_url": f"/static/{video_filename}",
            "reference_frame_url": f"/static/{frame_filename}",
            "filename": video_filename,
            "fps": fps,
            "duration": duration,
        }

    except HTTPException:
        raise
    except subprocess.CalledProcessError as e:
        if 'video_path' in locals():
            video_path.unlink(missing_ok=True)
        raise HTTPException(status_code=422, detail=f"Video could not be decoded: {e.stderr.decode(errors='ignore')[-500:]}")
    except Exception as e:
        if 'video_path' in locals():
            video_path.unlink(missing_ok=True)
        raise HTTPException(status_code=500, detail=str(e))

class TrimRequest(BaseModel):
    filename: str
    cut_time: float
    start_time: float = 0.0

@app.post("/trim")
def trim_video(request: TrimRequest):
    try:
        input_path = get_upload_path(request.filename)

        # Generate output filename
        file_id = Path(request.filename).stem
        extension = Path(request.filename).suffix
        output_filename = f"{file_id}_trimmed{extension}"
        output_path = UPLOAD_DIR / output_filename

        start_time = max(0.0, float(request.start_time or 0.0))
        end_time = max(start_time, float(request.cut_time))
        trim_duration = end_time - start_time
        if trim_duration <= 0:
            raise HTTPException(status_code=400, detail="End time must be after start time")

        # FFmpeg command to trim. Re-encode for frame accuracy and broadly playable exports.
        cmd = [
            "ffmpeg",
            "-ss", f"{start_time:.6f}",
            "-i", str(input_path),
            "-t", f"{trim_duration:.6f}",
            "-c:v", "libx264",
            "-preset", "fast",
            "-crf", "20",
            "-pix_fmt", "yuv420p",
            "-movflags", "+faststart",
            "-c:a", "aac",
            str(output_path),
            "-y"
        ]

        subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

        return {
            "trimmed_video_url": f"/static/{output_filename}",
            "filename": output_filename
        }

    except subprocess.CalledProcessError as e:
        raise HTTPException(status_code=500, detail=f"FFmpeg error: {e.stderr.decode()}")
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/metadata/{filename}")
def get_metadata(filename: str):
    try:
        file_path = get_upload_path(filename)
        fps, duration = get_video_metadata(file_path)
        return {"fps": fps, "duration": duration}

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/frame/{filename}")
def get_frame(filename: str, time: float):
    try:
        file_path = get_upload_path(filename)
        frame_filename = ensure_video_frame(file_path, filename, time)
        return {"frame_url": f"/static/{frame_filename}"}

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/timeline-samples/{filename}")
def get_timeline_samples(filename: str, count: int = Query(default=12, ge=1, le=40)):
    try:
        file_path = get_upload_path(filename)
        fps, duration = get_video_metadata(file_path)
        duration = max(0.0, duration)
        frame_epsilon = (1.0 / fps) if fps > 0 else 0.001
        max_frame_time = max(duration - frame_epsilon, 0.0)

        if duration <= 0 or count == 1:
            sample_times = [0.0]
        else:
            sample_times = [
                min(max_frame_time, ((index + 0.5) / count) * duration)
                for index in range(count)
            ]

        samples = []
        for sample_time in sample_times:
            frame_filename = ensure_video_frame(file_path, filename, sample_time)
            samples.append({
                "time": sample_time,
                "frame_url": f"/static/{frame_filename}",
            })

        return {
            "duration": duration,
            "fps": fps,
            "samples": samples,
        }

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

def prepare_analysis_frame(frame):
    import cv2

    if frame is None:
        raise ValueError("Could not decode video frame")
    return cv2.resize(frame, (320, 180), interpolation=cv2.INTER_AREA)


def read_frame(cap, time_seconds: float, fps: float, duration: float):
    import cv2

    safe_time = min(max(float(time_seconds), 0.0), max(duration - (1.0 / fps), 0.0))
    cap.set(cv2.CAP_PROP_POS_MSEC, safe_time * 1000.0)
    ok, frame = cap.read()
    if not ok:
        frame_index = max(0, round(safe_time * fps))
        cap.set(cv2.CAP_PROP_POS_FRAMES, frame_index)
        ok, frame = cap.read()
    if not ok:
        raise ValueError(f"Could not decode frame at {safe_time:.3f}s")
    return prepare_analysis_frame(frame)


def read_frames_by_index(cap, frame_indexes, fps: float, duration: float) -> dict:
    """Decode a group efficiently: sequentially when dense, seek when sparse."""
    import cv2

    indexes = sorted({max(0, int(index)) for index in frame_indexes})
    if not indexes:
        return {}

    frames = {}
    span = indexes[-1] - indexes[0] + 1
    dense = span <= max(600, len(indexes) * 6)
    if not dense:
        for frame_index in indexes:
            frames[frame_index] = read_frame(cap, frame_index / fps, fps, duration)
        return frames

    wanted = set(indexes)
    cap.set(cv2.CAP_PROP_POS_FRAMES, indexes[0])
    for frame_index in range(indexes[0], indexes[-1] + 1):
        ok, frame = cap.read()
        if not ok:
            break
        if frame_index in wanted:
            frames[frame_index] = prepare_analysis_frame(frame)
    if len(frames) != len(wanted):
        missing = wanted.difference(frames)
        for frame_index in missing:
            frames[frame_index] = read_frame(cap, frame_index / fps, fps, duration)
    return frames


def appearance_similarity(frame_a, frame_b) -> dict:
    """Compare structure, silhouettes, and color continuity between two frames."""
    import cv2
    import numpy as np
    from skimage.metrics import structural_similarity as ssim

    gray_a = cv2.cvtColor(frame_a, cv2.COLOR_BGR2GRAY)
    gray_b = cv2.cvtColor(frame_b, cv2.COLOR_BGR2GRAY)
    structural, _ = ssim(gray_a, gray_b, full=True)

    edges_a = cv2.Canny(gray_a, 80, 160)
    edges_b = cv2.Canny(gray_b, 80, 160)
    edge, _ = ssim(edges_a, edges_b, full=True)

    hsv_a = cv2.cvtColor(frame_a, cv2.COLOR_BGR2HSV)
    hsv_b = cv2.cvtColor(frame_b, cv2.COLOR_BGR2HSV)
    hist_a = cv2.calcHist([hsv_a], [0, 1], None, [32, 32], [0, 180, 0, 256])
    hist_b = cv2.calcHist([hsv_b], [0, 1], None, [32, 32], [0, 180, 0, 256])
    cv2.normalize(hist_a, hist_a)
    cv2.normalize(hist_b, hist_b)
    color = max(0.0, cv2.compareHist(hist_a, hist_b, cv2.HISTCMP_CORREL))

    score = (0.62 * structural) + (0.24 * edge) + (0.14 * color)
    if not np.isfinite(score):
        score = 0.0
    return {
        "score": float(np.clip(score * 100, 0, 100)),
        "structure": float(np.clip(structural * 100, 0, 100)),
        "edges": float(np.clip(edge * 100, 0, 100)),
        "color": float(np.clip(color * 100, 0, 100)),
    }


def calculate_flow(previous_frame, next_frame):
    import cv2

    previous_gray = cv2.cvtColor(previous_frame, cv2.COLOR_BGR2GRAY)
    next_gray = cv2.cvtColor(next_frame, cv2.COLOR_BGR2GRAY)
    previous_gray = cv2.resize(previous_gray, (160, 90), interpolation=cv2.INTER_AREA)
    next_gray = cv2.resize(next_gray, (160, 90), interpolation=cv2.INTER_AREA)
    return cv2.calcOpticalFlowFarneback(previous_gray, next_gray, None, 0.5, 3, 15, 3, 5, 1.2, 0)


def motion_similarity(reference_flow, candidate_flow) -> float:
    """Compare incoming seam motion with motion leaving the selected start frame."""
    import numpy as np

    ref_magnitude = np.linalg.norm(reference_flow, axis=2)
    candidate_magnitude = np.linalg.norm(candidate_flow, axis=2)
    motion_scale = float((ref_magnitude.mean() + candidate_magnitude.mean()) / 2.0)
    if motion_scale < 0.08:
        return 100.0

    active = (ref_magnitude > 0.08) | (candidate_magnitude > 0.08)
    ref_vectors = reference_flow[active]
    candidate_vectors = candidate_flow[active]
    if len(ref_vectors) == 0:
        return 100.0

    dots = np.sum(ref_vectors * candidate_vectors, axis=1)
    norms = np.linalg.norm(ref_vectors, axis=1) * np.linalg.norm(candidate_vectors, axis=1)
    cosine = np.divide(dots, norms, out=np.zeros_like(dots), where=norms > 1e-6)
    direction = float(np.clip((np.mean(cosine) + 1.0) / 2.0, 0.0, 1.0))

    magnitude_error = float(np.mean(np.abs(ref_magnitude - candidate_magnitude)))
    magnitude = float(np.exp(-magnitude_error / (motion_scale + 0.15)))
    field_error = float(np.mean(np.linalg.norm(reference_flow - candidate_flow, axis=2)))
    field = float(np.exp(-field_error / (motion_scale + 0.15)))

    score = (0.45 * field) + (0.35 * direction) + (0.20 * magnitude)
    return float(np.clip(score * 100, 0, 100))


def quality_label(score: float) -> str:
    if score >= 95:
        return "Excellent"
    if score >= 90:
        return "Strong"
    if score >= 82:
        return "Usable"
    return "Needs review"


def analyze_seam_frames(ref_frame, ref_flow, target_frame, target_previous, target_time: float, fps: float) -> dict:
    appearance = appearance_similarity(ref_frame, target_frame)
    incoming_flow = calculate_flow(target_previous, target_frame)
    motion = motion_similarity(ref_flow, incoming_flow)
    combined = (0.78 * appearance["score"]) + (0.22 * motion)
    return {
        "time": float(target_time),
        "frame_number": int(round(target_time * fps)),
        "score": float(combined),
        "appearance_score": appearance["score"],
        "motion_score": motion,
        "quality": quality_label(combined),
    }


def analyze_seam(cap, ref_frame, ref_flow, target_time: float, fps: float, duration: float) -> dict:
    frame_duration = 1.0 / fps
    target_frame = read_frame(cap, target_time, fps, duration)
    target_previous = read_frame(cap, target_time - frame_duration, fps, duration)
    return analyze_seam_frames(ref_frame, ref_flow, target_frame, target_previous, target_time, fps)


@app.get("/match-score/{filename}")
def get_match_score(filename: str, ref_time: float, target_time: float):
    import cv2

    cap = None
    try:
        file_path = get_upload_path(filename)
        fps, duration = get_video_metadata(file_path)
        fps = fps or 30.0
        frame_duration = 1.0 / fps
        cap = cv2.VideoCapture(str(file_path))
        ref_frame = read_frame(cap, ref_time, fps, duration)
        ref_next = read_frame(cap, ref_time + frame_duration, fps, duration)
        ref_flow = calculate_flow(ref_frame, ref_next)
        return analyze_seam(cap, ref_frame, ref_flow, target_time, fps, duration)
    except Exception as e:
        print(f"Error calculating score: {e}")
        raise HTTPException(status_code=422, detail=str(e))
    finally:
        if cap is not None:
            cap.release()


@app.get("/match-candidates/{filename}")
def get_match_candidates(
    filename: str,
    ref_time: float,
    min_time: float = 0.0,
    max_time: Optional[float] = None,
    count: int = Query(default=8, ge=1, le=20),
):
    import cv2
    import numpy as np

    cap = None
    try:
        file_path = get_upload_path(filename)
        fps, duration = get_video_metadata(file_path)
        fps = fps or 30.0
        frame_duration = 1.0 / fps
        search_start = max(ref_time + (frame_duration * 3), float(min_time), frame_duration)
        search_end = min(duration - frame_duration, float(max_time) if max_time is not None else duration - frame_duration)
        if search_end <= search_start:
            raise HTTPException(status_code=400, detail="Search window is empty")

        cap = cv2.VideoCapture(str(file_path))
        if not cap.isOpened():
            raise HTTPException(status_code=422, detail="Video could not be opened for analysis")

        ref_frame = read_frame(cap, ref_time, fps, duration)
        ref_next = read_frame(cap, ref_time + frame_duration, fps, duration)
        ref_flow = calculate_flow(ref_frame, ref_next)

        start_index = int(np.ceil(search_start * fps))
        end_index = int(np.floor(search_end * fps))
        frame_indexes = np.arange(start_index, end_index + 1, dtype=int)
        if len(frame_indexes) > MAX_MATCH_SAMPLES:
            frame_indexes = np.unique(np.linspace(start_index, end_index, MAX_MATCH_SAMPLES, dtype=int))

        # Stage 1: inexpensive appearance scan across the requested window.
        coarse_frames = read_frames_by_index(cap, frame_indexes, fps, duration)
        coarse = []
        for frame_index in frame_indexes:
            candidate_time = frame_index / fps
            appearance = appearance_similarity(ref_frame, coarse_frames[int(frame_index)])["score"]
            coarse.append((candidate_time, appearance))
        coarse.sort(key=lambda item: item[1], reverse=True)

        # Stage 2: frame-accurate refinement plus optical-flow continuity.
        refine_indexes = set()
        for candidate_time, _ in coarse[: max(count * 4, 16)]:
            center_index = round(candidate_time * fps)
            for offset in range(-6, 7):
                frame_index = center_index + offset
                if start_index <= frame_index <= end_index:
                    refine_indexes.add(frame_index)

        required_indexes = refine_indexes | {max(0, frame_index - 1) for frame_index in refine_indexes}
        refined_frames = read_frames_by_index(cap, required_indexes, fps, duration)
        refined = [
            analyze_seam_frames(
                ref_frame,
                ref_flow,
                refined_frames[frame_index],
                refined_frames[max(0, frame_index - 1)],
                frame_index / fps,
                fps,
            )
            for frame_index in sorted(refine_indexes)
        ]
        refined.sort(key=lambda item: item["score"], reverse=True)

        candidates = []
        minimum_separation = max(frame_duration * 2.0, 0.06)
        for item in refined:
            if all(abs(item["time"] - existing["time"]) >= minimum_separation for existing in candidates):
                frame_filename = ensure_video_frame(file_path, filename, item["time"])
                item["frame_url"] = f"/static/{frame_filename}"
                item["loop_duration"] = item["time"] - ref_time
                candidates.append(item)
            if len(candidates) >= count:
                break

        return {
            "fps": fps,
            "duration": duration,
            "reference_time": ref_time,
            "searched_frames": len(frame_indexes),
            "method": "appearance-motion-v1",
            "candidates": candidates,
        }

    except HTTPException:
        raise
    except Exception as e:
        print(f"Error finding match candidates: {e}")
        raise HTTPException(status_code=500, detail=str(e))
    finally:
        if cap is not None:
            cap.release()


@app.get("/download/{filename}")
async def download_video(filename: str):
    file_path = get_upload_path(filename)
    return FileResponse(
        path=file_path,
        filename=safe_filename(filename),
        media_type='application/octet-stream'
    )


@app.get("/health")
def health_check():
    return {"status": "ok", "service": "mothership-video-loop-refiner"}


@app.get("/")
def read_root():
    return {"message": "Mothership Video Loop Refiner Backend Ready"}
