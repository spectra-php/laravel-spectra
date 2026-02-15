@extends('spectra::ai.layout')

@section('content')
<div x-data="openaiPanel()" x-cloak>
    <h1 class="text-2xl font-bold mb-6">OpenAI</h1>

    <!-- Tabs -->
    <div class="flex gap-1 border-b border-gray-800 mb-6">
        <template x-for="t in tabs" :key="t">
            <button @click="tab = t"
                    :class="tab === t ? 'border-blue-500 text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-300'"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition" x-text="t"></button>
        </template>
    </div>

    <!-- ───── Text ───── -->
    <div x-show="tab === 'Text'" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Model</label>
                <select x-model="text.model" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm">
                    <option value="gpt-4.1-nano">gpt-4.1-nano</option>
                    <option value="gpt-4.1-mini">gpt-4.1-mini</option>
                    <option value="gpt-4.1">gpt-4.1</option>
                    <option value="gpt-4o-mini">gpt-4o-mini</option>
                    <option value="gpt-4o">gpt-4o</option>
                    <option value="gpt-5-mini">gpt-5-mini</option>
                    <option value="gpt-5">gpt-5</option>
                    <option value="gpt-5.1">gpt-5.1</option>
                    <option value="gpt-5.2">gpt-5.2</option>
                </select>
            </div>
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">System prompt</label>
            <textarea x-model="text.system" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm" placeholder="You are a helpful assistant."></textarea>
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">User prompt</label>
            <textarea x-model="text.user" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm" placeholder="Say hello!"></textarea>
        </div>
        <button @click="send('text', { model: text.model, system: text.system, user: text.user })"
                :disabled="loading" class="btn">
            <span x-show="loading && activeType === 'text'" class="spinner"></span>
            Send
        </button>
    </div>

    <!-- ───── Embeddings ───── -->
    <div x-show="tab === 'Embeddings'" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Model</label>
                <select x-model="embedding.model" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm">
                    <option value="text-embedding-3-small">text-embedding-3-small</option>
                    <option value="text-embedding-3-large">text-embedding-3-large</option>
                    <option value="text-embedding-ada-002">text-embedding-ada-002</option>
                </select>
            </div>
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Input text</label>
            <textarea x-model="embedding.input" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm" placeholder="The quick brown fox..."></textarea>
        </div>
        <button @click="send('embedding', { model: embedding.model, input: embedding.input })"
                :disabled="loading" class="btn">
            <span x-show="loading && activeType === 'embedding'" class="spinner"></span>
            Send
        </button>
    </div>

    <!-- ───── Audio ───── -->
    <div x-show="tab === 'Audio'" class="space-y-6">
        <!-- TTS -->
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-gray-300 uppercase tracking-wide">Text-to-Speech</h3>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Model</label>
                    <select x-model="tts.model" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm">
                        <option value="tts-1">tts-1</option>
                        <option value="tts-1-hd">tts-1-hd</option>
                        <option value="gpt-4o-mini-tts">gpt-4o-mini-tts</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Voice</label>
                    <select x-model="tts.voice" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm">
                        <option value="alloy">alloy</option>
                        <option value="echo">echo</option>
                        <option value="fable">fable</option>
                        <option value="onyx">onyx</option>
                        <option value="nova">nova</option>
                        <option value="shimmer">shimmer</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Input text</label>
                <textarea x-model="tts.input" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm" placeholder="Hello, world!"></textarea>
            </div>
            <button @click="send('tts', { model: tts.model, voice: tts.voice, input: tts.input })"
                    :disabled="loading" class="btn">
                <span x-show="loading && activeType === 'tts'" class="spinner"></span>
                Generate Speech
            </button>
            <template x-if="audioSrc">
                <audio :src="audioSrc" controls class="mt-2 w-full"></audio>
            </template>
        </div>

        <hr class="border-gray-800">

        <!-- STT -->
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-gray-300 uppercase tracking-wide">Speech-to-Text</h3>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Model</label>
                    <select x-model="stt.model" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm">
                        <option value="whisper-1">whisper-1</option>
                        <option value="gpt-4o-transcribe">gpt-4o-transcribe</option>
                        <option value="gpt-4o-mini-transcribe">gpt-4o-mini-transcribe</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Audio file</label>
                <input type="file" accept="audio/*" @change="stt.file = $event.target.files[0]"
                       class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm file:mr-3 file:bg-gray-700 file:text-gray-200 file:border-0 file:rounded file:px-3 file:py-1 file:text-xs">
            </div>
            <button @click="sendStt()"
                    :disabled="loading" class="btn">
                <span x-show="loading && activeType === 'stt'" class="spinner"></span>
                Transcribe
            </button>
        </div>
    </div>

    <!-- ───── Images ───── -->
    <div x-show="tab === 'Images'" class="space-y-4">
        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Model</label>
                <select x-model="image.model" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm">
                    <option value="gpt-image-1">gpt-image-1</option>
                    <option value="gpt-image-1-mini">gpt-image-1-mini</option>
                    <option value="dall-e-3">dall-e-3</option>
                    <option value="dall-e-2">dall-e-2</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Size</label>
                <select x-model="image.size" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm">
                    <option value="1024x1024">1024x1024</option>
                    <option value="1792x1024">1792x1024</option>
                    <option value="1024x1792">1024x1792</option>
                    <option value="512x512">512x512</option>
                    <option value="256x256">256x256</option>
                </select>
            </div>
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">Prompt</label>
            <textarea x-model="image.prompt" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm" placeholder="A white siamese cat"></textarea>
        </div>
        <button @click="send('image', { model: image.model, size: image.size, prompt: image.prompt })"
                :disabled="loading" class="btn">
            <span x-show="loading && activeType === 'image'" class="spinner"></span>
            Generate Image
        </button>
        <template x-if="imageSrc">
            <img :src="imageSrc" class="mt-4 rounded-lg max-w-lg border border-gray-700">
        </template>
    </div>

    <!-- ───── Video ───── -->
    <div x-show="tab === 'Video'" class="space-y-6">
        <!-- Generate -->
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-gray-300 uppercase tracking-wide">Generate</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Model</label>
                    <select x-model="video.model" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm">
                        <option value="sora-2">sora-2</option>
                        <option value="sora-2-pro">sora-2-pro</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Prompt</label>
                <textarea x-model="video.prompt" rows="3" class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm" placeholder="A cat walking on a beach at sunset"></textarea>
            </div>
            <button @click="send('video', { model: video.model, prompt: video.prompt })"
                    :disabled="loading" class="btn">
                <span x-show="loading && activeType === 'video'" class="spinner"></span>
                Generate Video
            </button>
        </div>

        <hr class="border-gray-800">

        <!-- Fetch Info -->
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-gray-300 uppercase tracking-wide">Fetch Video Info</h3>
            <p class="text-xs text-gray-500">Paste a video generation ID to check its status.</p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Video ID</label>
                    <input type="text" x-model="video.fetchId"
                           class="w-full bg-gray-800 border border-gray-700 rounded px-3 py-2 text-sm font-mono"
                           placeholder="vid_abc123...">
                </div>
            </div>
            <button @click="send('video_info', { video_id: video.fetchId })"
                    :disabled="loading || !video.fetchId" class="btn">
                <span x-show="loading && activeType === 'video_info'" class="spinner"></span>
                Fetch Info
            </button>
        </div>
    </div>

    <!-- ───── Response Area ───── -->
    <div class="mt-8" x-show="response || error">
        <div class="flex items-center gap-3 mb-2">
            <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide">Response</h2>
            <button @click="showRaw = !showRaw" class="text-xs text-gray-500 hover:text-gray-300 transition">
                <span x-text="showRaw ? 'Formatted' : 'Raw JSON'"></span>
            </button>
        </div>

        <!-- Error -->
        <template x-if="error">
            <div class="bg-red-900/30 border border-red-800 rounded-lg p-4 text-red-300 text-sm" x-text="error"></div>
        </template>

        <!-- Formatted -->
        <template x-if="response && !showRaw">
            <div class="bg-gray-900 border border-gray-800 rounded-lg p-4 text-sm whitespace-pre-wrap" x-text="formattedResponse"></div>
        </template>

        <!-- Raw JSON -->
        <template x-if="response && showRaw">
            <pre class="bg-gray-900 border border-gray-800 rounded-lg p-4 text-xs overflow-x-auto max-h-96" x-text="JSON.stringify(response, null, 2)"></pre>
        </template>
    </div>
</div>

<style>
    .btn {
        @apply px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md transition flex items-center gap-2;
    }
    .spinner {
        @apply inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin;
    }
</style>

<script>
function openaiPanel() {
    const basePath = @json(url(config('spectra.dashboard.path', 'spectra') . '/ai/openai'));

    return {
        tabs: ['Text', 'Embeddings', 'Audio', 'Images', 'Video'],
        tab: 'Text',
        loading: false,
        activeType: null,
        response: null,
        error: null,
        showRaw: false,
        audioSrc: null,
        imageSrc: null,

        text:      { model: 'gpt-4.1-nano', system: '', user: '' },
        embedding: { model: 'text-embedding-3-small', input: '' },
        tts:       { model: 'tts-1', voice: 'alloy', input: '' },
        stt:       { model: 'whisper-1', file: null },
        image:     { model: 'gpt-image-1', size: '1024x1024', prompt: '' },
        video:     { model: 'sora-2', prompt: '', fetchId: '' },

        get formattedResponse() {
            if (!this.response) return '';
            // Text completion
            if (this.response.choices?.[0]?.message?.content) {
                return this.response.choices[0].message.content;
            }
            // Embedding
            if (this.response.data?.[0]?.embedding) {
                const emb = this.response.data[0].embedding;
                return `Dimensions: ${emb.length}\nFirst 5: [${emb.slice(0, 5).join(', ')}...]`;
            }
            // Transcription
            if (this.response.text !== undefined) {
                return this.response.text;
            }
            // Image
            if (this.response.data?.[0]?.url) {
                return this.response.data[0].url;
            }
            if (this.response.data?.[0]?.b64_json) {
                return '[Base64 image displayed above]';
            }
            // Video status
            if (this.response.id && this.response.status !== undefined) {
                let out = `ID: ${this.response.id}\nStatus: ${this.response.status}`;
                if (this.response.model) out += `\nModel: ${this.response.model}`;
                if (this.response.seconds) out += `\nDuration: ${this.response.seconds}s`;
                if (this.response.created_at) out += `\nCreated: ${new Date(this.response.created_at * 1000).toLocaleString()}`;
                if (this.response.completed_at) out += `\nCompleted: ${new Date(this.response.completed_at * 1000).toLocaleString()}`;
                if (this.response.expires_at) out += `\nExpires: ${new Date(this.response.expires_at * 1000).toLocaleString()}`;
                return out;
            }
            return JSON.stringify(this.response, null, 2);
        },

        async send(type, payload) {
            this.loading = true;
            this.activeType = type;
            this.response = null;
            this.error = null;
            this.audioSrc = null;
            if (type !== 'image') this.imageSrc = null;

            try {
                const res = await fetch(basePath, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ type, ...payload }),
                });

                const data = await res.json();
                if (!res.ok) {
                    this.error = data.message || data.error || `HTTP ${res.status}`;
                    return;
                }
                this.response = data;

                // Auto-fill video fetch ID after generation
                if (type === 'video' && data.id) {
                    this.video.fetchId = data.id;
                }

                // Handle TTS audio
                if (type === 'tts' && data.audio_base64) {
                    this.audioSrc = 'data:audio/mp3;base64,' + data.audio_base64;
                }

                // Handle image
                if (type === 'image') {
                    if (data.data?.[0]?.url) {
                        this.imageSrc = data.data[0].url;
                    } else if (data.data?.[0]?.b64_json) {
                        this.imageSrc = 'data:image/png;base64,' + data.data[0].b64_json;
                    }
                }
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async sendStt() {
            if (!this.stt.file) {
                this.error = 'Please select an audio file.';
                return;
            }

            this.loading = true;
            this.activeType = 'stt';
            this.response = null;
            this.error = null;

            const form = new FormData();
            form.append('type', 'stt');
            form.append('model', this.stt.model);
            form.append('file', this.stt.file);

            try {
                const res = await fetch(basePath, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: form,
                });

                const data = await res.json();
                if (!res.ok) {
                    this.error = data.message || data.error || `HTTP ${res.status}`;
                    return;
                }
                this.response = data;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        }
    };
}
</script>
@endsection
