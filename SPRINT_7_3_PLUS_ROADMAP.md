# Sprint 7.3+ Roadmap: From Certified to Ecosystem-Ready

## Strategic Context

Sprint 7.2 achieved a critical milestone: **external SDK consumer validation** through a certified reference client. The platform has progressed from feature-complete to governable to consumable—and is now ready for ecosystem expansion.

This roadmap sequences the work to maximize developer adoption while building on the certification foundation.

---

## Phase 1: SDK Publication (Weeks 1-3 of Sprint 7.3)

### Objectives
- Publish TypeScript SDK to npm (primary)
- Validate JavaScript SDK generation
- Validate PHP SDK generation
- Create SDK getting started guides

### Deliverables

#### 1.1 TypeScript SDK Publication
**Tasks**:
1. Final code review of generated SDK
2. Package configuration (`package.json`)
   - Version: `0.1.0-beta` (or `1.0.0` if stable)
   - Repository URL
   - License: Match platform license
   - Keywords: `api`, `sdk`, `gdwb`, etc.
3. README with:
   - Installation instructions
   - Basic usage example
   - Link to full documentation
   - Support and reporting issues
4. Publish to npm registry
5. Test installation from npm in clean environment
6. Create GitHub release with changelog

**Success Criteria**:
- [ ] SDK published to npm
- [ ] `npm install @gd-workflow-bridge-pro/api-sdk` works
- [ ] GitHub release created with documentation link
- [ ] At least 1 external team successfully installs and uses it

#### 1.2 JavaScript SDK Generation & Validation
**Tasks**:
1. Regenerate JavaScript SDK from OpenAPI contract
2. Validate with simplified consumer workflow test
3. Document JavaScript-specific differences (Promise API, Node vs Browser)
4. Publish to npm (separate package: `@gd-workflow-bridge-pro/api-sdk-js` or similar)

**Success Criteria**:
- [ ] JavaScript SDK generated and compiles without errors
- [ ] At least 1 end-to-end workflow test passes
- [ ] Published to npm
- [ ] Basic usage example in README

#### 1.3 PHP SDK Generation & Validation
**Tasks**:
1. Regenerate PHP SDK from OpenAPI contract
2. Validate with simplified consumer workflow test
3. Document PHP-specific patterns (PSR standards, dependency injection)
4. Publish to Packagist (composer)

**Success Criteria**:
- [ ] PHP SDK generated and validates with PHP CodeSniffer
- [ ] At least 1 end-to-end workflow test passes
- [ ] Published to Packagist
- [ ] Basic usage example in README

---

## Phase 2: Developer Portal (Weeks 3-6 of Sprint 7.3)

### Objectives
- Create centralized developer documentation
- Lower barrier to entry for external teams
- Standardize SDK usage patterns across languages

### Deliverables

#### 2.1 Authentication Guide
**Content**:
- How JWT authentication works
- Obtaining tokens (login flow)
- Using tokens in API calls
- Token expiration and refresh
- Production vs development secrets (security policy)
- Example code (TypeScript, JavaScript, PHP)

#### 2.2 Getting Started Guide
**Content**:
- Quick start for each language
- Installation instructions
- First API call (e.g., list marketplace products)
- Error handling patterns
- Debugging tips

#### 2.3 SDK Examples
**Content** (one per language):
- Marketplace browsing (list products, get details)
- Plugin installation workflow
- Tenant health monitoring
- Remediation execution
- Intelligence metrics retrieval

#### 2.4 Workflow Tutorials
**Content**:
- End-to-end plugin install/uninstall
- Monitoring tenant health trends
- Executing and monitoring remediation
- Analyzing risk zones
- Using intelligence recommendations

#### 2.5 API Reference
**Content**:
- Auto-generated from OpenAPI spec
- Endpoint descriptions
- Request/response schemas
- Error codes and meanings
- Rate limiting documentation

#### 2.6 FAQ & Troubleshooting
**Content**:
- Common errors and solutions
- Performance tips
- Debugging checklist
- Getting help
- Reporting bugs

### Portal Technology Stack Options
- **Option A**: Static docs (Docusaurus, Sphinx, Jekyll)
  - Pros: Simple, version-controlled, easy to maintain
  - Cons: Less interactive
  
- **Option B**: Interactive portal (ReadTheDocs, Mintlify)
  - Pros: Better UX, searchable, versioned
  - Cons: Requires hosting/tooling

**Recommendation**: Start with Docusaurus (free, open-source, good DX for developers)

---

## Phase 3: Observability (Sprint 7.3+, Weeks 6-10)

### Objectives
- Make platform behavior transparent to consumers
- Support debugging and performance tuning
- Enable SLO tracking and alerting

### Deliverables

#### 3.1 Request ID Propagation
**Implementation**:
1. Gateway generates unique request IDs
2. IDs flow through all internal services
3. IDs returned in response headers
4. SDKs surface request IDs in error objects
5. Logging includes request ID for correlation

**Benefits**:
- Trace requests across service boundaries
- Debug distributed issues
- Support for customer escalations

#### 3.2 Structured Logging
**Implementation**:
1. All services emit JSON-formatted logs
2. Log schema includes: timestamp, service, level, message, request_id, user_id, tenant_id, latency_ms
3. Logs shipped to centralized store (ELK, CloudWatch, etc.)
4. Consumer-facing guidance on log interpretation

**Benefits**:
- Machine-readable logs for analysis
- Easy filtering by tenant/user/request
- Foundation for alerting

#### 3.3 Metrics & Monitoring
**Implementation**:
1. Prometheus metrics emitted by all services
2. Key metrics:
   - Request count by endpoint
   - Latency percentiles (p50, p95, p99)
   - Error rates by type
   - Resource utilization (CPU, memory, disk)
3. Dashboards in Grafana
4. Consumer-accessible SLO dashboard

**Benefits**:
- Real-time visibility into platform health
- Capacity planning data
- Performance trend analysis

#### 3.4 Optional: Distributed Tracing
**Implementation** (if budget allows):
1. OpenTelemetry instrumentation
2. Trace collection (Jaeger or similar)
3. Consumer access to traces for supported requests

**Benefits**:
- Detailed performance profiling
- Bottleneck identification
- Visual service dependency mapping

---

## Phase 4: Ecosystem Expansion (Sprint 7.4+)

### Objectives
- Enable specialized SDK implementations
- Support framework-specific patterns
- Build community contributions

### Examples
- **Spring Boot SDK** (Java wrapper around generated client)
- **Django Middleware** (Python framework integration)
- **Terraform Provider** (infrastructure-as-code integration)
- **Helm Charts** (Kubernetes deployment helpers)
- **GitHub Actions** (CI/CD workflow integration)

---

## Success Metrics

### Phase 1 (SDK Publication)
- ✅ All 3 SDKs published
- ✅ Combined download count > 100 in first month
- ✅ No critical issues reported

### Phase 2 (Developer Portal)
- ✅ Portal launched
- ✅ At least 5 external teams successfully complete getting started guide
- ✅ FAQ reduced support request volume by 20%

### Phase 3 (Observability)
- ✅ All services emit request IDs and structured logs
- ✅ Dashboards show platform health in real-time
- ✅ Consumer SLO dashboard accessible
- ✅ Mean time to debug reduced by 50%

### Phase 4 (Ecosystem Expansion)
- ✅ At least 2 community contributions or 1 official specialized SDK
- ✅ Developer satisfaction score > 8/10

---

## Resource Allocation

### Team Assignments
| Phase | Role | Time | Notes |
|-------|------|------|-------|
| 1 | SDK Engineer | 2 weeks | Pub ops, validation, testing |
| 1 | DevOps | 1 week | npm/Packagist setup, CI/CD |
| 2 | Technical Writer | 3 weeks | Portal creation, examples, tutorials |
| 2 | Developer Advocate | 2 weeks | Review, feedback, community outreach |
| 3 | Platform Engineer | 3 weeks | Instrumentation, dashboard setup |
| 3 | Data Engineer | 1 week | Log pipeline, metric collection |

### Timeline
- **Phase 1**: Weeks 1-3 (parallel with phase 2 prep)
- **Phase 2**: Weeks 3-6 (overlaps phase 1 end)
- **Phase 3**: Weeks 6-10
- **Phase 4**: Sprint 7.4+ (opportunistic)

---

## Dependencies & Assumptions

### Dependencies
- OpenAPI contract remains stable (no major breaking changes)
- Marketing/product alignment on ecosystem strategy
- Resources available for documentation and advocacy

### Assumptions
- External demand exists (validation: reach out to existing customers)
- JavaScript/PHP SDKs generate with same quality as TypeScript
- Developer portal hosting solution is available
- Observability tooling budget is approved

---

## Risks & Mitigation

| Risk | Impact | Mitigation |
|------|--------|-----------|
| SDK generation quality varies by language | High | Thorough testing in Phase 1.2-1.3; fallback to manual tweaks if needed |
| Low adoption despite SDKs | Medium | Phase 2 (portal) + community outreach essential |
| Observability tools complexity | Medium | Phase 3 start with basics (request IDs + logs); tracing optional |
| Resource constraints | High | Prioritize Phase 1+2; defer Phase 4 if needed |

---

## Long-Term Vision (Beyond Sprint 7.4)

Once phases 1-3 are complete:
1. **API Marketplace**: Discover and integrate complementary services
2. **Workflow Templates**: Pre-built remediation and intelligence workflows
3. **Certification Program**: Partner certification for integrations
4. **Commercial Support Tiers**: Different SLA levels for customers
5. **Analytics Dashboard**: Tenant/consumer usage analytics

---

## Decision Points

**Q1**: Should we start Phase 2 (Portal) immediately, or wait for Phase 1 (SDKs) feedback?  
**Recommendation**: Start Phase 2 in parallel (week 3 onward) to maximize developer time.

**Q2**: Which portal technology: Docusaurus or Mintlify?  
**Recommendation**: Docusaurus (lower barrier, maintainable by engineers).

**Q3**: Should Phase 3 (Observability) include tracing from the start?  
**Recommendation**: No. Start with request IDs + structured logs. Add tracing in Phase 3.2 if ROI is clear.

---

**Status**: Ready for Phase 1 kickoff  
**Owner**: Product + Engineering leadership  
**Next Step**: Resource allocation and timeline confirmation
