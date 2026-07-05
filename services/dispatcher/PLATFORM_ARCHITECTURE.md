# Dispatcher Platform Architecture

## Purpose

The dispatcher is the execution substrate for workflow and assistant-driven automation. It is responsible for receiving work, resolving runtime capabilities, executing actions safely, and reporting operational state back to operators and higher-level services.

## Core responsibilities

The dispatcher owns the following responsibilities:

- Accepting execution requests and creating an execution context
- Routing work to registered actions, workers, and middleware
- Managing retries, dead-letter handling, and time-based scheduling
- Coordinating queueing, locking, and metrics collection
- Emitting lifecycle events for runtime observability
- Enforcing plugin permissions and runtime safety rules
- Exposing plugin capabilities for tooling, diagnostics, and administration

## Runtime layers

### 1. Core execution runtime
The core runtime is the stable execution engine. It is responsible for:

- action resolution and dispatch
- context creation and propagation
- middleware pipeline execution
- event emission and subscription
- scheduler and worker coordination

### 2. Reliability and operations layer
This layer adds production behavior without changing the semantics of execution:

- retry policies
- dead-letter queueing
- queue abstraction
- lock coordination
- metrics and telemetry
- execution state tracking

### 3. Extension and plugin layer
This layer enables the runtime to be extended without modifying core logic:

- manifest-based plugin discovery
- dependency resolution and version compatibility
- lifecycle operations
- permission enforcement
- capability registration
- health diagnostics
- migration and integrity hooks

## Public extension points

The dispatcher should be treated as a platform with stable extension seams:

- Action registration through the action registry and runtime registrar
- Middleware insertion through the middleware pipeline
- Worker registration for background execution
- Event listener registration for lifecycle and runtime events
- Plugin packages discovered via manifest files
- Capability registration for runtime introspection
- Health and diagnostics hooks for operational tooling

These extension points are the contract for all future plugins and higher-level services.

## Stable SDK contract for plugins

Plugin authors should interact with the dispatcher through the following stable surfaces:

- Plugin manifest: declares metadata, dependencies, permissions, and entry points
- Plugin loader: discovers and initializes plugin packages
- Plugin manager: coordinates lifecycle and registration
- Runtime registrar: connects plugin capabilities into the dispatcher
- Permission enforcer: validates plugin access before execution
- Capability registry: exposes what a plugin contributes
- Health service: reports state and diagnostics for operations

The expectation is that plugin authors can extend the platform without needing to understand internal runtime implementation details.

## Plugin lifecycle

Plugins should move through the following lifecycle states:

1. Discover: locate package and read manifest
2. Validate: verify structure, metadata, and version constraints
3. Resolve: determine dependency order and compatibility
4. Install: prepare package assets and metadata
5. Enable: register capabilities and activate runtime hooks
6. Operate: execute under permission and health monitoring
7. Disable or uninstall: remove runtime registration and clean up state

The lifecycle must remain explicit and observable so that operators can reason about failures and upgrades.

## Permission model

Permissions should be declared in the plugin manifest and enforced at runtime.

Rules:

- plugins declare the permissions they require
- execution paths validate those permissions before invoking protected actions
- permission checks happen in the dispatcher layer rather than inside individual actions
- denied access produces a deterministic failure path and diagnostic output

## Capability model

Capabilities are the public contract between a plugin and the runtime.

Supported capability categories include:

- actions
- middleware
- workers
- event listeners

Every capability should be registered with a plugin identity and made discoverable through the capability registry. This allows dashboards, admin tools, and future marketplaces to introspect what the system can do without reading code directly.

## Versioning policy

The dispatcher should use semantic versioning for plugin compatibility.

Guidelines:

- plugins use semantic version numbers in the manifest
- dependency compatibility is validated against declared version ranges
- breaking runtime changes require a major version bump
- non-breaking additions should preserve backward compatibility for existing plugins
- version compatibility should be enforced before activation

## Relationship to assistant services

Assistant services should consume the dispatcher rather than re-implementing parallel infrastructure. The dispatcher provides the execution backbone for:

- assistant orchestration
- tool invocation
- memory-aware workflows
- planning and review pipelines
- plugin-driven capabilities

This keeps assistant services aligned with the same lifecycle, permission, and capability model as the rest of the platform.

## Architectural recommendation

Future work should preserve the current separation of concerns:

- keep the core runtime stable
- evolve plugins through the documented extension points
- treat operational features as first-class platform concerns
- let higher-level services build on dispatcher capabilities instead of introducing parallel mechanisms
