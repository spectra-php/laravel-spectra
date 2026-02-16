<template>
    <component :is="logos[provider]" v-if="logos[provider]" />
</template>

<script setup>
import AnthropicLogo from './logos/AnthropicLogo.vue';
import BedrockLogo from './logos/BedrockLogo.vue';
import CohereLogo from './logos/CohereLogo.vue';
import DeepseekLogo from './logos/DeepseekLogo.vue';
import ElevenlabsLogo from './logos/ElevenlabsLogo.vue';
import GoogleLogo from './logos/GoogleLogo.vue';
import GroqLogo from './logos/GroqLogo.vue';
import MetaLogo from './logos/MetaLogo.vue';
import MicrosoftLogo from './logos/MicrosoftLogo.vue';
import MistralLogo from './logos/MistralLogo.vue';
import OllamaLogo from './logos/OllamaLogo.vue';
import OpenaiLogo from './logos/OpenaiLogo.vue';
import OpenrouterLogo from './logos/OpenrouterLogo.vue';
import ReplicateLogo from './logos/ReplicateLogo.vue';
import XaiLogo from './logos/XaiLogo.vue';

defineProps({
    provider: String,
});

const logos = {
    anthropic: AnthropicLogo,
    bedrock: BedrockLogo,
    cohere: CohereLogo,
    deepseek: DeepseekLogo,
    elevenlabs: ElevenlabsLogo,
    google: GoogleLogo,
    groq: GroqLogo,
    meta: MetaLogo,
    microsoft: MicrosoftLogo,
    mistral: MistralLogo,
    ollama: OllamaLogo,
    openai: OpenaiLogo,
    openrouter: OpenrouterLogo,
    replicate: ReplicateLogo,
    xai: XaiLogo,
};
</script>
