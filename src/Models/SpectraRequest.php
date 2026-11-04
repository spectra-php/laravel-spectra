<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Spectra\Database\Factories\SpectraRequestFactory;
use Spectra\Support\Casts\PreciseDateTime;
use Spectra\Support\ProviderRegistry;

/**
 * One tracked AI call: the provider, model and endpoint, who made it, its
 * usage, cost and latency, and (when storing is enabled) its payloads.
 *
 * A request is successful when its status code is 2xx and failed otherwise,
 * including a missing status code, so the two always cover every row. The
 * aggregate aliases below are the columns the dashboard and the usage
 * queries select; they exist only on those query results.
 *
 * @property string $id
 * @property string|null $batch_id
 * @property string|null $trace_id
 * @property string|null $parent_span_id
 * @property string|null $span_id
 * @property string|null $response_id
 * @property string $provider
 * @property string $model
 * @property string|null $snapshot
 * @property string|null $model_type
 * @property string|null $endpoint
 * @property string|null $trackable_type
 * @property string|null $trackable_id
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $conversation_id
 * @property int|null $conversation_turn
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $cached_tokens
 * @property int $cache_write_tokens
 * @property int $reasoning_tokens
 * @property int|null $page_count
 * @property string|null $currency
 * @property string|null $error_type
 * @property string|null $error_message
 * @property string|null $request_source
 * @property string|null $origin
 * @property string|null $laravel_request_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property float|null $duration_seconds
 * @property int|null $input_characters
 * @property int|null $image_count
 * @property int|null $video_count
 * @property float $prompt_cost
 * @property float $completion_cost
 * @property float $total_cost_in_cents
 * @property string|null $pricing_tier
 * @property int|null $latency_ms
 * @property int|null $time_to_first_token_ms
 * @property string|null $tokens_per_second
 * @property bool $is_reasoning
 * @property string|null $reasoning_effort
 * @property bool $is_streaming
 * @property string|null $finish_reason
 * @property bool $has_tool_calls
 * @property array<string, int>|null $tool_call_counts
 * @property int|null $status_code
 * @property array<string, mixed>|null $media_storage_path
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $expires_at
 * @property-read string|null $prompt
 * @property-read string|null $response_text
 * @property-read int $total_tokens
 * @property-read string|null $formatted_created_at
 * @property-read string|null $formatted_expires_at
 * @property-read string|null $created_at_human
 * @property-read string|null $provider_display_name
 * @property-read float $total_cost_in_dollars
 * @property-read mixed $cost_sum
 * @property-read mixed $date
 * @property-read mixed $requests
 * @property-read mixed $tokens_sum
 * @property-read mixed $images_sum
 * @property-read mixed $videos_sum
 * @property-read mixed $input_characters_sum
 * @property-read mixed $duration_seconds_sum
 * @property-read mixed $cost
 * @property-read mixed $tts_characters_sum
 * @property-read mixed $audio_duration_sum
 * @property-read mixed $latency_avg
 * @property-read mixed $count
 * @property-read mixed $avg_latency
 * @property-read mixed $total_requests
 * @property-read mixed $total_trackables
 * @property-read mixed $total_images
 * @property-read mixed $total_videos
 * @property-read mixed $total_duration_seconds
 * @property-read mixed $total_input_characters
 * @property-read mixed $tts_characters
 * @property-read mixed $tts_duration_seconds
 * @property-read mixed $stt_duration_seconds
 * @property-read mixed $request_count
 * @property-read mixed $total_cost
 * @property-read mixed $period
 * @property-read mixed $model_count
 * @property-read mixed $tts_duration_sum
 * @property-read mixed $stt_duration_sum
 * @property-read mixed $avg_latency_ms
 * @property-read mixed $failed_count
 * @property-read mixed $tokens
 * @property-read mixed $first_started_at
 * @property-read mixed $last_started_at
 * @property-read mixed $turn_count
 * @property-read mixed $duration_ms
 * @property-read Collection<int, SpectraTag> $tags
 */
class SpectraRequest extends Model
{
    /** @use HasFactory<SpectraRequestFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): SpectraRequestFactory
    {
        return SpectraRequestFactory::new();
    }

    protected $guarded = [];

    /**
     * The raw request/response payloads are heavy and potentially sensitive,
     * so they are hidden from serialization by default. The request-details
     * endpoint re-exposes them explicitly via makeVisible().
     *
     * @var list<string>
     */
    protected $hidden = ['request', 'response'];

    protected $appends = ['provider_display_name', 'total_tokens', 'formatted_created_at', 'created_at_human'];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'request' => 'json',
            'response' => 'json',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'page_count' => 'integer',
            'conversation_turn' => 'integer',
            'duration_seconds' => 'float',
            'input_characters' => 'integer',
            'image_count' => 'integer',
            'video_count' => 'integer',
            'prompt_cost' => 'float',
            'completion_cost' => 'float',
            'total_cost_in_cents' => 'float',
            'created_at' => 'datetime',
            'started_at' => PreciseDateTime::class,
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'latency_ms' => 'integer',
            'time_to_first_token_ms' => 'integer',
            'tokens_per_second' => 'decimal:2',
            'reasoning_tokens' => 'integer',
            'is_reasoning' => 'boolean',
            'is_streaming' => 'boolean',
            'has_tool_calls' => 'boolean',
            'tool_call_counts' => 'json',
            'media_storage_path' => 'json',
            'metadata' => 'json',
            'status_code' => 'integer',
            // A freshly created row still holds the trackable's own key type.
            'trackable_id' => 'string',
        ];
    }

    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * Get the model that made this request.
     *
     * @return MorphTo<Model, $this>
     */
    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the tags attached to this request.
     *
     * @return BelongsToMany<SpectraTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            SpectraTag::class,
            'spectra_requests_tags',
            'request_id',
            'tag_id'
        );
    }

    /**
     * Scope the query to requests carrying the given tag.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithTag(Builder $query, string $tag): Builder
    {
        return $query->whereHas('tags', function ($q) use ($tag) {
            $q->where('name', $tag);
        });
    }

    /**
     * Scope the query to requests carrying at least one of the given tags.
     *
     * @param  Builder<static>  $query
     * @param  array<int, string>  $tags
     * @return Builder<static>
     */
    public function scopeWithAnyTags(Builder $query, array $tags): Builder
    {
        return $query->whereHas('tags', function ($q) use ($tags) {
            $q->whereIn('name', $tags);
        });
    }

    /**
     * Attach tags to this request.
     *
     * @param  array<string>  $tags
     */
    public function attachTags(array $tags): void
    {
        $tagModels = [];

        foreach ($tags as $tag) {
            $tagModels[] = SpectraTag::findOrCreateByName($tag);
        }

        $this->tags()->syncWithoutDetaching(
            collect($tagModels)->pluck('id')->toArray()
        );
    }

    /**
     * Scope the query to one provider's requests.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope the query to one model's requests.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeModel(Builder $query, string $model): Builder
    {
        return $query->where('model', $model);
    }

    /**
     * Scope the query to requests with a 2xx status code.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereBetween('status_code', [200, 299]);
    }

    /**
     * Scope the query to requests with a non-2xx status code or none at all.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNotBetween('status_code', [200, 299])
                ->orWhereNull('status_code');
        });
    }

    /**
     * Requests that belong to the given trace.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTrace(Builder $query, string $traceId): Builder
    {
        // trace_id is a native uuid column on PostgreSQL, which rejects any
        // other string with an error instead of matching nothing. Other
        // drivers store it as text, where custom trace ids can exist.
        if (! Str::isUuid($traceId) && $this->getConnection()->getDriverName() === 'pgsql') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('trace_id', $traceId);
    }

    /**
     * Scope the query to one model type's requests.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeModelType(Builder $query, string $modelType): Builder
    {
        return $query->where('model_type', $modelType);
    }

    /**
     * Scope the query to requests that stopped for the given reason.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFinishReason(Builder $query, string $reason): Builder
    {
        return $query->where('finish_reason', $reason);
    }

    /**
     * Scope the query to requests in which the model called a tool.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithToolCalls(Builder $query): Builder
    {
        return $query->where('has_tool_calls', true);
    }

    /**
     * Scope the query to the requests one trackable model made.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTrackable(Builder $query, Model $trackable): Builder
    {
        return $query->where('trackable_type', $trackable->getMorphClass())
            ->where('trackable_id', $trackable->getKey());
    }

    /**
     * Get the prompt text kept in the stored response.
     *
     * @return Attribute<string|null, never>
     */
    protected function prompt(): Attribute
    {
        return Attribute::get(fn () => $this->response['prompt'] ?? null);
    }

    /**
     * Get the generated text kept in the stored response.
     *
     * @return Attribute<string|null, never>
     */
    protected function responseText(): Attribute
    {
        return Attribute::get(fn () => $this->response['response'] ?? null);
    }

    /**
     * Get the request's input and output tokens added together.
     *
     * @return Attribute<int, never>
     */
    protected function totalTokens(): Attribute
    {
        return Attribute::get(fn () => $this->prompt_tokens + $this->completion_tokens);
    }

    /**
     * Get when the request was made, in the dashboard's date format.
     *
     * @return Attribute<string|null, never>
     */
    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::get(fn () => $this->created_at?->format(config('spectra.dashboard.date_format', 'M j, Y g:i:s A')));
    }

    /**
     * Get when the provider's copy of the result expires, in the dashboard's date format.
     *
     * @return Attribute<string|null, never>
     */
    protected function formattedExpiresAt(): Attribute
    {
        return Attribute::get(fn () => $this->expires_at?->format(config('spectra.dashboard.date_format', 'M j, Y g:i:s A')));
    }

    /**
     * Get how long ago the request was made, in words.
     *
     * @return Attribute<string|null, never>
     */
    protected function createdAtHuman(): Attribute
    {
        return Attribute::get(fn () => $this->created_at?->diffForHumans());
    }

    /**
     * Get the display name of the request's provider.
     *
     * @return Attribute<string|null, never>
     */
    protected function providerDisplayName(): Attribute
    {
        // Read the raw attribute: aggregate rows and narrow selects may not
        // load the provider column at all.
        return Attribute::get(function () {
            $provider = $this->attributes['provider'] ?? null;

            return filled($provider) ? app(ProviderRegistry::class)->displayName($provider) : null;
        });
    }

    /**
     * Get the request's cost in dollars.
     *
     * @return Attribute<float, never>
     */
    protected function totalCostInDollars(): Attribute
    {
        return Attribute::get(fn () => $this->total_cost_in_cents / 100);
    }

    /**
     * Determine if the request got a 2xx status code.
     */
    public function isSuccessful(): bool
    {
        return $this->status_code !== null && $this->status_code >= 200 && $this->status_code < 300;
    }

    /**
     * Whether the request failed: any status outside 200-299, or none at all.
     */
    public function isFailed(): bool
    {
        return ! $this->isSuccessful();
    }
}
