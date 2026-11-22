<script setup lang="ts">
defineProps<{ flying: boolean }>()

const route = 'M-24 124 C31 55 105 113.5 164 77'
</script>

<template>
    <svg class="address-plane" viewBox="0 0 260 146" aria-hidden="true" focusable="false">
        <path class="flight-trail" :d="route" fill="none" stroke="#a499dc"
            stroke-width="1.8" stroke-linecap="round" stroke-dasharray="3 6" />
        <g class="plane" :class="{ flying }" :style="{ '--route': `path('${route}')` }">
            <g stroke="#8272c8" stroke-width="1.45" stroke-linejoin="round">
                <path d="M-13-27 88 0 10 5Z" fill="#fdfcff" />
                <path d="M10 5 88 0 13 17 5 25Z" fill="#9b8ad9" />
                <path d="M5 25 13 17 27 17Z" fill="#c2b5ec" />
                <path d="M13 13 88 0 29 27Z" fill="#e5dcfa" />
            </g>
        </g>
    </svg>
</template>

<style scoped>
.address-plane {
    position: absolute;
    z-index: -1;
    right: 12px;
    top: 50%;
    width: 29%;
    max-width: 260px;
    height: auto;
    transform: translateY(-50%);
    pointer-events: none;
}
.plane {
    transform: translate(164px, 77px) rotate(-32deg);
    filter: drop-shadow(0 4px 4px #8172b824);
}
@supports (offset-path: path('M0 0L1 1')) {
    .plane {
        transform: none;
        offset-path: var(--route);
        offset-anchor: 0 0;
        offset-distance: 100%;
        offset-rotate: auto;
    }
    @media (prefers-reduced-motion: no-preference) {
        .flying { animation: fly-in 3.2s linear; }
        @keyframes fly-in {
            from { offset-distance: 100%; opacity: 1; transform: none; }
            9% { offset-distance: 100%; opacity: 0; transform: translateX(10px); }
            10% { offset-distance: 0%; opacity: 0; transform: none; animation-timing-function: ease-in-out; }
            30% { opacity: 1; }
            to { offset-distance: 100%; opacity: 1; transform: none; }
        }
    }
}
@container (max-width: 550px) {
    .address-plane { width: 101px; right: 11px; top: 0; transform: none; }
}
@container (max-width: 250px) {
    .address-plane { width: 66px; right: 5px; top: 2px; }
    .flight-trail { opacity: .45; }
}
</style>
