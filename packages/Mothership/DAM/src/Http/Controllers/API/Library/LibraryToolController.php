<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Library;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Support\ValidationMessages;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Mcp\LibraryOperations;
use Mothership\DAM\Mcp\LibraryRefusal;
use Mothership\DAM\Mcp\LibraryTool;
use Mothership\DAM\Mcp\LibraryTransfer;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The `library_*` tools over HTTPS, for the local `mothership` launcher (tools/mcp-launcher, docs/api/mothership-mcp.md).
 * Each call runs LibraryTool::perform and LibraryOperations exactly as the stdio server does, as the key's own admin;
 * api.scope has already checked the scope of the tool's REST twin (Config/api-acl.php).
 *
 * The launcher reads and writes the agent's files itself, so this server never opens a path the caller names. A file
 * to save or vectorize arrives as the multipart field `file`, with the arguments as the JSON field `arguments`, and its
 * bytes stand in for local_path. A file copied out (fetch, or vectorization with format) is answered with its bytes,
 * the result riding in X-Library-Result; dest_dir is checked against the tool's rules and otherwise ignored. Anything
 * else answers {"result": ...}, and a refusal {"error": ...} with the same text the stdio server gives.
 */
class LibraryToolController extends Controller
{
    /** The tools whose local_path the launcher uploads. */
    private const UPLOADS = ['save', 'vectorize'];

    public function __construct(private LibraryOperations $operations) {}

    public function call(Request $request, string $tool): Response
    {
        $admin = $this->admin();
        try {
            $input = $this->input(request: $request);
            $upload = $this->upload(request: $request, tool: $tool, input: $input);
            $outcome = (new LibraryTool(action: $tool))->perform(input: $input, via: 'rest', run: function (array $arguments) use ($tool, $upload, $admin): array|LibraryTransfer {
                if ($tool === 'fetch' || ($tool === 'vectorization' && isset($arguments['format']))) {
                    return $this->operations->transfer(operation: $tool, arguments: $arguments, actor: $admin);
                }
                if ($upload !== null) {
                    $arguments['local_path'] = (string) $upload->getRealPath();
                    if ($tool === 'save') {
                        // The stdio server's default name is the local file's own; the launcher sends that name.
                        $arguments['name'] ??= $upload->getClientOriginalName();
                    }
                }

                return $this->operations->execute(operation: $tool, arguments: $arguments, actor: $admin);
            });
        } catch (ValidationException $e) {
            return $this->refuse(message: ValidationMessages::from($e), status: 422);
        } catch (LibraryRefusal $e) {
            return $this->refuse(message: $e->getMessage(), status: $e->status);
        }

        return $outcome instanceof LibraryTransfer
            ? $this->stream(transfer: $outcome)
            : response()->json(['result' => $outcome])->header('Cache-Control', 'private, no-store');
    }

    /**
     * The tool arguments: the JSON body, or the JSON field `arguments` of a multipart upload.
     *
     * @return array<array-key, mixed>
     */
    private function input(Request $request): array
    {
        $raw = $request->isJson() ? $request->getContent() : $request->request->get('arguments', '{}');
        // Decoded as the stdio server decodes tool arguments: an empty [] is no arguments, a list's keys are unknown.
        $input = is_string($raw) ? json_decode($raw === '' ? '{}' : $raw, true) : null;
        if (! is_array($input)) {
            throw new LibraryRefusal(message: 'Send the tool arguments as one JSON object: the request body, or the field arguments of a multipart upload.', status: 422);
        }

        return $input;
    }

    /**
     * The uploaded file that stands in for local_path, or null when the call names none. local_path without a file,
     * or a file without local_path, is refused: the bytes always come with the call, never from a path.
     *
     * @param  array<array-key, mixed>  $input
     */
    private function upload(Request $request, string $tool, array $input): ?UploadedFile
    {
        $file = $request->file('file');
        if (! in_array($tool, self::UPLOADS, true) || ! array_key_exists('local_path', $input)) {
            if ($file !== null) {
                throw new LibraryRefusal(message: 'Send a file only with local_path, to library_save or library_vectorize.', status: 422);
            }

            return null;
        }
        if (! $file instanceof UploadedFile) {
            throw new LibraryRefusal(message: 'Send the file at local_path with the call, as the multipart field file.', status: 422);
        }
        if (! $file->isValid()) {
            throw in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? new LibraryRefusal(message: 'The file is larger than The Mothership accepts in one upload ('.ini_get('upload_max_filesize').'B), so nothing was saved.', status: 413)
                : new LibraryRefusal(message: 'The file did not arrive whole, so nothing was saved. Try again.', status: 422);
        }

        return $file;
    }

    private function stream(LibraryTransfer $transfer): StreamedResponse
    {
        $stream = $transfer->stream;
        $result = ['name' => $transfer->name, 'sha256' => $transfer->sha256, 'size' => $transfer->size, 'result' => $transfer->result];

        return response()->stream(function () use ($stream): void {
            try {
                // A read that fails part way ends the body short; the launcher's size and sha256 check refuses it.
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type'           => 'application/octet-stream',
            'Content-Length'         => (string) $transfer->size,
            'X-Library-Result'       => base64_encode(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    private function refuse(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status)->header('Cache-Control', 'private, no-store');
    }

    private function admin(): Admin
    {
        /** @var Admin */
        return auth()->guard('api')->user();
    }
}
