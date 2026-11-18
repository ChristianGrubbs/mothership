# ADR 0001: The Mothership is an owned codebase

Date: 2026-09-01 · Status: accepted

## Context
The previous DAM deployment grew a 256-file, ~81,500-line delta spread across plugins,
switches, hooks, and CSS assumptions. Continuing to build the product as extensions was
rejected. Owning the whole codebase was evaluated against license freedom, permanently inherited surface,
data-model fit, replaceability of UI/storage/search/workers, and legacy migration fit. Community
size and upstream upgrade paths were explicitly excluded as criteria.

## Decision
- Base: an MIT-licensed Laravel 13 / PHP 8.4 / Vue 3 application with a DAM module, kept as owned source
  (the DAM lives at `packages/Mothership/DAM`) rather than as composer dependencies.
- Repo: standalone `ChristianGrubbs/mothership`. It tracks no outside releases.
- Phrasea is a domain-model donor (concepts only until its service licensing is clarified); AtroCore a
  configurable-entity donor; the previous DAM was the migration source and regression oracle.

## Consequences
- Every line is ours to change; there is no upgrade path to protect and no upstream compatibility tax.
- PIM packages (Product, Category, Attribute, Completeness, DataTransfer, Publication, ProductPassport,
  Measurement, AiAgent, MagicAI) are inherited surface to hide first and remove later.
- The first architectural change is the typed compound-asset graph (ADR 0002).
