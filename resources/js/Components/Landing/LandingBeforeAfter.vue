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
    <section id="problem" class="landing-before-after">
        <div class="landing-before-after__intro">
            <h2 class="landing__section-title landing-before-after__title">{{ title }}</h2>
            <p class="landing-before-after__lead">{{ lead }}</p>
        </div>

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
    margin-bottom: 3.5rem;
}

.landing-before-after__intro {
    max-width: 42rem;
    margin: 0 auto 2rem;
    text-align: center;
}

.landing-before-after__title {
    margin-bottom: 0.75rem;
}

.landing-before-after__lead {
    margin: 0;
    font-size: 1rem;
    line-height: 1.6;
    color: var(--landing-muted);
}

.landing-before-after__layout {
    display: grid;
    gap: 2rem;
}

@media (min-width: 1024px) {
    .landing-before-after__layout {
        grid-template-columns: minmax(0, 1.35fr) minmax(0, 0.65fr);
        align-items: start;
        gap: 2.5rem;
    }
}

.landing-before-after__grid {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.landing-before-after__row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

@media (max-width: 640px) {
    .landing-before-after__row {
        grid-template-columns: 1fr;
    }
}

.landing-before-after__cell {
    padding: 1rem 1.1rem;
    border-radius: 12px;
    border: 1px solid var(--landing-border);
    min-height: 7.5rem;
}

.landing-before-after__cell--before {
    background: rgba(239, 68, 68, 0.06);
    border-color: rgba(239, 68, 68, 0.22);
}

.landing-before-after__cell--after {
    background: rgba(1, 185, 100, 0.07);
    border-color: rgba(1, 185, 100, 0.28);
}

.landing-before-after__label {
    margin: 0 0 0.5rem;
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
    font-size: 0.9375rem;
    font-weight: 500;
    line-height: 1.45;
    color: var(--landing-text);
}

.landing-before-after__aside {
    padding: 1.25rem 1.35rem;
    border-radius: 12px;
    border: 1px solid var(--landing-border);
    background: var(--landing-surface);
}

.landing-before-after__aside-title {
    margin: 0 0 0.65rem;
    font-size: 1.0625rem;
    font-weight: 600;
    line-height: 1.35;
    color: var(--landing-text);
}

.landing-before-after__aside-lead {
    margin: 0 0 1rem;
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
    gap: 0.65rem;
}

.landing-before-after__aside-list li {
    position: relative;
    padding-left: 1.1rem;
    font-size: 0.875rem;
    line-height: 1.5;
    color: var(--landing-muted);
}

.landing-before-after__aside-list li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.55em;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--landing-accent);
}

/* Mini chat decorations */
.ba-mini {
    margin-bottom: 0.75rem;
    padding: 0.5rem 0.6rem;
    border-radius: 8px;
    font-size: 0.6875rem;
}

.ba-mini--pain0,
.ba-mini--pain2,
.ba-mini--pain3 {
    background: rgba(0, 0, 0, 0.25);
    color: var(--landing-muted);
}

.ba-mini--ok0,
.ba-mini--ok2,
.ba-mini--ok3 {
    background: rgba(1, 185, 100, 0.12);
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
    background: var(--landing-accent);
    width: 75%;
    margin-bottom: 0.35rem;
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
}

.ba-ok3-arrow {
    color: var(--landing-muted);
}
</style>
