# Flutter Scalable Architecture

## Purpose

This document defines the application architecture for the Flutter teacher app so it can scale from the initial teacher scope to 30 to 70+ features over the next 20 to 30 years without breaking stable workflows.

## Architecture Goals

- feature growth without monolith drift
- low coupling between modules
- stable domain contracts
- replaceable infrastructure
- backward-compatible evolution
- durable local storage and migrations
- testability at every layer

## Architectural Style

Recommended style:

- feature-first modular Flutter app
- Clean Architecture boundaries
- app shell + platform core + feature modules
- repository pattern with DTO-to-domain mapping
- use-case driven business actions

## Top-Level Layers

```text
lib/
|- app/
|- bootstrap/
|- core/
|- data/
|- domain/
|- features/
|- design_system/
```

Responsibilities:

- `app/`: app shell, router, top-level dependency graph, session bootstrap
- `bootstrap/`: environment loading, build flavor setup, app start wiring
- `core/`: cross-cutting technical services only
- `data/`: remote DTOs, local persistence, repository implementations
- `domain/`: shared business primitives and cross-feature contracts
- `features/`: bounded feature modules
- `design_system/`: durable visual primitives and tokens

## Feature Module Structure

Each feature should follow the same shape.

```text
features/
|- attendance/
|  |- presentation/
|  |- application/
|  |- domain/
|  |- data/
|  |- routes/
|  |- di/
```

Rules:

- `presentation/` contains screens, widgets, controllers, and UI state
- `application/` contains orchestration services and feature-specific use cases
- `domain/` contains feature models, policies, and repository contracts
- `data/` contains DTOs, mappers, local entities, and repository implementations
- `routes/` contains only feature route definitions
- `di/` contains feature dependency registration

## Dependency Rules

Mandatory direction:

- presentation -> application -> domain
- data -> domain
- app shell can depend on all feature public entry points
- one feature must not import another feature's internal layers

Forbidden patterns:

- presentation calling Dio directly
- widgets reading secure storage directly
- features sharing mutable singletons casually
- dumping business logic into router or theme files

## Shared Core Rules

`core/` must stay intentionally small.

Allowed in `core/`:

- API client setup
- auth/session manager
- logging
- analytics abstraction
- connectivity service
- error model
- date/time helpers
- app configuration

Not allowed in `core/` unless repeatedly proven:

- feature-specific models
- feature-specific widgets
- one-off helpers used by a single module
- raw backend DTOs

## State Management

Recommended:

- Riverpod for dependency injection plus feature state
- or Bloc if the team already standardizes on it

State rules:

- one clear owner per screen or workflow
- loading, success, empty, error, and stale-cache states are explicit
- state should be immutable
- business decisions should not live inside widgets

## Data Layer Design

The data layer should separate:

- remote DTOs
- local entities
- domain models
- mapping logic

Recommended repository flow:

1. feature presentation calls use case
2. use case calls repository contract
3. repository implementation decides remote/local source
4. remote DTO is mapped to domain model
5. presentation receives domain-safe model only

## API Evolution Rules

To stay durable for decades:

- accept additive JSON safely
- ignore unknown fields
- treat nullable fields carefully
- centralize response parsing
- version any intentionally breaking app-side interpretation

## Storage Strategy

Use:

- `flutter_secure_storage` for tokens and small session secrets
- Hive or Drift for cacheable entities

Storage rules:

- every table or box has schema version awareness
- migrations are written, reviewed, and tested
- local storage keys are centrally declared
- stale cache cleanup has explicit retention policy

## Routing Strategy

Recommended:

- `go_router` with centralized typed route definitions

Rules:

- route names stay stable
- feature modules expose route builders, not global navigation side effects
- deep links must degrade safely when a feature is unavailable

## Design System Boundary

All reusable visual tokens and primitives belong in `design_system/`.

This should include:

- colors
- typography
- spacing
- radius
- shadows
- icons
- form primitives
- feedback components
- navigation bars

This keeps redesigns from touching business logic.

## Feature Flags

Feature flags should be supported from early stages.

Use flags for:

- risky rollouts
- incomplete features
- role-based phased releases
- backend-dependent experiments

Do not scatter flag logic randomly; centralize flag evaluation in app or feature application services.

## Observability

The architecture must support:

- crash reporting
- sanitized network failure logging
- feature-level analytics
- slow-screen detection
- release diagnostics

Observability adapters must be replaceable.

## Long-Term Growth Pattern

When adding more features:

1. add a new bounded feature module
2. define repository contracts
3. add DTO and domain mapping
4. wire routes through the app shell
5. add tests before broad integration

Do not expand one module until it behaves like a second monolith.

## Multi-Role Future

If this app later supports teacher, principal, parent, or student roles:

- keep one shared platform layer
- add separate bounded role feature groups
- keep role shells composable
- avoid mixing teacher-only logic into shared platform services

## Testing Architecture

Required layers:

- unit tests for use cases and policies
- repository tests with fake sources
- DTO parsing tests
- storage migration tests
- widget tests for critical screens
- integration tests for login, attendance, and marks flows

## Non-Negotiable Rules

- no direct HTTP from widgets
- no raw JSON in presentation state
- no feature-private logic in `core/`
- no breaking storage changes without migration
- no breaking route renames without transition plan
- no broad refactor without regression coverage
