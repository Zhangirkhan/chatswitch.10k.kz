<script setup lang="ts">
const kzCountryOutlinePath =
    'M799,200.425L784.106,220.165L767.948,222.956L767.035,252.153L756.146,265.14L717.437,255.715L703.386,306.426L693.34,312.592L654.701,323.638L672.264,370.793L658.916,377.753L660.462,392.884L648.449,388.991L638.613,379.466L609.669,376.67L577.283,375.948L570.257,378.88L542.437,367.664L531.337,373.191L528.316,388.901L496.211,379.737L483.355,383.516L478.999,395.117L467.829,399.974L442.046,418.21L433.546,436.686L426.239,436.86L420.9,424.625L396.101,423.791L392.097,402.42L382.612,402.242L384.088,375.677L360.694,356.096L327.253,358.197L304.351,362.118L285.734,337.662L269.787,327.319L239.508,307.511L235.855,305.105L185.624,321.491L186.397,420.145L176.421,421.42L162.722,400.953L149.514,393.554L127.385,399.04L118.743,407.788L117.69,401.398L122.467,390.424L118.743,381.178L96.122,372.06L87.341,347.894L76.522,340.988L75.889,332.105L94.858,334.701L95.63,314.563L112.21,310.053L129.281,314.188L132.794,286.924L129.281,269.396L109.751,270.748L93.171,263.783L70.62,276.335L52.425,282.285L42.449,277.68L44.486,263.007L32.052,243.729L17.509,244.563L1,224.748L12.24,202.295L6.55,196.222L22.146,162.938L42.168,180.572L44.627,158.313L84.882,124.455L115.371,123.656L158.366,145.296L181.479,157.793L202.204,144.771L233.115,144.141L258.125,160.134L263.745,151.009L291.214,152.316L296.061,137.609L264.447,116.011L283.205,100.489L279.552,91.737L298.239,83.369L284.188,60.974L293.111,49.667L366.103,38.098L375.657,29.798L424.483,17.311L442.046,3.14L477.102,10.504L483.285,45.446L503.658,37.304L528.668,48.712L527.052,66.704L545.809,64.87L594.705,33.499L587.539,43.979L612.479,69.532L656.036,150.277L666.433,134.014L693.34,151.898L721.371,143.983L732.19,149.544L741.533,167.289L755.162,173.224L763.522,186.097L788.673,182.057Z';

const majorMarkers = [
    { cx: 595.1, cy: 368.8 },
    { cx: 488.1, cy: 142.1 },
    { cx: 452.4, cy: 393.7 },
] as const;

const minorMarkers = [
    { cx: 528, cy: 248 },
    { cx: 208, cy: 256 },
    { cx: 564, cy: 166 },
    { cx: 388, cy: 120 },
    { cx: 248, cy: 318 },
] as const;

defineProps<{
    eyebrow: string;
    mapCaption: string;
    title: string;
    lead: string;
    note: string;
    lawBadge: string;
    lawQuote: string;
    lawFine: string;
    lawLinkPd: string;
    lawLinkCoap: string;
    dataPoints: readonly string[];
    pillMade: string;
    pillData: string;
    pillSupport: string;
    fitLead: string;
    securityHeading: string;
    securityList: readonly string[];
}>();
</script>

<template>
    <section id="data-kz" class="landing-data-kz landing__section-block">
        <div class="landing-data-kz__shell">
            <div class="landing-data-kz__highlight">
                <div class="landing-data-kz__map-tile">
                    <div class="kz-map-block" aria-hidden="true">
                        <svg class="kz-map-svg" viewBox="0 0 800 440" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="accel-kz-stroke" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#06d670" stop-opacity="0.55" />
                                    <stop offset="100%" stop-color="#01b964" stop-opacity="0.25" />
                                </linearGradient>
                                <radialGradient id="accel-kz-halo" cx="50%" cy="50%" r="50%">
                                    <stop offset="0%" stop-color="#06d670" stop-opacity="0.55" />
                                    <stop offset="70%" stop-color="#01b964" stop-opacity="0.15" />
                                    <stop offset="100%" stop-color="#01b964" stop-opacity="0" />
                                </radialGradient>
                                <radialGradient id="accel-kz-core" cx="35%" cy="30%" r="65%">
                                    <stop offset="0%" stop-color="#eafff3" />
                                    <stop offset="45%" stop-color="#06d670" />
                                    <stop offset="100%" stop-color="#01b964" />
                                </radialGradient>
                                <filter id="accel-kz-blur" x="-50%" y="-50%" width="200%" height="200%">
                                    <feGaussianBlur stdDeviation="2.5" />
                                </filter>
                            </defs>

                            <path class="kz-map-shape" :d="kzCountryOutlinePath" />

                            <g
                                v-for="(marker, index) in majorMarkers"
                                :key="`major-${index}`"
                                class="kz-marker kz-marker--major kz-marker--pulse"
                                :transform="`translate(${marker.cx}, ${marker.cy})`"
                            >
                                <circle
                                    class="kz-marker__halo"
                                    r="14"
                                    :style="{ animationDelay: `${index * 0.6}s` }"
                                />
                                <circle class="kz-marker__ring" r="6" />
                                <circle class="kz-marker__core" r="3" />
                            </g>

                            <g
                                v-for="(marker, index) in minorMarkers"
                                :key="`minor-${index}`"
                                class="kz-marker kz-marker--minor"
                                :transform="`translate(${marker.cx}, ${marker.cy})`"
                            >
                                <circle class="kz-marker__halo kz-marker__halo--minor" r="8" />
                                <circle class="kz-marker__ring kz-marker__ring--minor" r="3.5" />
                                <circle class="kz-marker__core kz-marker__core--minor" r="1.75" />
                            </g>
                        </svg>
                        <p class="kz-map-caption">
                            <span class="kz-map-caption__dot" aria-hidden="true" />
                            {{ mapCaption }}
                        </p>
                    </div>
                </div>

                <blockquote class="landing-data-kz__law">
                    <span class="landing-data-kz__law-badge">{{ lawBadge }}</span>
                    <p>{{ lawQuote }}</p>
                    <p class="landing-data-kz__law-note">{{ lawFine }}</p>
                    <div class="landing-data-kz__law-links">
                        <a href="https://adilet.zan.kz/rus/docs/Z1300000094" target="_blank" rel="noopener noreferrer">
                            {{ lawLinkPd }}
                        </a>
                        <a href="https://adilet.zan.kz/rus/docs/K1400000235" target="_blank" rel="noopener noreferrer">
                            {{ lawLinkCoap }}
                        </a>
                    </div>
                </blockquote>
            </div>

            <div class="landing-data-kz__narrative landing-data-kz__card">
                <header class="landing__section-header">
                    <p class="landing__section-eyebrow">{{ eyebrow }}</p>
                    <h2 class="landing__section-heading">{{ title }}</h2>
                    <p class="landing__section-lead">{{ lead }}</p>
                </header>
                <p class="landing-data-kz__note">{{ note }}</p>
            </div>

            <div class="landing-data-kz__trust landing-data-kz__card">
                <ul class="landing-data-kz__checklist" role="list">
                    <li v-for="point in dataPoints" :key="point">
                        <span class="landing-data-kz__check-icon" aria-hidden="true">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8.5l3 3 7-7" />
                            </svg>
                        </span>
                        <span>{{ point }}</span>
                    </li>
                </ul>
                <div class="landing-data-kz__pills">
                    <span class="landing-data-kz__pill">{{ pillMade }}</span>
                    <span class="landing-data-kz__pill">{{ pillData }}</span>
                    <span class="landing-data-kz__pill">{{ pillSupport }}</span>
                </div>
            </div>

            <div class="landing-data-kz__security landing-data-kz__card">
                <p class="landing-data-kz__security-lead">{{ fitLead }}</p>
                <h3 class="landing-data-kz__security-heading">{{ securityHeading }}</h3>
                <ul class="landing-data-kz__security-list" role="list">
                    <li v-for="(item, idx) in securityList" :key="idx">
                        <span class="landing-data-kz__check-icon" aria-hidden="true">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8.5l3 3 7-7" />
                            </svg>
                        </span>
                        <span>{{ item }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>

<style scoped>
.landing-data-kz {
    scroll-margin-top: 5rem;
}

.landing-data-kz__shell {
    display: grid;
    gap: 1.75rem;
}

@media (min-width: 900px) {
    .landing-data-kz__shell {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto auto;
        gap: 1.75rem;
    }

    .landing-data-kz__highlight {
        grid-column: 1;
        grid-row: 1 / 3;
    }

    .landing-data-kz__narrative {
        grid-column: 2;
        grid-row: 1;
    }

    .landing-data-kz__trust {
        grid-column: 2;
        grid-row: 2;
    }

    .landing-data-kz__security {
        grid-column: 1 / -1;
    }
}

.landing-data-kz__highlight {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.landing-data-kz__card {
    padding: 1.5rem 1.6rem;
    border-radius: 16px;
    border: 1px solid var(--landing-border-bright);
    background: linear-gradient(160deg, var(--landing-card-top), var(--landing-card));
    box-shadow: var(--landing-elevation);
    transition:
        transform 0.22s ease,
        box-shadow 0.22s ease;
}

.landing-data-kz__card:hover {
    transform: translateY(-2px);
    box-shadow:
        var(--landing-elevation),
        0 0 0 1px rgba(1, 185, 100, 0.08);
}

.landing-data-kz__map-tile {
    position: relative;
    overflow: hidden;
    padding: 1.75rem 1.5rem 1.35rem;
    border-radius: 16px;
    border: 1px solid var(--landing-border-bright);
    background:
        radial-gradient(120% 120% at 50% 20%, rgba(1, 185, 100, 0.12), transparent 70%),
        linear-gradient(160deg, var(--landing-card-top), var(--landing-card));
    box-shadow: var(--landing-elevation);
}

.landing-data-kz__map-tile::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
}

.kz-map-block {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1rem;
}

.kz-map-svg {
    width: 100%;
    max-width: 340px;
    height: auto;
}

.kz-map-shape {
    fill: rgba(1, 185, 100, 0.05);
    stroke: url(#accel-kz-stroke);
    stroke-width: 1.5;
    stroke-linejoin: round;
}

.kz-marker__halo {
    fill: url(#accel-kz-halo);
    opacity: 0.5;
    filter: url(#accel-kz-blur);
}

.kz-marker__halo--minor {
    opacity: 0.28;
    filter: url(#accel-kz-blur);
}

.kz-marker__ring {
    fill: none;
    stroke: rgba(1, 185, 100, 0.65);
    stroke-width: 1;
}

.kz-marker__ring--minor {
    stroke: rgba(1, 185, 100, 0.4);
    stroke-width: 0.75;
}

.kz-marker__core {
    fill: url(#accel-kz-core);
}

.kz-marker__core--minor {
    fill: rgba(6, 214, 112, 0.85);
    opacity: 0.75;
}

.kz-marker--pulse .kz-marker__halo {
    transform-box: fill-box;
    transform-origin: center;
    animation: kz-marker-pulse 2.8s ease-in-out infinite;
}

@keyframes kz-marker-pulse {
    0%,
    100% {
        opacity: 0.35;
        transform: scale(0.92);
    }

    50% {
        opacity: 0.65;
        transform: scale(1.08);
    }
}

.kz-map-caption {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    margin: 0;
    font-size: 0.9375rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    color: var(--landing-text);
    text-align: center;
}

.kz-map-caption__dot {
    flex-shrink: 0;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--landing-accent);
    box-shadow: 0 0 10px rgba(1, 185, 100, 0.65);
}

.landing-data-kz__law {
    margin: 0;
    padding: 1.5rem 1.6rem;
    border-radius: 16px;
    border: 1px solid rgba(1, 185, 100, 0.28);
    background:
        linear-gradient(180deg, rgba(1, 185, 100, 0.12) 0%, transparent 40%),
        linear-gradient(160deg, var(--landing-card-top), var(--landing-card));
    box-shadow: var(--landing-elevation);
}

.landing-data-kz__law-badge {
    display: inline-block;
    margin-bottom: 0.75rem;
    padding: 0.25rem 0.65rem;
    border-radius: 999px;
    background: rgba(1, 185, 100, 0.18);
    color: var(--landing-accent);
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.landing-data-kz__law p {
    margin: 0 0 0.65rem;
    font-size: 0.875rem;
    line-height: 1.55;
    color: var(--landing-text);
}

.landing-data-kz__law-note {
    color: var(--landing-muted) !important;
    font-size: 0.8125rem !important;
}

.landing-data-kz__law-links {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1rem;
    margin-top: 0.5rem;
}

.landing-data-kz__law-links a {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--landing-accent);
    text-decoration: none;
}

.landing-data-kz__law-links a:hover {
    color: var(--landing-accent-hover);
    text-decoration: underline;
}

.landing-data-kz__narrative .landing__section-header {
    margin-bottom: 1rem;
}

.landing-data-kz__note {
    margin: 0;
    font-size: 0.96875rem;
    line-height: 1.6;
    color: var(--landing-muted);
}

.landing-data-kz__checklist,
.landing-data-kz__security-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.landing-data-kz__checklist li,
.landing-data-kz__security-list li {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    font-size: 0.875rem;
    line-height: 1.55;
    color: var(--landing-text);
}

.landing-data-kz__check-icon {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.35rem;
    height: 1.35rem;
    margin-top: 0.05rem;
    border-radius: 50%;
    background: rgba(1, 185, 100, 0.14);
    color: var(--landing-accent);
    box-shadow: 0 0 0 1px rgba(1, 185, 100, 0.2);
}

.landing-data-kz__check-icon svg {
    width: 0.75rem;
    height: 0.75rem;
}

.landing-data-kz__pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    margin-top: 1.25rem;
}

.landing-data-kz__pill {
    padding: 0.4rem 0.85rem;
    border-radius: 999px;
    border: 1px solid rgba(1, 185, 100, 0.32);
    background: rgba(1, 185, 100, 0.08);
    color: var(--landing-accent);
    font-size: 0.75rem;
    font-weight: 600;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.landing-data-kz__security-lead {
    margin: 0 0 0.85rem;
    font-size: 0.96875rem;
    line-height: 1.6;
    color: var(--landing-muted);
}

.landing-data-kz__security-heading {
    margin: 0 0 1rem;
    font-size: 1.0625rem;
    font-weight: 600;
    color: var(--landing-text);
}

@media (prefers-reduced-motion: reduce) {
    .kz-marker--pulse .kz-marker__halo {
        animation: none;
    }

    .landing-data-kz__card:hover {
        transform: none;
    }
}
</style>
