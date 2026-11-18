<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

use Mothership\DAM\Support\BatchLog;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs ffmpeg, ffprobe and vips for batch recipes (P6a). Every command is an argument array handed straight to
 * the process, never a shell string, so a value can never become a second command; recipes only pass numbers they
 * formatted themselves, enum values and paths they made. Each run has a timeout and works in the item's own
 * directory under storage (its cwd and TMPDIR), so nothing a tool writes lands in the system temp directory.
 */
class MediaTools
{
    public function binary(string $tool): string
    {
        return (string) config('dam.batch.binaries.'.$tool, $tool);
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws RecipeFailed timeout, or tool_failed with the exit code
     */
    public function mustRun(string $tool, array $arguments, string $workdir): string
    {
        $process = new Process(
            command: [$this->binary($tool), ...$arguments],
            cwd: $workdir,
            env: ['TMPDIR' => $workdir],
            timeout: (float) config('dam.batch.tool_timeout', 1500),
        );
        $started = hrtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            BatchLog::write('batch.tool.timeout', ['tool' => $tool, 'ms' => $this->elapsed($started)], 'warning');

            throw new RecipeFailed(RecipeFailed::TIMEOUT);
        }

        $exitCode = (int) $process->getExitCode();
        BatchLog::write('batch.tool.exit', ['tool' => $tool, 'exit_code' => $exitCode, 'ms' => $this->elapsed($started)], $exitCode === 0 ? 'debug' : 'warning');

        if ($exitCode !== 0) {
            throw new RecipeFailed(RecipeFailed::TOOL_FAILED, exitCode: $exitCode);
        }

        return $process->getOutput();
    }

    /** What the file holds; a file ffprobe cannot read is unsupported input. */
    public function probe(string $path, string $workdir): MediaInfo
    {
        try {
            $json = $this->mustRun('ffprobe', ['-v', 'error', '-show_format', '-show_streams', '-of', 'json', $path], $workdir);
        } catch (RecipeFailed $failed) {
            throw $failed->code() === RecipeFailed::TIMEOUT ? $failed : new RecipeFailed(RecipeFailed::UNSUPPORTED_INPUT, exitCode: $failed->exitCode);
        }

        $decoded = json_decode($json, true);

        return MediaInfo::fromProbe(is_array($decoded) ? $decoded : []);
    }

    /**
     * Video keyframe times from $from to $from + $span seconds (the demuxer seeks to the keyframe at or before
     * $from first), for the clip recipe's stream-copy decision.
     *
     * @return list<float>
     */
    public function keyframes(string $path, float $from, float $span, string $workdir): array
    {
        $interval = number_format(max(0.0, $from), 3, '.', '').'%+'.number_format($span, 3, '.', '');
        $csv = $this->mustRun('ffprobe', [
            '-v', 'error', '-select_streams', 'v:0', '-skip_frame', 'nokey', '-read_intervals', $interval,
            '-show_entries', 'frame=best_effort_timestamp_time', '-of', 'csv=p=0', $path,
        ], $workdir);

        return collect(preg_split('/\R/', trim($csv)) ?: [])
            ->map(fn ($line) => trim((string) $line, " ,\t"))
            ->filter(fn ($line) => is_numeric($line))
            ->map(fn ($line) => (float) $line)
            ->values()->all();
    }

    private function elapsed(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
