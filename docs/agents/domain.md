# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase. Single-context: one glossary for all of TeacherOS (API, Web, Auth integration).

## Before exploring, read these

- **`GLOSSARY.md`** at the root of `teacheros-api`.
- **`docs/decisions/`**: read ADRs that touch the area you're about to work in. TeacherOS keeps ADRs here (Source of Truth 4.2), **not** in `docs/adr/`.
- **`docs/TeacherOS_Source_of_Truth_2026-11-14_rev1.md`**: the binding product specification. Read the sections for the area you work in; **section 17 overrides sections 1–16**, and section 18 lists deliberately open points that must not be turned into silent product decisions.

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The `/domain-modeling` skill (reached via `/grill-with-docs` and `/improve-codebase-architecture`) creates them lazily when terms or decisions actually get resolved.

## File structure

```
teacheros-api/
├── GLOSSARY.md
├── docs/
│   ├── TeacherOS_Source_of_Truth_2026-11-14_rev1.md
│   └── decisions/
│       ├── 0001-auth-und-permissions-gap-analyse.md
│       └── ...
└── src/
```

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `GLOSSARY.md`. Don't drift to synonyms the glossary explicitly avoids (e.g. write **Konto**, not Account/User).

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag ADR and Source-of-Truth conflicts

If your output contradicts an existing ADR or the Source of Truth, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0003 (gemeinsamer Auth-Dienst), but worth reopening because…_
