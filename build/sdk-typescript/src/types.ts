// Auto-generated API schemas from OpenAPI specification
// Generated from modular schema files

export interface AdoptionGapsResponse {
  gaps: Array<Record<string, any>>;
  computed_at: string;
}

export interface ConsolidatedLearningResponse {
  performance: LearningPerformance;
  adoption_gaps: AdoptionGapsResponse;
  recurring_issues: RecurringIssuesResponse;
  trends: LearningTrendsResponse;
  effectiveness_score: LearningEffectivenessScore;
  computed_at: string;
}

export interface DeleteResponse {
  status: string;
}

export interface DriftAnalysisResult {
  metric: string;
  days_back: number;
  sort_by: string;
  tenants: Array<DriftTenantSummary>;
  fleet_average?: number;
  fleet_stddev?: number;
  tenant_count?: number;
  anomalous_count?: number;
  computed_at: string;
}

export interface DriftTenantSummary {
  tenant_id: string;
  drift_magnitude: number;
  drift_sigma: number;
  is_anomalous: boolean;
}

export interface EffectivenessMetric {
  value: number;
  unit?: string;
  computed_at: string;
}

export interface ErrorResponse {
  error: string;
  message?: string;
  details?: Record<string, any>;
}

export interface Finding {
  severity: string;
  title: string;
  message: string;
}

export interface HealthResponse {
  status: string;
  service: string;
  version: string;
  time: string;
}

export interface IntelligenceEffectivenessSummary {
  recommendations: Array<RecommendationEffectivenessItem>;
  mttd: EffectivenessMetric;
  mttr: EffectivenessMetric;
  acceptance_rate: EffectivenessMetric;
  accuracy: EffectivenessMetric;
  computed_at: string;
}

export interface IntelligenceHealthResponse {
  status: string;
  trend_confidence: number;
  stable_tenants_pct?: number;
  anomaly_density?: number;
  remediation_success_rate?: number;
  average_drift_resolution_hours?: number;
  tenant_count: number;
  anomalous_count: number;
  fleet_average?: number;
  fleet_stddev?: number;
  findings: Array<Finding>;
  recommendations: Array<string>;
  computed_at: string;
}

export interface LearningEffectivenessScore {
  score: number;
  level: string;
  computed_at: string;
}

export interface LearningPerformance {
  total_recommendations: number;
  successful_outcomes: number;
  success_rate: number;
  computed_at: string;
}

export interface LearningTrendsResponse {
  trends: Array<Record<string, any>>;
  computed_at: string;
}

export interface PlatformDashboardResponse {
  platform_health_score: number;
  platform_health_status: string;
  at_risk_tenants: number;
  critical_alerts: number;
  total_active_installs: number;
  total_remediations_7d: number;
  fleet_volatility: number;
  health_distribution: Record<string, any>;
  drift_summary: Record<string, any>;
  tenant_count: number;
  cached_at: string;
}

export interface PlatformDriftSummaryResponse {
  no_drift: number;
  governance_drift: number;
  revocation_drift: number;
  drifted_tenants: Array<Record<string, any>>;
  cached_at: string;
}

export interface PlatformOverviewItem {
  tenant_id: string;
  health_score: number;
  health_status: string;
  install_count: number;
  finding_count: number;
  critical_finding_count: number;
  missing_deps_count?: number;
  revoked_key_count?: number;
  health_trend: string;
  health_delta: number;
  volatility_score: number;
  volatility_status?: string;
  drift_status: string;
  last_updated: string;
}

export interface PlatformOverviewResponse {
  items: Array<Record<string, any>>;
  total_tenants: number;
  average_health: number;
  average_volatility: number;
  updated_at: string;
  cached_at: number;
}

export interface PlatformRankingsResponse {
  healthiest_tenants: Array<PlatformOverviewItem>;
  most_improved_tenants: Array<PlatformOverviewItem>;
  highest_risk_tenants: Array<PlatformOverviewItem>;
  cached_at: string;
}

export interface Plugin {
  id: string;
  slug: string;
  name: string;
  description?: string;
  author?: string;
  version: string;
  manifest_url?: string;
  published: boolean;
  tenant_id?: string;
  created_at: string;
  updated_at?: string;
}

export interface PluginArtifact {
  id: string;
  plugin_id: string;
  version: string;
  file_name: string;
  path?: string;
  signature_verified: boolean;
  signature?: string;
  public_key?: string;
  created_at: string;
  download_base64?: string;
}

export interface PluginArtifactCreateRequest {
  file_name: string;
  artifact_base64: string;
  signature?: string;
  public_key?: string;
}

export interface PluginCreateRequest {
  name: string;
  version: string;
  description?: string;
  author?: string;
  manifest_url?: string;
  tenant_id?: string;
}

export interface PluginInstall {
  id: string;
  plugin_id: string;
  tenant_id: string;
  version?: string;
  status: string;
  installed_at: string;
  uninstalled_at?: string;
  installed_by?: string;
}

export interface PluginInstallListResponse {
  items: Array<PluginInstall>;
  total: number;
}

export interface PluginKey {
  id: string;
  plugin_id: string;
  public_key: string;
  label?: string;
  revoked: boolean;
  created_at: string;
  revoked_at?: string;
}

export interface PluginKeyCreateRequest {
  public_key: string;
  label?: string;
}

export interface PluginKeyListResponse {
  items: Array<PluginKey>;
  total: number;
}

export interface PluginListResponse {
  items: Array<Plugin>;
  total: number;
}

export interface PluginRating {
  id: string;
  plugin_id: string;
  tenant_id?: string;
  rating: number;
  comment?: string;
  created_at: string;
}

export interface PluginRatingCreateRequest {
  tenant_id?: string;
  rating: number;
  comment?: string;
}

export interface PluginUpdateRequest {
  name?: string;
  version?: string;
  description?: string;
  author?: string;
  manifest_url?: string;
  published?: boolean;
  tenant_id?: string;
}

export interface PluginVersion {
  id: string;
  plugin_id: string;
  version: string;
  manifest_url?: string;
  manifest?: Record<string, any>;
  manifest_validated: boolean;
  signature_verified: boolean;
  changelog?: string;
  created_at: string;
}

export interface PluginVersionCreateRequest {
  version: string;
  manifest_url?: string;
  manifest?: Record<string, any>;
  signature?: string;
  public_key?: string;
  changelog?: string;
}

export interface PluginVersionListResponse {
  items: Array<PluginVersion>;
  total: number;
}

export interface Product {
  id: string;
  title: string;
  description?: string;
  price: number;
  category: string;
  brand: string;
  image?: string;
  inventory?: number;
  tenant_id?: string;
  created_at: string;
}

export interface ProductCreateRequest {
  title: string;
  description?: string;
  price: number;
  category?: string;
  brand?: string;
  image?: string;
  inventory?: number;
  tenant_id?: string;
}

export interface ProductListResponse {
  items: Array<Product>;
  total: number;
}

export interface PurchaseResponse {
  status: string;
  product: Product;
  quantity: number;
  message: string;
}

export interface RecommendationEffectivenessItem {
  recommendation_id: string;
  description: string;
  effectiveness_score: number;
  adopted_count?: number;
  total_recommendations?: number;
}

export interface RecurringIssuesResponse {
  issues: Array<Record<string, any>>;
  computed_at: string;
}

export interface RemediationEvent {
  id: string;
  tenant_id: string;
  action: string;
  details?: Record<string, any>;
  created_at: string;
}

export interface RemediationEventRequest {
  tenant_id: string;
  action: string;
  recommendation_type?: string;
  details?: Record<string, any>;
}

export interface RemediationPreviewResponse {
  action: string;
  current_health: number;
  projected_health: number;
  health_impact: number;
  changes?: Array<string>;
  confidence: string;
  safe_to_execute: boolean;
}

export interface RemediationResolveRequest {
  resolved_at: string;
  resolution_comment?: string;
  outcome?: string;
  success?: boolean;
}

export interface RiskZone {
  id: string;
  name: string;
  severity: string;
  description?: string;
  health_range?: string;
  volatility_range?: string;
}

export interface RiskZoneList {
  items: Array<RiskZone>;
  total: number;
}

export interface Snapshot {
  id: string;
  created_at: string;
}

export interface SnapshotListResponse {
  items: Array<Snapshot>;
  total: number;
}

export interface Tenant {
  id: string;
}

export interface TenantListResponse {
  items: Array<Tenant>;
  total: number;
}

export interface TenantStatsResponse {
  tenant_id: string;
  health_score: number;
  health_status: string;
  install_count: number;
  findings: Array<Finding>;
  missing_deps?: Array<string>;
  revoked_key_count: number;
  health_trend: string;
  health_delta: number;
  volatility_score: number;
  volatility_status?: string;
  drift_status: string;
  last_updated?: string;
}

export interface TenantTrendResponse {
  tenant_id: string;
  history_points: number;
  current_snapshot: Record<string, any>;
  trends: Record<string, any>;
  history: Array<Record<string, any>>;
}

export interface TimeSeriesPoint {
  timestamp: string;
  value: number;
}

export interface TimeSeriesResponse {
  tenant_id?: string;
  metric: string;
  period: string;
  items: Array<TimeSeriesPoint>;
  comparison?: Array<Record<string, any>>;
}

