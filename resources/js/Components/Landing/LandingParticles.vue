<script setup lang="ts">
/**
 * WebGL particles background — adapted from Vue Bits (MIT)
 * https://vue-bits.dev/backgrounds/particles
 */
import { nextTick, onMounted, onUnmounted, ref, useTemplateRef, watch } from 'vue';
import { Camera, Geometry, Mesh, Program, Renderer } from 'ogl';

interface ParticlesProps {
    particleCount?: number;
    particleSpread?: number;
    speed?: number;
    particleColors?: string[];
    moveParticlesOnHover?: boolean;
    particleHoverFactor?: number;
    alphaParticles?: boolean;
    particleBaseSize?: number;
    sizeRandomness?: number;
    cameraDistance?: number;
    disableRotation?: boolean;
}

const props = withDefaults(defineProps<ParticlesProps>(), {
    particleCount: 300,
    particleSpread: 10,
    speed: 0.1,
    particleColors: () => ['#ffffff'],
    moveParticlesOnHover: true,
    particleHoverFactor: 1,
    alphaParticles: true,
    particleBaseSize: 200,
    sizeRandomness: 1,
    cameraDistance: 20,
    disableRotation: true,
});

const containerRef = useTemplateRef<HTMLDivElement>('containerRef');
const mouseRef = ref({ x: 0, y: 0 });
const prefersReducedMotion = ref(false);
const hoverCapable = ref(false);

let renderer: Renderer | null = null;
let camera: Camera | null = null;
let particles: Mesh | null = null;
let program: Program | null = null;
let animationFrameId: number | null = null;
let teardown: (() => void) | null = null;
let lastTime = 0;
let elapsed = 0;

const defaultColors = ['#ffffff', '#ffffff', '#ffffff'];

const hexToRgb = (hex: string): [number, number, number] => {
    let normalized = hex.replace(/^#/, '');
    if (normalized.length === 3) {
        normalized = normalized
            .split('')
            .map((c) => c + c)
            .join('');
    }
    const int = parseInt(normalized, 16);
    const r = ((int >> 16) & 255) / 255;
    const g = ((int >> 8) & 255) / 255;
    const b = (int & 255) / 255;
    return [r, g, b];
};

const vertex = /* glsl */ `
  attribute vec3 position;
  attribute vec4 random;
  attribute vec3 color;

  uniform mat4 modelMatrix;
  uniform mat4 viewMatrix;
  uniform mat4 projectionMatrix;
  uniform float uTime;
  uniform float uSpread;
  uniform float uBaseSize;
  uniform float uSizeRandomness;

  varying vec4 vRandom;
  varying vec3 vColor;

  void main() {
    vRandom = random;
    vColor = color;

    vec3 pos = position * uSpread;
    pos.z *= 10.0;

    vec4 mPos = modelMatrix * vec4(pos, 1.0);
    float t = uTime;
    mPos.x += sin(t * random.z + 6.28 * random.w) * mix(0.1, 1.5, random.x);
    mPos.y += sin(t * random.y + 6.28 * random.x) * mix(0.1, 1.5, random.w);
    mPos.z += sin(t * random.w + 6.28 * random.y) * mix(0.1, 1.5, random.z);

    vec4 mvPos = viewMatrix * mPos;
    gl_PointSize = (uBaseSize * (1.0 + uSizeRandomness * (random.x - 0.5))) / length(mvPos.xyz);
    gl_Position = projectionMatrix * mvPos;
  }
`;

const fragment = /* glsl */ `
  precision highp float;

  uniform float uTime;
  uniform float uAlphaParticles;
  varying vec4 vRandom;
  varying vec3 vColor;

  void main() {
    vec2 uv = gl_PointCoord.xy;
    float d = length(uv - vec2(0.5));

    if(uAlphaParticles < 0.5) {
      if(d > 0.5) {
        discard;
      }
      gl_FragColor = vec4(vColor + 0.2 * sin(uv.yxx + uTime + vRandom.y * 6.28), 1.0);
    } else {
      float circle = smoothstep(0.5, 0.4, d) * 0.8;
      gl_FragColor = vec4(vColor + 0.2 * sin(uv.yxx + uTime + vRandom.y * 6.28), circle);
    }
  }
`;

function handleMouseMove(e: MouseEvent): void {
    const container = containerRef.value;
    if (!container) {
        return;
    }

    const rect = container.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
    const y = -(((e.clientY - rect.top) / rect.height) * 2 - 1);
    mouseRef.value = { x, y };
}

function initParticles(): void {
    const container = containerRef.value;
    if (!container || prefersReducedMotion.value) {
        return;
    }

    renderer = new Renderer({ depth: false, alpha: true });
    const gl = renderer.gl;
    container.appendChild(gl.canvas);
    gl.clearColor(0, 0, 0, 0);

    gl.canvas.style.width = '100%';
    gl.canvas.style.height = '100%';
    gl.canvas.style.display = 'block';
    gl.canvas.style.position = 'absolute';
    gl.canvas.style.top = '0';
    gl.canvas.style.left = '0';
    gl.canvas.style.pointerEvents = 'none';

    camera = new Camera(gl, { fov: 15 });
    camera.position.set(0, 0, props.cameraDistance);

    const resize = (): void => {
        if (!container) {
            return;
        }

        const parentWidth = container.parentElement?.offsetWidth || container.offsetWidth || window.innerWidth;
        const parentHeight = container.parentElement?.offsetHeight || container.offsetHeight || window.innerHeight;

        const width = Math.max(parentWidth, 300);
        const height = Math.max(parentHeight, 300);

        renderer!.setSize(width, height);
        camera!.perspective({ aspect: width / height });
    };

    window.addEventListener('resize', resize, false);
    resize();

    const hoverEnabled = props.moveParticlesOnHover && hoverCapable.value;
    if (hoverEnabled) {
        container.addEventListener('mousemove', handleMouseMove);
    }

    const count = props.particleCount;
    const positions = new Float32Array(count * 3);
    const randoms = new Float32Array(count * 4);
    const colors = new Float32Array(count * 3);
    const palette = props.particleColors.length > 0 ? props.particleColors : defaultColors;

    for (let i = 0; i < count; i++) {
        let x: number;
        let y: number;
        let z: number;
        let len: number;
        do {
            x = Math.random() * 2 - 1;
            y = Math.random() * 2 - 1;
            z = Math.random() * 2 - 1;
            len = x * x + y * y + z * z;
        } while (len > 1 || len === 0);
        const r = Math.cbrt(Math.random());
        positions.set([x * r, y * r, z * r], i * 3);
        randoms.set([Math.random(), Math.random(), Math.random(), Math.random()], i * 4);
        const col = hexToRgb(palette[Math.floor(Math.random() * palette.length)]);
        colors.set(col, i * 3);
    }

    const geometry = new Geometry(gl, {
        position: { size: 3, data: positions },
        random: { size: 4, data: randoms },
        color: { size: 3, data: colors },
    });

    program = new Program(gl, {
        vertex,
        fragment,
        uniforms: {
            uTime: { value: 0 },
            uSpread: { value: props.particleSpread },
            uBaseSize: { value: props.particleBaseSize },
            uSizeRandomness: { value: props.sizeRandomness },
            uAlphaParticles: { value: props.alphaParticles ? 1 : 0 },
        },
        transparent: true,
        depthTest: false,
    });

    particles = new Mesh(gl, { mode: gl.POINTS, geometry, program });

    lastTime = performance.now();
    elapsed = 0;

    const update = (t: number): void => {
        if (!animationFrameId) {
            return;
        }
        animationFrameId = requestAnimationFrame(update);
        const delta = t - lastTime;
        lastTime = t;
        elapsed += delta * props.speed;

        if (program) {
            program.uniforms.uTime.value = elapsed * 0.001;
            program.uniforms.uSpread.value = props.particleSpread;
            program.uniforms.uBaseSize.value = props.particleBaseSize;
            program.uniforms.uSizeRandomness.value = props.sizeRandomness;
            program.uniforms.uAlphaParticles.value = props.alphaParticles ? 1 : 0;
        }

        if (particles) {
            if (hoverEnabled) {
                particles.position.x = -mouseRef.value.x * props.particleHoverFactor;
                particles.position.y = -mouseRef.value.y * props.particleHoverFactor;
            } else {
                particles.position.x = 0;
                particles.position.y = 0;
            }

            if (!props.disableRotation) {
                particles.rotation.x = Math.sin(elapsed * 0.0002) * 0.1;
                particles.rotation.y = Math.cos(elapsed * 0.0005) * 0.15;
                particles.rotation.z += 0.01 * props.speed;
            }
        }

        if (renderer && camera && particles) {
            renderer.render({ scene: particles, camera });
        }
    };

    animationFrameId = requestAnimationFrame(update);

    teardown = (): void => {
        window.removeEventListener('resize', resize);
        if (hoverEnabled) {
            container.removeEventListener('mousemove', handleMouseMove);
        }
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
            animationFrameId = null;
        }
        if (container.contains(gl.canvas)) {
            container.removeChild(gl.canvas);
        }
    };
}

function cleanup(): void {
    teardown?.();
    teardown = null;

    if (renderer) {
        renderer.gl.getExtension('WEBGL_lose_context')?.loseContext();
    }

    renderer = null;
    camera = null;
    particles = null;
    program = null;
}

function boot(): void {
    cleanup();
    if (!prefersReducedMotion.value) {
        initParticles();
    }
}

onMounted(async () => {
    prefersReducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    hoverCapable.value = window.matchMedia('(hover: hover)').matches;
    await nextTick();
    boot();
});

onUnmounted(() => {
    cleanup();
});

watch(
    () => [props.particleCount, props.particleColors],
    () => {
        boot();
    },
    { deep: true },
);
</script>

<template>
    <div
        ref="containerRef"
        class="landing-particles"
        :class="{ 'landing-particles--static': prefersReducedMotion }"
    />
</template>

<style scoped>
.landing-particles {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.landing-particles--static {
    display: none;
}

:deep(canvas) {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
}
</style>
