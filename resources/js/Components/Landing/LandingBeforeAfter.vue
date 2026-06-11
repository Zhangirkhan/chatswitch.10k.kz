<script setup lang="ts">
export type BeforeAfterRow = {
    before: string;
    after: string;
};

defineProps<{
    title: string;
    lead: string;
    rows: readonly BeforeAfterRow[];
    baPain0Unread: string;
    baPain0Wait: string;
    baPain2Muted: string;
    baPain3Label: string;
    baLabelBefore: string;
    baLabelAfter: string;
    baAfter0a: string;
    baAfter0b: string;
    baAfter1Dialogs: string;
    baAfter1Min: string;
    baAfter1Window: string;
    asideAria: string;
    asideTitle: string;
    asideLead: string;
    asidePoints: readonly string[];
}>();
</script>

<template>
    <section id="problem" class="landing-before-after landing__section-block">
        <header class="landing__section-header">
            <p class="landing__section-eyebrow">{{ baLabelBefore }} / {{ baLabelAfter }}</p>
            <h2 class="landing__section-heading">{{ title }}</h2>
            <p class="landing__section-lead">{{ lead }}</p>
        </header>

        <div class="landing-before-after__layout">
            <div class="landing-before-after__grid">
                <article
                    v-for="(row, rowIdx) in rows"
                    :key="row.before"
                    class="landing-before-after__row"
                >
                    <div class="landing-before-after__cell landing-before-after__cell--before">
                        <div v-if="rowIdx === 0" class="ba-mini ba-mini--pain0" aria-hidden="true">
                            <div class="ba-pain0-row">
                                <span class="ba-pain0-avatar" />
                                <span class="ba-pain0-lines" />
                            </div>
                            <span class="ba-pain0-unread">{{ baPain0Unread }}</span>
                            <span class="ba-pain0-wait">{{ baPain0Wait }}</span>
                        </div>
                        <div v-else-if="rowIdx === 1" class="ba-mini ba-mini--pain2" aria-hidden="true">
                            <span class="ba-pain2-muted">{{ baPain2Muted }}</span>
                        </div>
                        <div v-else-if="rowIdx === 2" class="ba-mini ba-mini--pain3" aria-hidden="true">
                            <span class="ba-pain3-avatar" />
                            <span class="ba-pain3-label">{{ baPain3Label }}</span>
                        </div>
                        <p class="landing-before-after__label landing-before-after__label--pain">{{ baLabelBefore }}</p>
                        <h3 class="landing-before-after__heading">{{ row.before }}</h3>
                    </div>

                    <div class="landing-before-after__cell landing-before-after__cell--after">
                        <div v-if="rowIdx === 0" class="ba-mini ba-mini--ok0" aria-hidden="true">
                            <div class="ba-ok0-bar"><span /></div>
                            <div class="ba-ok0-meta">
                                <span>{{ baAfter0a }}</span>
                                <span>{{ baAfter0b }}</span>
                            </div>
                        </div>
                        <div v-else-if="rowIdx === 1" class="ba-mini ba-mini--ok2" aria-hidden="true">
                            <div class="ba-ok2-kpi">
                                <strong>47</strong>
                                <small>{{ baAfter1Dialogs }}</small>
                            </div>
                            <div class="ba-ok2-kpi">
                                <strong>3.2</strong>
                                <small>{{ baAfter1Min }}</small>
                            </div>
                            <div class="ba-ok2-kpi">
                                <strong>1</strong>
                                <small>{{ baAfter1Window }}</small>
                            </div>
                        </div>
                        <div v-else-if="rowIdx === 2" class="ba-mini ba-mini--ok3" aria-hidden="true">
                            <span class="ba-ok3-av">M</span>
                            <span class="ba-ok3-arrow" aria-hidden="true">→</span>
                            <span class="ba-ok3-av ba-ok3-av--new">A</span>
                        </div>
                        <p class="landing-before-after__label landing-before-after__label--solution">{{ baLabelAfter }}</p>
                        <h3 class="landing-before-after__heading">{{ row.after }}</h3>
                    </div>
                </article>
            </div>

            <aside class="landing-before-after__aside" :aria-label="asideAria">
                <h3 class="landing-before-after__aside-title">{{ asideTitle }}</h3>
                <p class="landing-before-after__aside-lead">{{ asideLead }}</p>
                <ul class="landing-before-after__aside-list" role="list">
                    <li v-for="(line, idx) in asidePoints" :key="idx">{{ line }}</li>
                </ul>
            </aside>
        </div>
    </section>
</template>

<style scoped>
.landing-before-after {
    scroll-margin-top: 5rem;
}

.landing-before-after__layout {
    display: grid;
    gap: 2.5rem;
}

@media (min-width: 1024px) {
    .landing-before-after__layout {
        grid-template-columns: minmax(0, 1.35fr) minmax(0, 0.65fr);
        align-items: start;
        gap: 3rem;
    }
}

.landing-before-after__grid {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.landing-before-after__row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

@media (max-width: 640px) {
    .landing-before-after__row {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    .landing-before-after__cell {
        padding: 1rem 1.1rem;
        min-height: 6rem;
    }
}

@media (hover: none) {
    .landing-before-after__cell:hover,
    .landing-before-after__cell--after:hover {
        transform: none;
        box-shadow: var(--landing-elevation);
    }

    .landing-before-after__cell--after:hover {
        box-shadow:
            var(--landing-elevation),
            0 0 0 1px rgba(1, 185, 100, 0.08),
            0 12px 32px -18px rgba(1, 185, 100, 0.35);
    }
}

.landing-before-after__cell {
    padding: 1.35rem 1.45rem;
    border-radius: var(--landing-radius);
    border: 1px solid var(--landing-border-bright);
    min-height: 7.5rem;
    box-shadow: var(--landing-elevation);
    transition:
        transform 0.22s ease,
        box-shadow 0.22s ease;
}

.landing-before-after__cell:hover {
    transform: translateY(-2px);
}

.landing-before-after__cell--before {
    background:
        linear-gradient(180deg, rgba(239, 68, 68, 0.14) 0%, transparent 28%),
        linear-gradient(160deg, var(--landing-card-top), var(--landing-card));
    border-color: rgba(239, 68, 68, 0.28);
}

.landing-before-after__cell--after {
    background:
        linear-gradient(180deg, rgba(1, 185, 100, 0.16) 0%, transparent 32%),
        linear-gradient(160deg, rgba(38, 43, 50, 1), rgba(32, 36, 42, 1));
    border-color: rgba(1, 185, 100, 0.32);
    box-shadow:
        var(--landing-elevation),
        0 0 0 1px rgba(1, 185, 100, 0.08),
        0 12px 32px -18px rgba(1, 185, 100, 0.35);
}

.landing-before-after__cell--after:hover {
    box-shadow:
        var(--landing-elevation),
        var(--landing-glow);
}

.landing-before-after__label {
    margin: 0 0 0.55rem;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.landing-before-after__label--pain {
    color: #f87171;
}

.landing-before-after__label--solution {
    color: var(--landing-accent);
}

.landing-before-after__heading {
    margin: 0;
    font-size: 0.96875rem;
    font-weight: 500;
    line-height: 1.5;
    color: var(--landing-text);
}

.landing-before-after__aside {
    padding: 1.5rem 1.6rem;
    border-radius: var(--landing-radius);
    border: 1px solid var(--landing-border-bright);
    background: linear-gradient(160deg, var(--landing-card-top), var(--landing-card));
    box-shadow: var(--landing-elevation);
    transition:
        transform 0.22s ease,
        box-shadow 0.22s ease;
}

.landing-before-after__aside:hover {
    transform: translateY(-2px);
    box-shadow:
        var(--landing-elevation),
        0 0 0 1px rgba(1, 185, 100, 0.1);
}

.landing-before-after__aside-title {
    margin: 0 0 0.75rem;
    font-size: 1.125rem;
    font-weight: 600;
    line-height: 1.35;
    color: var(--landing-text);
}

.landing-before-after__aside-lead {
    margin: 0 0 1.15rem;
    font-size: 0.9375rem;
    line-height: 1.55;
    color: var(--landing-muted);
}

.landing-before-after__aside-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.landing-before-after__aside-list li {
    position: relative;
    padding-left: 1.15rem;
    font-size: 0.875rem;
    line-height: 1.55;
    color: var(--landing-muted);
}

.landing-before-after__aside-list li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.55em;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--landing-accent);
    box-shadow: 0 0 8px rgba(1, 185, 100, 0.55);
}

/* Mini chat decorations */
.ba-mini {
    margin-bottom: 0.85rem;
    padding: 0.6rem 0.75rem;
    border-radius: 10px;
    font-size: 0.6875rem;
}

.ba-mini--pain0,
.ba-mini--pain2,
.ba-mini--pain3 {
    background: rgba(0, 0, 0, 0.22);
    border: 1px solid rgba(239, 68, 68, 0.12);
    color: var(--landing-muted);
}

.ba-mini--ok0,
.ba-mini--ok2,
.ba-mini--ok3 {
    background: rgba(1, 185, 100, 0.1);
    border: 1px solid rgba(1, 185, 100, 0.18);
    color: var(--landing-accent);
}

.ba-pain0-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-bottom: 0.35rem;
}

.ba-pain0-avatar {
    width: 1.25rem;
    height: 1.25rem;
    border-radius: 50%;
    background: rgba(134, 150, 160, 0.35);
}

.ba-pain0-lines {
    flex: 1;
    height: 0.35rem;
    border-radius: 999px;
    background: rgba(134, 150, 160, 0.25);
}

.ba-pain0-unread {
    display: inline-block;
    margin-right: 0.5rem;
    padding: 0.1rem 0.35rem;
    border-radius: 999px;
    background: rgba(239, 68, 68, 0.25);
    color: #fca5a5;
    font-weight: 600;
}

.ba-pain0-wait {
    color: #f87171;
}

.ba-pain2-muted {
    display: block;
    text-align: center;
    letter-spacing: 0.05em;
}

.ba-pain3-avatar {
    display: inline-block;
    width: 1.25rem;
    height: 1.25rem;
    margin-right: 0.4rem;
    border-radius: 50%;
    vertical-align: middle;
    background: rgba(134, 150, 160, 0.35);
}

.ba-pain3-label {
    vertical-align: middle;
}

.ba-ok0-bar span {
    display: block;
    height: 0.35rem;
    border-radius: 999px;
    background: linear-gradient(90deg, var(--landing-accent), var(--landing-accent-hover));
    width: 75%;
    margin-bottom: 0.35rem;
    box-shadow: 0 0 12px rgba(1, 185, 100, 0.45);
}

.ba-ok0-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.65rem;
    font-weight: 600;
}

.ba-mini--ok2 {
    display: flex;
    gap: 0.65rem;
}

.ba-ok2-kpi {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
}

.ba-ok2-kpi strong {
    font-size: 1rem;
    line-height: 1.2;
    color: var(--landing-text);
}

.ba-ok2-kpi small {
    font-size: 0.625rem;
    text-align: center;
    color: var(--landing-muted);
}

.ba-mini--ok3 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ba-ok3-av {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    background: rgba(1, 185, 100, 0.25);
    font-size: 0.6875rem;
    font-weight: 700;
    color: var(--landing-accent);
}

.ba-ok3-av--new {
    background: var(--landing-accent);
    color: #000;
    box-shadow: 0 0 14px rgba(1, 185, 100, 0.5);
}

.ba-ok3-arrow {
    color: var(--landing-muted);
}

@media (prefers-reduced-motion: reduce) {
    .landing-before-after__cell,
    .landing-before-after__aside {
        transition: none;
    }

    .landing-before-after__cell:hover,
    .landing-before-after__aside:hover {
        transform: none;
    }
}
</style>
