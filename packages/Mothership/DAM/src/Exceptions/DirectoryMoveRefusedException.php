<?php

namespace Mothership\DAM\Exceptions;

use RuntimeException;

/**
 * A directory move the user cannot retry their way past: the destination is not writable, the
 * move would create a cycle (into the directory's own descendant), or the source and destination
 * are the same directory. Distinct from a transient failure (DB deadlock, S3 blip, storage
 * rename hiccup), which is worth a queue retry.
 *
 * MoveDirectoryStructure catches this type specifically so the action request fails immediately
 * with the refusal's own message instead of being left pending through however many attempts the
 * queue allows before failed() finally records it.
 */
class DirectoryMoveRefusedException extends RuntimeException {}
