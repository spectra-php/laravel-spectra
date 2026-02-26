<?php

namespace Spectra\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spectra\Database\Factories\SpectraRequestFactory;
use Spectra\Support\ProviderRegistry;

/**
 * @property string $id
 * @property string|null $batch_id
 * @property string|null $trace_id
 * @property string|null $response_id
 * @property string|null $provider
 * @property string $model
 * @property string|null $snapshot
 * @property string|null $model_type
 * @property string|null $endpoint
 * @property string|null $trackable_type
 * @property string|null $trackable_id
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $reasoning_tokens
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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property-read string|null $prompt
 * @property-read string|null $response_text
 * @property-read int $total_tokens
 * @property-read string|null $formatted_created_at
 * @property-read string|null $formatted_expires_at
 * @property-read string|null $created_at_human
 * @property-read string|null $provider_display_name
 * @property-read float $total_cost_in_dollars
 * @property-read string|null $user_id
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
 * @property-read mixed $model_count
 * @property-read mixed $total_trackables
 * @property-read mixed $total_images
 * @property-read mixed $total_videos
 * @property-read mixed $total_duration_seconds
 * @property-read mixed $total_input_characters
 * @property-read mixed $tts_characters
 * @property-read mixed $tts_duration_seconds
 * @property-read mixed $stt_duration_seconds
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spectra\Models\SpectraTag> $tags
 */
class SpectraRequest extends Model
{
    /** @use HasFactory<SpectraRequestFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected static function newFactory(): SpectraRequestFactory
    {
        return SpectraRequestFactory::new();
    }

    protected $guarded = [];

    protected $appends = ['provider_display_name', 'total_tokens', 'formatted_created_at', 'formatted_expires_at', 'created_at_human'];

    protected function casts(): array
    {
        return [
            'request' => 'json',
            'response' => 'json',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'duration_seconds' => 'float',
            'input_characters' => 'integer',
            'image_count' => 'integer',
            'video_count' => 'integer',
            'prompt_cost' => 'float',
            'completion_cost' => 'float',
            'total_cost_in_cents' => 'float',
            'created_at' => 'datetime',
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
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Spectra\Models\SpectraTag, $this>
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
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function withTag(Builder $query, string $tag): Builder
    {
        return $query->whereHas('tags', function ($q) use ($tag) {
            $q->where('name', $tag);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @param  array<int, string>  $tags
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function withAnyTags(Builder $query, array $tags): Builder
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
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function provider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function model(Builder $query, string $model): Builder
    {
        return $query->where('model', $model);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function successful(Builder $query): Builder
    {
        return $query->whereBetween('status_code', [200, 299]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function failed(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status_code', '>=', 400)
                ->orWhereNull('status_code');
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function modelType(Builder $query, string $modelType): Builder
    {
        return $query->where('model_type', $modelType);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function finishReason(Builder $query, string $reason): Builder
    {
        return $query->where('finish_reason', $reason);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function withToolCalls(Builder $query): Builder
    {
        return $query->where('has_tool_calls', true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[Scope]
    protected function forTrackable(Builder $query, Model $trackable): Builder
    {
        return $query->where('trackable_type', $trackable->getMorphClass())
            ->where('trackable_id', $trackable->getKey());
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function prompt(): Attribute
    {
        return Attribute::get(fn () => $this->response['prompt'] ?? null);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function responseText(): Attribute
    {
        return Attribute::get(fn () => $this->response['response'] ?? null);
    }

    /**
     * @return Attribute<int, never>
     */
    protected function totalTokens(): Attribute
    {
        return Attribute::get(fn () => $this->prompt_tokens + $this->completion_tokens);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::get(fn () => $this->created_at?->format(config('spectra.dashboard.date_format', 'M j, Y g:i:s A')));
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function formattedExpiresAt(): Attribute
    {
        return Attribute::get(fn () => $this->expires_at?->format(config('spectra.dashboard.date_format', 'M j, Y g:i:s A')));
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function createdAtHuman(): Attribute
    {
        return Attribute::get(fn () => $this->created_at?->diffForHumans());
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function providerDisplayName(): Attribute
    {
        return Attribute::get(fn () => $this->provider !== null
            ? app(ProviderRegistry::class)->displayName($this->provider)
            : null
        );
    }

    /**
     * @return Attribute<float, never>
     */
    protected function totalCostInDollars(): Attribute
    {
        return Attribute::get(fn () => $this->total_cost_in_cents / 100);
    }

    public function isSuccessful(): bool
    {
        return $this->status_code !== null && $this->status_code >= 200 && $this->status_code < 300;
    }

    /**
     * Check if the request failed (4xx/5xx or null status code).
     */
    public function isFailed(): bool
    {
        return $this->status_code === null || $this->status_code >= 400;
    }
}
