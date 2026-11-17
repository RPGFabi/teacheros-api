# TeacherOS Architektur

## Überblick

TeacherOS folgt einer modularen, schulbezogenen Architektur mit klarer Trennung von
- App-/UI-Schicht
- API-/Domain-Schicht
- gemeinsamer Typ-/Domain-Schicht
- Mobile-Wrapper

## Grundprinzipien

1. Browser-first, Desktop und Mobile über responsive UI
2. Single-tenant pro Schule
3. Konfigurierbare Module statt Code-Forks
4. Human-in-the-loop bei kritischen Entscheidungen
5. Modulare Erweiterung über registrierte Extension Points

## Schichten

### 1. Frontend
- React + TypeScript + Vite
- responsive, schulische Arbeitsflächen
- zentrale Routen, Rollen und Zuständigkeiten

### 2. API
- PHP-basierte Backend-Services
- Ausgabe über strukturierte REST-/JSON-Endpunkte
- Authentifizierung, Berechtigungen, Entitäten, Imports, Exports

### 3. Shared
- gemeinsame Typen und Domänenspezifika
- Tests und Contract-Definitionen zwischen Frontend und API

### 4. Mobile
- Capacitor-Wrapper, keine separate fachliche App-Logik
- Fokus auf Push, Offline-Workflows und native UX-Features

## Erstes MVP-Set

- Auth / Rollen / Schuljahre
- Klassen / Fächer / Kernstammdaten
- Beobachtung / ICE / Bewertungsraster
- CSV-Import / Basis-CRUD
- Grundlegende Dashboard-/Listenansichten
