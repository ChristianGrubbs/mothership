<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub\StackMedia;

/**
 * One app's reader of the media its native files name by path (a FilmCraft project's clips, a SoundCraft session's audio).
 * Open with matches each path to a file of the opened file's own stack by file name (StackMedia::match) and delivers the
 * matches: as the bundle the app opens (SessionBundle), or with the file itself, for the app's hub agent to link after
 * the open (`MEDIA_LINKERS` in tools/creative-hub/hub-agent.js, capability `linked-media`). An app plugs in with a class
 * in this folder, listed in StackMedia::READERS.
 */
interface StackMediaReader
{
    /**
     * The native file types (lowercase extensions) this reader reads.
     *
     * @return list<string>
     */
    public function extensions(): array;

    /** What opens with its media, in the words of the Craft MCP instructions: "a FilmCraft project (its clips)". */
    public function media(): string;

    /**
     * The media paths a file of those types names, each once, read from its text (at most StackMedia::MAX_BYTES of it);
     * none for text it cannot read.
     *
     * @return list<string>
     */
    public function paths(string $contents): array;
}
