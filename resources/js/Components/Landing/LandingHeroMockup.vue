<script setup lang="ts">
const desktopScreenshot = '/landing/screenshots/desktop-app.png';
const mobileScreenshot = '/landing/screenshots/mobile-app.png';
</script>

<template>
    <div class="hero-mockup-root" aria-hidden="true">
        <div class="hero-mockup-devices hero-mockup-devices--desktop">
            <div class="hero-device hero-device--desktop">
                <div class="hero-device__shell hero-device__shell--macbook">
                    <div class="hero-device__screen hero-device__screen--macbook">
                        <div class="hero-device__ui-scale hero-device__ui-scale--macbook">
                            <img
                                class="hero-device__screenshot hero-device__screenshot--macbook"
                                :src="desktopScreenshot"
                                alt=""
                                width="3680"
                                height="2387"
                                decoding="async"
                            />
                        </div>
                    </div>
                    <div class="hero-device__macbook-base-fill" aria-hidden="true" />
                    <img
                        class="hero-device__frame"
                        src="/landing/frames/macbook-frame.png"
                        width="656"
                        height="380"
                        alt=""
                        decoding="async"
                    />
                </div>

                <div class="hero-device hero-device--companion hero-device--companion-overlap">
                    <div class="hero-device__shell hero-device__shell--companion">
                        <div class="hero-device__screen hero-device__screen--iphone">
                            <div class="hero-device__ui-scale hero-device__ui-scale--iphone">
                                <img
                                    class="hero-device__screenshot hero-device__screenshot--iphone"
                                    :src="mobileScreenshot"
                                    alt=""
                                    width="428"
                                    height="872"
                                    decoding="async"
                                />
                            </div>
                        </div>
                        <img
                            class="hero-device__frame"
                            src="/landing/frames/iphone-frame.png"
                            width="1200"
                            height="1200"
                            alt=""
                            decoding="async"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div class="hero-device hero-device--mobile">
            <div class="hero-device__shell hero-device__shell--phone">
                <div class="hero-device__screen hero-device__screen--iphone">
                    <div class="hero-device__ui-scale hero-device__ui-scale--iphone">
                        <img
                            class="hero-device__screenshot hero-device__screenshot--iphone"
                            :src="mobileScreenshot"
                            alt=""
                            width="428"
                            height="872"
                            decoding="async"
                        />
                    </div>
                </div>
                <img
                    class="hero-device__frame"
                    src="/landing/frames/iphone-frame.png"
                    width="1200"
                    height="1200"
                    alt=""
                    decoding="async"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.hero-mockup-root {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: min(100%, min(2000px, 105vw));
    margin-left: auto;
    margin-right: auto;
    transform: rotate(0.9deg);
    transition: transform 0.22s ease;
}

.hero-mockup-root:hover {
    transform: rotate(0.35deg) translateY(-3px);
}

@media (min-width: 1024px) {
    .hero-mockup-root {
        max-width: min(100%, min(2400px, 104vw));
    }
}

.hero-mockup-devices--desktop {
    display: none;
}

.hero-device {
    width: 100%;
}

.hero-device__shell {
    position: relative;
    display: block;
    width: 100%;
    isolation: isolate;
}

.hero-device--mobile {
    display: block;
}

.hero-device__shell--phone {
    max-width: min(340px, 92vw);
    margin-left: auto;
    margin-right: auto;
}

@media (max-width: 359px) {
    .hero-device__shell--phone {
        max-width: min(252px, 92vw);
    }
}

@media (min-width: 700px) and (min-height: 480px) {
    .hero-mockup-devices--desktop {
        display: flex;
        width: 100%;
        align-items: flex-end;
        justify-content: center;
        overflow: visible;
    }

    .hero-device--desktop {
        position: relative;
        z-index: 1;
        flex: 1 1 auto;
        min-width: 0;
        max-width: 100%;
        overflow: visible;
    }

    .hero-device--desktop .hero-device--companion-overlap {
        position: absolute;
        z-index: 5;
        right: clamp(-6.5rem, -14%, -0.5rem);
        bottom: clamp(-4.95rem, -3.7vw, -0.56rem);
        width: clamp(260px, 28vw, 380px);
        max-width: min(58%, 380px);
        margin: 0;
        pointer-events: none;
        filter: drop-shadow(0 14px 30px rgb(0 0 0 / 0.45));
    }

    .hero-device--mobile {
        display: none;
    }
}

@media (min-width: 700px) and (max-width: 1023px) and (min-height: 480px) {
    .hero-mockup-root {
        transform: rotate(0.9deg) scale(0.94);
        transform-origin: 50% 88%;
    }

    .hero-mockup-root:hover {
        transform: rotate(0.35deg) translateY(-3px) scale(0.94);
    }
}

.hero-device__frame {
    position: relative;
    z-index: 2;
    display: block;
    width: 100%;
    height: auto;
    pointer-events: none;
}

.hero-device__screen {
    position: absolute;
    z-index: 1;
    overflow: hidden;
    background: #000;
    pointer-events: none;
}

.hero-device__screen--macbook {
    top: 0.55%;
    left: 10.27%;
    right: 10.29%;
    bottom: 10.33%;
    border-radius: 0.82%;
    container-type: size;
}

.hero-device__screen--iphone {
    top: 13.67%;
    right: 31.95%;
    bottom: 13.55%;
    left: 31.95%;
    border-radius: 12.85% / 6.31%;
    container-type: size;
}

.hero-device__macbook-base-fill {
    position: absolute;
    z-index: 1;
    bottom: 0;
    left: 50%;
    width: clamp(48px, 15.5%, 104px);
    height: clamp(14px, 6.8%, 30px);
    transform: translateX(-50%);
    border-radius: 0 0 999px 999px;
    background: #b4b9c0;
    pointer-events: none;
}

.hero-device__ui-scale--macbook {
    position: absolute;
    top: 0;
    left: 0;
    width: 860px;
    height: calc(860px * 2387 / 3680);
    transform-origin: 0 0;
    transform: scale(calc(100cqw / 860px));
}

.hero-device__ui-scale--iphone {
    position: absolute;
    top: 0;
    left: 0;
    width: 248px;
    height: calc(248px * 872 / 428);
    transform-origin: 0 0;
    transform: scale(calc(100cqw / 248px));
}

.hero-device__screenshot {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top left;
}

.hero-device__screenshot--macbook {
    object-position: center top;
    transform: scale(1.012) translateY(-0.6%);
    transform-origin: top center;
}

.hero-device__screenshot--iphone {
    object-fit: cover;
    object-position: center center;
}
</style>
