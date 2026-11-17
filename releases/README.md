# Release-Manifest

Jede Datei `<version>.json` legt eine gemeinsam getestete Kombination der
Komponenten fest (Source of Truth 17.12, ADR 0006). Produktiv ausgerollt wird nur
eine Kombination mit `"status": "released"`.

- `components` nennt je Komponente den exakten Git-Tag ihres Repos:
  `auth` (`jwt-auth-service`), `api` (`teacheros-api`), `web` (`teacheros-web`,
  inklusive Capacitor-Hülle).
- `null` heißt: Für diese Komponente gibt es noch keinen Tag. Eine Kombination mit
  `null` kann nicht `released` sein.
- `status`: `draft` (in Arbeit, nicht ausrollbar) oder `released`.
