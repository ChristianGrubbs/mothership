# Blender 5.2 file fixtures

Saved by Blender 5.2.2 (header `BLENDER17-01v0502`, 5.x large block headers), zstd-compressed the way Blender 5 writes it: several zstd frames plus a skippable seek-table frame. They hold the same small scene (1 scene, 3 objects, 1 mesh, 1 camera, 1 light).

- v0502_thumb_zstd.blend: 95,918 bytes; SHA-256 77ea01fc5677285c269a5ffef871df19c06dbddfb6706650878498385f362feb. REND, then TEST (length 65,544: a 128 x 128 RGBA thumbnail), then GLOB.
- v0502_nothumb_zstd.blend: 94,346 bytes; SHA-256 d907e0333454f2c5a353577222c72a3e41a298dab4e21cf8bfeeb65b3fa693f5. No TEST block.

Read by BlendFileReaderTest; preserve them byte for byte. The uncompressed equivalents (about 0.5 MB each) are not committed.
