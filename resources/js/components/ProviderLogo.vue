<template>
    <component :is="logos[provider as string]" v-if="logos[provider as string]" />
</template>

<script setup lang="ts">
import type { Component } from 'vue';
import AnthropicLogo from './logos/AnthropicLogo.vue';
import BedrockLogo from './logos/BedrockLogo.vue';
import CerebrasLogo from './logos/CerebrasLogo.vue';
import CohereLogo from './logos/CohereLogo.vue';
import DeepinfraLogo from './logos/DeepinfraLogo.vue';
import DeepseekLogo from './logos/DeepseekLogo.vue';
import ElevenlabsLogo from './logos/ElevenlabsLogo.vue';
import FalaiLogo from './logos/FalaiLogo.vue';
import FireworksLogo from './logos/FireworksLogo.vue';
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
import TogetherLogo from './logos/TogetherLogo.vue';
import XaiLogo from './logos/XaiLogo.vue';

defineProps<{
    provider?: string | null;
}>();

// Keyed by provider slug. bedrock/deepseek/meta/microsoft have no built-in
// provider — they render only for user-registered custom providers that use
// those slugs, so keep them even though no bundled provider maps to them.
const logos: Record<string, Component> = {
    anthropic: AnthropicLogo,
    bedrock: BedrockLogo,
    cerebras: CerebrasLogo,
    cohere: CohereLogo,
    deepinfra: DeepinfraLogo,
    deepseek: DeepseekLogo,
    elevenlabs: ElevenlabsLogo,
    falai: FalaiLogo,
    fireworks: FireworksLogo,
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
    together: TogetherLogo,
    xai: XaiLogo,
};
</script>
