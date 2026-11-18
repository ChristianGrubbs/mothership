<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

/**
 * The one list of which file extensions get which kind of preview. Every PHP check reads these constants. The
 * Vue templates spell the model lists out literally, because the browser DOM tests render them without PHP;
 * GameAssetPreviewTest fails when a literal drifts from these constants.
 *
 * None of these is a stored file_type: 3D models, Blender files and Unity files are all stored as
 * `document` (dam_assets.file_type is an enum), so the extension decides the preview.
 */
final class PreviewKinds
{
    /** The formats the browser model viewer renders and captures a thumbnail of (ADR 0006). */
    public const MODEL_VIEWER = ['glb', '3mf', 'stl'];

    /** Every 3D model format the Library stores: the "3D" chip, the Recent "3D model" label, the details icon. */
    public const MODEL_3D = ['glb', 'gltf', 'stl', '3mf', 'obj', 'fbx', 'usdz'];

    /** A Blender scene; its preview is the thumbnail Blender embeds when it saves (no renderer, ADR 0006). */
    public const BLENDER = ['blend'];

    /** A Unity asset package: a gzipped tar whose assets may carry their own preview.png. */
    public const UNITY_PACKAGE = ['unitypackage'];

    /** Unity's text-serialized assets; their preview is the YAML itself. */
    public const UNITY_YAML = ['unity', 'prefab', 'mat', 'asset', 'controller', 'anim', 'overridecontroller', 'physicmaterial', 'mask', 'meta'];

    public const KIND_BLENDER = 'blender';

    public const KIND_UNITY_PACKAGE = 'unity-package';

    public const KIND_UNITY_YAML = 'unity-yaml';

    /** The game-engine kind of an extension, or null when it is none of them. */
    public static function gameAssetKind(?string $extension): ?string
    {
        $extension = strtolower(ltrim(trim((string) $extension), '.'));

        return match (true) {
            in_array($extension, self::BLENDER, true)       => self::KIND_BLENDER,
            in_array($extension, self::UNITY_PACKAGE, true) => self::KIND_UNITY_PACKAGE,
            in_array($extension, self::UNITY_YAML, true)    => self::KIND_UNITY_YAML,
            default                                         => null,
        };
    }

    /** Whether the extension has an embedded thumbnail a job can extract (Blender, Unity package). */
    public static function hasEmbeddedThumbnail(?string $extension): bool
    {
        return in_array(self::gameAssetKind($extension), [self::KIND_BLENDER, self::KIND_UNITY_PACKAGE], true);
    }
}
