<script setup>

</script>

<template>
  <svg viewBox="0 0 500 120" xmlns="http://www.w3.org/2000/svg">
    <defs>
      <linearGradient id="rainbow" x1="0%" y1="0%" x2="100%" y2="0%">
        <stop offset="0%">
          <animate attributeName="stop-color" values="#E31E24;#2E3192;#00AEEF;#4DB748;#FFD200;#F68B1E;#E31E24" dur="6s" repeatCount="indefinite" />
        </stop>
        <stop offset="20%">
          <animate attributeName="stop-color" values="#F68B1E;#E31E24;#2E3192;#00AEEF;#4DB748;#FFD200;#F68B1E" dur="6s" repeatCount="indefinite" />
        </stop>
        <stop offset="40%">
          <animate attributeName="stop-color" values="#FFD200;#F68B1E;#E31E24;#2E3192;#00AEEF;#4DB748;#FFD200" dur="6s" repeatCount="indefinite" />
        </stop>
        <stop offset="60%">
          <animate attributeName="stop-color" values="#4DB748;#FFD200;#F68B1E;#E31E24;#2E3192;#00AEEF;#4DB748" dur="6s" repeatCount="indefinite" />
        </stop>
        <stop offset="80%">
          <animate attributeName="stop-color" values="#00AEEF;#4DB748;#FFD200;#F68B1E;#E31E24;#2E3192;#00AEEF" dur="6s" repeatCount="indefinite" />
        </stop>
        <stop offset="100%">
          <animate attributeName="stop-color" values="#2E3192;#00AEEF;#4DB748;#FFD200;#F68B1E;#E31E24;#2E3192" dur="6s" repeatCount="indefinite" />
        </stop>
      </linearGradient>
      <mask id="softStripes">
        <rect width="100%" height="100%" fill="white" />
        <g fill="black" opacity="0.3">
          <rect y="52" width="100%" height="3" />
          <rect y="62" width="100%" height="3" />
          <rect y="72" width="100%" height="3" />
          <rect y="82" width="100%" height="3" />
        </g>
      </mask>
      <filter id="softGlow" x="-20%" y="-20%" width="140%" height="140%">
        <feGaussianBlur stdDeviation="1.4" result="blur" />
        <feMerge>
          <feMergeNode in="blur" />
          <feMergeNode in="SourceGraphic" />
        </feMerge>
      </filter>
    </defs>
    <text
        x="50%"
        y="86"
        text-anchor="middle"
        font-size="88"
        font-style="italic"
        font-weight="700"
        letter-spacing="-1.5"
        font-family="Inter, SF Pro Display, Helvetica Neue, Arial, sans-serif"
        fill="url(#rainbow)"
        mask="url(#softStripes)"
        filter="url(#softGlow)"
        transform="skewX(-6)"
    >
      Spectra
    </text>
  </svg>

</template>

<style scoped>

</style>