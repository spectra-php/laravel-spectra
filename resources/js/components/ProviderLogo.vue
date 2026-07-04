<template>
    <component :is="logos[provider]" v-if="logos[provider]" />
</template>

<script setup>
import AnthropicLogo from './logos/AnthropicLogo.vue';
import BedrockLogo from './logos/BedrockLogo.vue';
import CohereLogo from './logos/CohereLogo.vue';
import DeepseekLogo from './logos/DeepseekLogo.vue';
import ElevenlabsLogo from './logos/ElevenlabsLogo.vue';
import FalaiLogo from './logos/FalaiLogo.vue';
import GoogleLogo from './logos/GoogleLogo.vue';
import GroqLogo from './logos/GroqLogo.vue';
import MetaLogo from './logos/MetaLogo.vue';
import MicrosoftLogo from './logos/MicrosoftLogo.vue';
import MistralLogo from './logos/MistralLogo.vue';
import OllamaLogo from './logos/OllamaLogo.vue';
import OpenaiLogo from './logos/OpenaiLogo.vue';
import OpenrouterLogo from './logos/OpenrouterLogo.vue';
import ReplicateLogo from './logos/ReplicateLogo.vue';
import ScalewayLogo from './logos/ScalewayLogo.vue';
import XaiLogo from './logos/XaiLogo.vue';

defineProps({
    provider: String,
});

// Keyed by provider slug. bedrock/deepseek/meta/microsoft have no built-in
// provider — they render only for user-registered custom providers that use
// those slugs, so keep them even though no bundled provider maps to them.
const logos = {
    anthropic: AnthropicLogo,
    bedrock: BedrockLogo,
    cohere: CohereLogo,
    deepseek: DeepseekLogo,
    elevenlabs: ElevenlabsLogo,
    falai: FalaiLogo,
    google: GoogleLogo,
    groq: GroqLogo,
    meta: MetaLogo,
    microsoft: MicrosoftLogo,
    mistral: MistralLogo,
    ollama: OllamaLogo,
    openai: OpenaiLogo,
    openrouter: OpenrouterLogo,
    replicate: ReplicateLogo,
    scaleway: ScalewayLogo,
    xai: XaiLogo,
};
</script>
