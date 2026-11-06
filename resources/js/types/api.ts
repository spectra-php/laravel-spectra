/*
 * The JSON the dashboard API returns.
 *
 * The PHP DTOs in src/Data and src/Data/Responses are the source of truth, and
 * their snake_case properties are the keys here. Where a DTO only says
 * array<string, mixed>, the action that builds the array (src/Actions) has the
 * keys. Costs are cents and may have sub-cent precision. Dates are ISO strings,
 * except the plain Y-m-d days on the per-day rows.
 */

// Any JSON: provider payloads, stored inputs and outputs, metadata.
export type JsonValue = string | number | boolean | null | JsonValue[] | JsonObject;
export type JsonObject = { [key: string]: JsonValue };

export interface PaginatedResponse<Row> {
    data: Row[];
    current_page: number;
    last_page: number;
    total: number;
}

// The filter options the list screens offer, from the values actually stored.
export interface AvailableFilterOptions {
    available_tags: string[];
    available_finish_reasons: string[];
    available_reasoning_efforts: string[];
    available_trackable_types: string[];
}

export interface AvailableFiltersResponse {
    tags: string[];
    finish_reasons: string[];
    reasoning_efforts: string[];
    trackable_types: string[];
}

/* Config and providers */

export interface ModelTypeOption {
    value: string;
    label: string;
}

export interface DashboardConfigResponse {
    layout: string;
    model_types: ModelTypeOption[];
}

export interface Provider {
    internal_name: string;
    display_name: string;
}

export interface ProvidersResponse {
    providers: Provider[];
}

export interface Tag {
    id: string;
    name: string;
    slug: string;
    count: number;
}

export interface TagsResponse {
    tags: Tag[];
}

/* Requests */

// SpectraRequest as Eloquent serialises it: columns plus the appended
// attributes. `request` and `response` are hidden unless made visible.
export interface SpectraRequestAttributes {
    id: string;
    batch_id: string | null;
    trace_id: string | null;
    parent_span_id: string | null;
    span_id: string | null;
    response_id: string | null;
    provider: string;
    model: string;
    snapshot: string | null;
    model_type: string | null;
    endpoint: string | null;
    trackable_type: string | null;
    trackable_id: string | null;
    conversation_id: string | null;
    conversation_turn: number | null;
    prompt_name: string | null;
    prompt_version: string | null;
    error_fingerprint: string | null;
    prompt_tokens: number;
    completion_tokens: number;
    cached_tokens: number;
    cache_write_tokens: number;
    reasoning_tokens: number;
    page_count: number | null;
    currency: string | null;
    error_type: string | null;
    error_message: string | null;
    request_source: string | null;
    origin: string | null;
    laravel_request_id: string | null;
    ip_address: string | null;
    user_agent: string | null;
    duration_seconds: number | null;
    input_characters: number | null;
    image_count: number | null;
    video_count: number | null;
    prompt_cost: number;
    completion_cost: number;
    total_cost_in_cents: number;
    pricing_tier: string | null;
    latency_ms: number | null;
    time_to_first_token_ms: number | null;
    // decimal:2 cast, so a string in the JSON.
    tokens_per_second: string | null;
    is_reasoning: boolean;
    reasoning_effort: string | null;
    is_streaming: boolean;
    finish_reason: string | null;
    has_tool_calls: boolean;
    tool_call_counts: Record<string, number> | null;
    status_code: number | null;
    media_storage_path: JsonObject | null;
    metadata: JsonObject | null;
    created_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    expires_at: string | null;
    // Appended attributes.
    provider_display_name: string | null;
    total_tokens: number;
    formatted_created_at: string | null;
    created_at_human: string | null;
}

type RequestAppends = 'provider_display_name' | 'total_tokens' | 'formatted_created_at' | 'created_at_human';

// A row of the requests list (GetFilteredRequests, FormatRequestsList swaps the
// tag models for their names).
export type RequestListItem = Pick<SpectraRequestAttributes,
    | RequestAppends
    | 'id' | 'batch_id' | 'trace_id' | 'response_id'
    | 'provider' | 'model' | 'snapshot' | 'model_type' | 'endpoint'
    | 'trackable_type' | 'trackable_id'
    | 'prompt_tokens' | 'completion_tokens' | 'reasoning_tokens'
    | 'duration_seconds' | 'input_characters' | 'image_count' | 'video_count'
    | 'prompt_cost' | 'completion_cost' | 'total_cost_in_cents' | 'pricing_tier'
    | 'latency_ms' | 'time_to_first_token_ms' | 'tokens_per_second'
    | 'is_reasoning' | 'reasoning_effort' | 'is_streaming'
    | 'finish_reason' | 'has_tool_calls' | 'tool_call_counts'
    | 'status_code' | 'error_type' | 'metadata'
    | 'created_at' | 'completed_at' | 'expires_at'
> & { tags: string[] };

export interface RequestsResponse extends PaginatedResponse<RequestListItem>, AvailableFilterOptions {}

// A row of the overview's latest requests (GetRecentRequests).
export type RecentRequest = Pick<SpectraRequestAttributes,
    | RequestAppends
    | 'id' | 'provider' | 'model' | 'model_type' | 'status_code' | 'error_type'
    | 'prompt_tokens' | 'completion_tokens' | 'image_count' | 'video_count'
    | 'duration_seconds' | 'input_characters' | 'created_at'
>;

// A row of a trackable's latest requests (GetTrackableDetails).
export type TrackableRequest = Pick<SpectraRequestAttributes,
    | RequestAppends
    | 'id' | 'provider' | 'model' | 'model_type'
    | 'prompt_tokens' | 'completion_tokens'
    | 'image_count' | 'video_count' | 'input_characters' | 'duration_seconds'
    | 'total_cost_in_cents' | 'latency_ms' | 'status_code' | 'error_type' | 'created_at'
>;

export interface ModelCapabilities {
    text: boolean;
    images: boolean;
    video: boolean;
    audio: boolean;
}

// The request detail screen (GetRequestDetails): the whole row plus extras.
export interface RequestDetailsResponse extends SpectraRequestAttributes {
    request: JsonObject | null;
    response: JsonObject | null;
    formatted_expires_at: string | null;
    prompt: string | null;
    tags: string[];
    model_capabilities: ModelCapabilities;
    image_urls?: string[];
    audio_url?: string;
    audio_download_url?: string;
    media_storage_enabled?: boolean;
    can_open_in_playground: boolean;
    scores: ScoreEntry[];
    trace_migrated: boolean;
    trace: TraceDetailsResponse | null;
}

/* Traces */

export interface ModelBreakdownRow {
    provider: string;
    provider_display_name: string | null;
    model: string;
    request_count: number;
    total_cost_in_cents: number;
    total_tokens: number;
}

export interface SoleTrackable {
    type: string;
    id: string;
    name: string | null;
    email: string | null;
}

export interface CallModel {
    provider: string;
    provider_display_name: string | null;
    model: string;
}

export interface TraceListItem {
    trace_id: string;
    origin: string | null;
    request_count: number;
    failed_count: number;
    total_cost_in_cents: number;
    total_tokens: number;
    started_at: string | null;
    duration_ms: number;
    models: CallModel[];
    trackable: SoleTrackable | null;
}

export interface TracesSummary {
    trace_count: number;
    failed_trace_count: number;
    total_cost_in_cents: number;
    avg_cost_in_cents: number;
    avg_request_count: number;
    avg_duration_ms: number;
}

export interface TracesResponse extends PaginatedResponse<TraceListItem>, AvailableFilterOptions {
    summary: TracesSummary;
    migrated: boolean;
}

export interface TraceCall {
    id: string;
    provider: string;
    provider_display_name: string | null;
    model: string;
    model_type: string | null;
    endpoint: string | null;
    origin: string | null;
    span_id: string | null;
    parent_span_id: string | null;
    status_code: number | null;
    is_error: boolean;
    is_streaming: boolean;
    total_tokens: number;
    total_cost_in_cents: number;
    latency_ms: number | null;
    created_at: string | null;
    started_at: string | null;
    offset_ms: number | null;
    is_current: boolean;
}

export type SpanKind = 'span' | 'request' | 'job' | 'command' | 'tool';

export interface TraceSpan {
    span_id: string;
    parent_span_id: string | null;
    name: string;
    kind: SpanKind;
    origin: string | null;
    is_error: boolean;
    error_message: string | null;
    attributes: JsonObject;
    started_at: string;
    offset_ms: number | null;
    duration_ms: number;
}

export interface TraceDetailsResponse {
    trace_id: string;
    parent_span_id: string | null;
    origin: string | null;
    request_count: number;
    failed_count: number;
    position: number | null;
    total_cost_in_cents: number;
    total_tokens: number;
    started_at: string | null;
    duration_ms: number;
    timeline_ms: number;
    truncated: boolean;
    requests: TraceCall[];
    spans: TraceSpan[];
    models: ModelBreakdownRow[];
    tags: string[];
    trackable: SoleTrackable | null;
    scores: ScoreEntry[];
    migrated: boolean;
}

/* Conversations */

export interface ConversationListItem {
    conversation_id: string;
    request_count: number;
    turn_count: number;
    failed_count: number;
    total_cost_in_cents: number;
    total_tokens: number;
    started_at: string | null;
    last_activity_at: string | null;
    models: CallModel[];
    trackable: SoleTrackable | null;
}

export interface ConversationsSummary {
    conversation_count: number;
    failed_conversation_count: number;
    total_cost_in_cents: number;
    avg_cost_in_cents: number;
    avg_turn_count: number;
}

export interface ConversationsResponse extends PaginatedResponse<ConversationListItem>, AvailableFilterOptions {
    summary: ConversationsSummary;
    migrated: boolean;
}

export interface ConversationTurnCall {
    id: string;
    provider: string;
    provider_display_name: string | null;
    model: string;
    model_type: string | null;
    status_code: number | null;
    is_error: boolean;
    finish_reason: string | null;
    total_tokens: number;
    total_cost_in_cents: number;
    latency_ms: number | null;
}

export interface ConversationTurn {
    turn: number | null;
    started_at: string;
    prompt: string | null;
    reply: string | null;
    request_count: number;
    failed_count: number;
    total_cost_in_cents: number;
    running_cost_in_cents: number;
    total_tokens: number;
    duration_ms: number;
    trace_id: string | null;
    calls: ConversationTurnCall[];
}

export interface ConversationDetailsResponse {
    conversation_id: string;
    request_count: number;
    turn_count: number;
    failed_count: number;
    total_cost_in_cents: number;
    total_tokens: number;
    started_at: string | null;
    last_activity_at: string | null;
    truncated: boolean;
    stores_prompts: boolean;
    stores_responses: boolean;
    turns: ConversationTurn[];
    models: ModelBreakdownRow[];
    tags: string[];
    trackable: SoleTrackable | null;
    scores: ScoreEntry[];
    migrated: boolean;
}

/* Overview */

export interface TopModel {
    model: string;
    provider: string | null;
    count: number;
    cost: number;
}

export interface RequestByDate {
    date: string;
    count: number;
}

export interface RequestByProvider {
    provider: string | null;
    count: number;
}

export interface ModelTypeLatency {
    model_type: string;
    label: string;
    count: number;
    avg_latency: number;
}

export interface ModelTypeCost {
    model_type: string;
    label: string;
    count: number;
    cost: number;
}

export interface UnpricedRequests {
    request_count: number;
    models: string[];
}

export interface StatsResponse {
    total_requests: number;
    total_tokens: number;
    total_cost_in_cents: number;
    avg_latency: number;
    total_images: number;
    total_videos: number;
    total_duration_seconds: number;
    total_input_characters: number;
    tts_characters: number;
    tts_duration_seconds: number;
    stt_duration_seconds: number;
    cost_by_model_type: Record<string, number>;
    top_models: TopModel[];
    recent_requests: RecentRequest[];
    requests_by_date: RequestByDate[];
    requests_by_provider: RequestByProvider[];
    latency_by_model_type: ModelTypeLatency[];
    unpriced: UnpricedRequests;
    layout: string;
    stats_by_model_type: ModelTypeCost[] | null;
}

export interface ModelPerformance {
    provider: string;
    model: string;
    request_count: number;
    failed_count: number;
    error_rate: number;
    latency_p50: number | null;
    latency_p95: number | null;
    latency_p99: number | null;
    ttft_p50: number | null;
}

export interface PerformanceResponse {
    models: ModelPerformance[];
}

export interface AlertEntry {
    name: string;
    rule: string;
    metric: string;
    value: string | null;
    firing: boolean;
    since: string | null;
    notified_at: string | null;
}

export interface AlertsResponse {
    alerts: AlertEntry[];
    error: string | null;
}

/* Scores */

export type ScoreType = 'numeric' | 'boolean' | 'categorical';

export type ScoreValue = number | boolean | string | null;

export interface ScoreEntry {
    id: string;
    name: string;
    type: ScoreType;
    value: ScoreValue;
    comment: string | null;
    source: string;
    source_label: string;
    request_id: string | null;
    trace_id: string | null;
    conversation_id: string | null;
    created_at: string | null;
}

export interface ScoreSummary {
    name: string;
    type: ScoreType;
    count: number;
    average: number | null;
    minimum: number | null;
    maximum: number | null;
    labels: { label: string; count: number }[];
}

export interface ScoresResponse {
    scores: ScoreSummary[];
}

/* Costs */

export interface CostOverviewResponse {
    today: number;
    this_week: number;
    last_week: number;
    this_month: number;
    this_year: number;
}

export interface CostByProvider {
    provider: string | null;
    cost: number;
}

export interface CostByModel {
    model: string;
    provider: string | null;
    model_type: string;
    requests: number;
    tokens: number;
    images: number;
    videos: number;
    input_characters: number;
    duration_seconds: number;
    cost: number;
    capabilities: ModelCapabilities;
}

export interface CostByModelType {
    model_type: string;
    label: string;
    requests: number;
    tokens: number;
    images: number;
    videos: number;
    input_characters: number;
    duration_seconds: number;
    cost: number;
}

export interface CostByDate {
    date: string;
    cost: number;
}

export interface CostByUser {
    user_id: string;
    requests: number;
    cost: number;
    user_name: string | null;
    trackable_type: string | null;
}

export interface CostsResponse {
    total_cost_in_cents: number;
    cost_overview: CostOverviewResponse;
    costs_by_provider: CostByProvider[];
    costs_by_model: CostByModel[];
    costs_by_model_type: CostByModelType[];
    costs_by_date: CostByDate[];
    costs_by_user: CostByUser[];
}

/* Prompts and errors */

export interface PromptVersion {
    prompt_name: string;
    prompt_version: string | null;
    request_count: number;
    failed_count: number;
    error_rate: number;
    total_cost_in_cents: number;
    avg_cost_in_cents: number;
    avg_tokens: number;
    avg_latency_ms: number;
    first_seen_at: string | null;
    last_seen_at: string | null;
    scores: { name: string; type: ScoreType; count: number; average: number | null }[];
}

export interface PromptsResponse {
    versions: PromptVersion[];
    migrated: boolean;
}

export interface ErrorGroup {
    fingerprint: string;
    provider: string;
    status_code: number | null;
    error_type: string | null;
    message: string | null;
    latest_request_id: string;
    failure_count: number;
    share: number;
    models: { model: string; count: number }[];
    first_seen_at: string | null;
    last_seen_at: string | null;
    trend: number[];
}

export interface ErrorsResponse {
    request_count: number;
    failed_count: number;
    error_rate: number;
    ungrouped_count: number;
    migrated: boolean;
    groups: ErrorGroup[];
}

/* Evaluations */

export type EvalRunStatus = 'running' | 'completed' | 'failed';

// One score name over a run's results (EvaluationRunner::summary()).
export type EvalScoreSummary =
    | { type: 'boolean'; count: number; average: number | null }
    | { type: 'numeric'; count: number; average: number | null }
    | { type: 'categorical'; count: number; average: null; labels: Record<string, number> };

export interface EvalRun {
    id: string;
    evaluation: string;
    dataset: string;
    status: EvalRunStatus;
    example_count: number;
    error_count: number;
    summary: Record<string, EvalScoreSummary>;
    metadata: JsonObject;
    started_at: string | null;
    duration_ms: number | null;
}

export interface EvalRunWithCost extends EvalRun {
    total_cost_in_cents: number;
}

export interface EvalResult {
    id: string;
    example_id: string | null;
    trace_id: string;
    input: JsonValue;
    expected: JsonValue;
    output: JsonValue;
    scores: Record<string, ScoreValue>;
    error: string | null;
    latency_ms: number;
    total_cost_in_cents: number;
    call_count: number;
}

export interface EvalResultWithBaseline extends EvalResult {
    baseline: EvalResult | null;
}

export interface EvalRunResponse {
    run: EvalRunWithCost;
    baseline: EvalRunWithCost | null;
    results: EvalResultWithBaseline[];
    runs: EvalRun[];
}

export interface Evaluation {
    name: string;
    dataset: string;
    runs: EvalRun[];
}

export interface DatasetListItem {
    id: string;
    name: string;
    description: string | null;
    example_count: number;
    updated_at: string | null;
}

export interface EvalsResponse {
    evaluations: Evaluation[];
    datasets: DatasetListItem[];
    migrated: boolean;
    can_curate: boolean;
}

export interface DatasetExample {
    id: string;
    input: JsonValue;
    expected: JsonValue;
    metadata: JsonObject;
    source_request_id: string | null;
    created_at: string | null;
}

export interface DatasetResponse {
    dataset: {
        id: string;
        name: string;
        description: string | null;
        example_count: number;
    };
    examples: DatasetExample[];
    runs: EvalRun[];
}

export interface AddDatasetExampleResponse {
    dataset_id: string;
    example_id: string;
}

/* Review queues */

export interface AnnotationQueueScore {
    name: string;
    type: ScoreType;
    min: number | null;
    max: number | null;
    options: string[];
}

export interface AnnotationQueueSummary {
    name: string;
    description: string | null;
    scores: AnnotationQueueScore[];
    sample: number;
    filter: JsonObject;
    pending_count: number;
    completed_count: number;
    skipped_count: number;
}

export interface AnnotationQueuesResponse {
    queues: AnnotationQueueSummary[];
    can_annotate: boolean;
    migrated: boolean;
}

export interface AnnotationCall {
    id: string;
    provider: string;
    model: string;
    model_type: string | null;
    status_code: number | null;
    error_message: string | null;
    latency_ms: number | null;
    total_cost_in_cents: number;
    prompt: string | null;
    reply: string | null;
    is_queued_call: boolean;
}

export interface AnnotationReviewItem {
    id: string;
    queue: string;
    trace_id: string;
    request_id: string | null;
    origin: string | null;
    queued_at: string | null;
    calls: AnnotationCall[];
    scores: ScoreEntry[];
}

export interface AnnotationItemResponse {
    queue: AnnotationQueueSummary;
    item: AnnotationReviewItem | null;
    can_annotate: boolean;
}

export type AnnotationStatus = 'pending' | 'completed' | 'skipped';

export interface ReviewAnnotationResponse {
    status: AnnotationStatus;
}

export interface QueueTraceResponse {
    queued: boolean;
}

/* Trackables */

export interface TrackableListItem {
    trackable_type: string;
    trackable_id: string;
    requests: number;
    tokens: number;
    images: number;
    videos: number;
    tts_characters: number;
    audio_duration: number;
    cost: number;
    avg_latency: number;
    top_model: string | null;
    trackable_name: string | null;
    trackable_email: string | null;
}

export interface TrackablesSummaryResponse {
    total_trackables: number;
    total_requests: number;
    total_tokens: number;
    total_images: number;
    total_videos: number;
    total_tts_characters: number;
    tts_duration_seconds: number;
    stt_duration_seconds: number;
    total_cost: number;
    avg_latency: number;
}

export interface TrackablesResponse extends PaginatedResponse<TrackableListItem>, Omit<AvailableFilterOptions, 'available_reasoning_efforts'> {
    summary: TrackablesSummaryResponse;
    latency_by_model_type: ModelTypeLatency[];
}

export interface UsageByModel {
    model: string;
    provider: string | null;
    requests: number;
    tokens: number;
    cost: number;
}

export interface UsageByProvider {
    provider: string | null;
    requests: number;
    tokens: number;
    images: number;
    videos: number;
    tts_characters: number;
    audio_duration: number;
    cost: number;
}

export interface TrackableDetail {
    type: string;
    id: string;
    total_requests: number;
    total_tokens: number;
    total_images: number;
    total_videos: number;
    total_tts_characters: number;
    tts_duration_seconds: number;
    stt_duration_seconds: number;
    total_cost: number;
    avg_latency: number;
    costs_by_date: CostByDate[];
    usage_by_model: UsageByModel[];
    usage_by_provider: UsageByProvider[];
    latency_by_model_type: ModelTypeLatency[];
    // Only when the trackable's model still exists.
    name?: string | null;
    email?: string | null;
}

export interface TrackableDetailsResponse {
    trackable: TrackableDetail;
    requests: TrackableRequest[];
}

/* Maintenance */

export interface PrunePreview {
    hours: number;
    stats_days: number | null;
    cutoff: string;
    requests: number;
    requests_with_media: number;
    spans: number;
    daily_stats: number;
    scores: number;
    review_items: number;
}

export interface LastPrune {
    at: string;
    requests: number;
    spans: number;
    media_files: number;
    daily_stats: number;
    scores: number;
    review_items: number;
    hours: number;
    stats_days: number | null;
}

export interface LastRebuild {
    at: string;
    requests: number;
    from: string | null;
    to: string | null;
}

export interface MaintenanceStatusResponse {
    enabled: boolean;
    can_manage: boolean;
    recording_paused: boolean;
    totals: {
        requests: number;
        spans: number;
        daily_stats: number;
        oldest_request_at: string | null;
    };
    preview: PrunePreview;
    last_prune: LastPrune | null;
    last_rebuild: LastRebuild | null;
    pending: {
        prune: boolean;
        rebuild_stats: boolean;
    };
}

export interface MaintenanceQueuedResponse {
    queued: boolean;
}

export interface RecordingResponse {
    recording_paused: boolean;
}

export interface PricingFileSummary {
    path: string;
    exists: boolean;
    generated_at: string | null;
    error: string | null;
    models: number;
    providers: Record<string, { scraped_at: string | null; models: number }>;
}

export interface PricingStatusResponse {
    bundled: PricingFileSummary;
    file: PricingFileSummary | null;
    cache: {
        enabled: boolean;
        store: string | null;
    };
}

/* Playground */

export interface PlaygroundCall {
    id: string;
    provider: string;
    provider_display_name: string | null;
    model: string;
    endpoint: string | null;
    prompt: string | null;
    reply: string | null;
    status_code: number | null;
    error_type: string | null;
    error_message: string | null;
    finish_reason: string | null;
    prompt_tokens: number;
    completion_tokens: number;
    total_cost_in_cents: number;
    latency_ms: number | null;
    is_streaming: boolean;
    created_at_human: string | null;
    formatted_created_at: string | null;
}

export interface PlaygroundResponse {
    enabled: boolean;
    allowed: boolean;
    calls: PlaygroundCall[];
}

export interface PlaygroundCallResponse {
    call: PlaygroundCall;
    body: JsonObject | null;
    model: string;
    models: string[];
    base_url: string;
    notices: string[];
    unavailable_reason: string | null;
}

export interface PlaygroundRunResponse {
    succeeded: boolean;
    status_code: number | null;
    error_type: string | null;
    error_message: string | null;
    reply: string | null;
    response: JsonObject | null;
    model: string;
    finish_reason: string | null;
    has_tool_calls: boolean;
    prompt_tokens: number;
    completion_tokens: number;
    total_cost_in_cents: number;
    latency_ms: number | null;
    request_id: string | null;
}

// Shared dashboards: what a viewer gets. A cost is null on a dashboard that hides costs.
export interface DashboardStats {
    total_requests: number;
    total_tokens: number;
    total_images: number;
    total_videos: number;
    total_tts_characters: number;
    stt_duration_seconds: number;
    avg_latency: number;
    total_cost: number | null;
}

export interface DashboardUsageRow {
    provider: string | null;
    provider_name: string | null;
    model: string | null;
    requests: number;
    tokens: number;
    cost: number | null;
}

export interface DashboardRequest {
    provider: string | null;
    provider_name: string | null;
    model: string | null;
    model_type: string | null;
    tokens: number;
    images: number;
    videos: number;
    input_characters: number;
    duration_seconds: number;
    latency_ms: number | null;
    failed: boolean;
    cost: number | null;
    created_at: string | null;
}

export interface DashboardBudgetLimit {
    metric: 'cost' | 'tokens' | 'requests';
    period: 'daily' | 'weekly' | 'monthly' | 'total';
    used: number;
    limit: number;
}

export type DashboardWidgetSize = 'full' | 'half';

export type DashboardWidgetData = { size: DashboardWidgetSize } & (
    | { type: 'stats'; data: DashboardStats }
    | { type: 'cost_trend'; data: CostByDate[] }
    | { type: 'requests_trend'; data: RequestByDate[] }
    | { type: 'usage_by_model'; data: DashboardUsageRow[] }
    | { type: 'usage_by_provider'; data: DashboardUsageRow[] }
    | { type: 'recent_requests'; data: DashboardRequest[] }
    | { type: 'budget'; data: DashboardBudgetLimit[] }
);

export interface DashboardResponse {
    name: string;
    trackable_name: string | null;
    period: string;
    show_costs: boolean;
    widgets: DashboardWidgetData[];
}

// Shared dashboards: what an admin designs.
export interface DashboardSummary {
    id: string;
    name: string;
    trackable_type: string;
    trackable_id: string | null;
    trackable_name: string | null;
    widgets: { type: DashboardWidgetData['type']; size: DashboardWidgetSize }[];
    default_period: string;
    show_costs: boolean;
    is_active: boolean;
    url: string;
}

export interface DashboardsResponse {
    enabled: boolean;
    allowed: boolean;
    migrated: boolean;
    dashboards: DashboardSummary[];
    trackable_types: string[];
}
