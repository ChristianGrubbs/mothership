## Issue Reference
<!-- Use a closing keyword so the issue closes automatically on merge, e.g. "Fixes #123". Separate multiple issues with commas. -->

## Description
<!-- Please describe your changes in detail. -->

## How To Test This?
<!-- Please describe in detail how to test the changes made in this pull request. -->

## Screenshots
<!-- For UI changes, add before/after screenshots or a short screen recording. Remove this section otherwise. -->

## Checklist
- [ ] The Pest files this change touches pass locally (not the full suite; see CONTRIBUTING.md)
- [ ] `vendor/bin/pint --test` reports no style issues
- [ ] Tailwind classes are reordered
- [ ] New user-facing strings use translation keys in every locale directory (`en_US` is canonical, plus `en_AU`, `en_GB` and `en_NZ`), and `php artisan mothership:translations:check` passes

## Documentation
- [ ] My pull request requires an update to the documentation in `docs/` or `CONTEXT.md`.
<!-- If checked, please describe in detail what needs to be changed. -->
