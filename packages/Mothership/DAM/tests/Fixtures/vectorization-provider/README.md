# Captured Vectorizer.AI response fixtures

Captured 2026-09-08 from successful HTTP 200 responses using the application's production SVG1.1 Adobe-compatible request and its PDF export from the same image reference. These files contain response bodies only; no credentials, request headers, or image references are stored.

The SVG reproduces the standard external SVG1.1 PUBLIC declaration rejected by the original validator. The PDF reproduces the blank line and comment between its classic xref rows and trailer. Preserve both byte-for-byte so the regression remains representative of the provider output.

- raw.svg: 25,790 bytes; SHA-256 6c465678b20424f383e9e3847264ebd012e0eff364f6f473d1e7ea87844b759f.
- raw.pdf: 6,102 bytes; SHA-256 47bce7de5a2899c1c06ca354ebc043377606f36ddc77379d6cb6f5a39a88b32f.

Poppler validated the PDF as one page, 197 by 256 points, PDF1.4. The sanitized SVG has the matching viewBox and 43 paths. All regression tests fake HTTP; they never contact the provider.
