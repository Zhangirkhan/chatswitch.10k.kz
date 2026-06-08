#!/usr/bin/env node
/**
 * Export Accel «Диалог» app icons from SVG sources.
 * Requires: rsvg-convert (librsvg), ImageMagick `convert` + `identify`
 */
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const SOURCE = join(ROOT, 'source');
const IOS = join(ROOT, 'ios');
const ANDROID = join(ROOT, 'android');
const PREVIEW = join(ROOT, 'preview');

const SCALE = 1024 / 108;

function readSvg(name) {
    return readFileSync(join(SOURCE, name), 'utf8');
}

function compositeSvg(backgroundSvg, markSvg) {
    const bgInner = backgroundSvg.replace(/<\?xml[^?]*\?>\s*/i, '').replace(/<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '');
    const markInner = markSvg
        .replace(/<\?xml[^?]*\?>\s*/i, '')
        .replace(/<svg[^>]*>/, '')
        .replace(/<\/svg>\s*$/, '')
        .replace(/<defs>[\s\S]*?<\/defs>/g, (block) => block);

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 108 108" width="1024" height="1024">
${bgInner.trim()}
${markInner.trim()}
</svg>`;
}

function renderSvg(svgContent, outPath, size) {
    const tmpPath = `${outPath}.tmp.svg`;
    writeFileSync(tmpPath, svgContent);
    try {
        execFileSync('rsvg-convert', [
            '-w', String(size),
            '-h', String(size),
            '-o', outPath,
            tmpPath,
        ], { stdio: 'pipe' });
    } finally {
        try {
            execFileSync('rm', ['-f', tmpPath], { stdio: 'pipe' });
        } catch {
            // ignore
        }
    }
}

function pngHasAlpha(path) {
    const out = execFileSync('identify', ['-format', '%A', path], { encoding: 'utf8' }).trim();
    return out !== 'False' && out !== 'undefined';
}

function pngSize(path) {
    const out = execFileSync('identify', ['-format', '%w x %h', path], { encoding: 'utf8' }).trim();
    return out;
}

function ensureDirs() {
    for (const dir of [IOS, ANDROID, PREVIEW]) {
        if (!existsSync(dir)) {
            mkdirSync(dir, { recursive: true });
        }
    }
}

function exportIcons() {
    ensureDirs();

    const bgLight = readSvg('background-light.svg');
    const bgDark = readSvg('background-dark.svg');
    const markLight = readSvg('dialog-mark-light.svg');
    const markDark = readSvg('dialog-mark-dark.svg');
    const markTinted = readSvg('dialog-mark-tinted.svg');

    const lightComposite = compositeSvg(bgLight, markLight);
    const darkComposite = compositeSvg(bgDark, markDark);

    const iosLight = join(IOS, 'AppIcon-light@1024.png');
    const iosDark = join(IOS, 'AppIcon-dark@1024.png');
    const iosTinted = join(IOS, 'AppIcon-tinted@1024.png');
    const playStore = join(ANDROID, 'play-store-512.png');

    renderSvg(lightComposite, iosLight, 1024);
    renderSvg(darkComposite, iosDark, 1024);

    const tintedSvg = markTinted.replace(
        /viewBox="0 0 108 108" width="108" height="108"/,
        'viewBox="0 0 108 108" width="1024" height="1024"',
    );
    renderSvg(tintedSvg, iosTinted, 1024);
    renderSvg(lightComposite, playStore, 512);

    // Previews for QA
    renderSvg(lightComposite, join(PREVIEW, 'preview-light-1024.png'), 1024);
    renderSvg(lightComposite, join(PREVIEW, 'preview-light-60.png'), 60);
    renderSvg(darkComposite, join(PREVIEW, 'preview-dark-60.png'), 60);
    renderSvg(tintedSvg, join(PREVIEW, 'preview-tinted-60.png'), 60);

    // Validation
    const checks = [
        { path: iosLight, size: '1024 x 1024', alpha: false },
        { path: iosDark, size: '1024 x 1024', alpha: false },
        { path: iosTinted, size: '1024 x 1024', alpha: true },
        { path: playStore, size: '512 x 512', alpha: false },
    ];

    const errors = [];
    for (const { path, size, alpha } of checks) {
        const actual = pngSize(path);
        if (actual !== size) {
            errors.push(`${path}: expected ${size}, got ${actual}`);
        }
        const hasAlpha = pngHasAlpha(path);
        if (hasAlpha !== alpha) {
            errors.push(`${path}: alpha=${hasAlpha}, expected ${alpha}`);
        }
    }

    // Strip alpha from iOS Light/Dark if rsvg added it (flatten on background)
    for (const path of [iosLight, iosDark, playStore]) {
        if (pngHasAlpha(path)) {
            execFileSync('convert', [path, '-background', 'none', '-alpha', 'remove', path], { stdio: 'pipe' });
        }
    }

    console.log('Exported:');
    console.log(`  iOS Light:  ${iosLight}`);
    console.log(`  iOS Dark:   ${iosDark}`);
    console.log(`  iOS Tinted: ${iosTinted}`);
    console.log(`  Play Store: ${playStore}`);
    console.log(`  Previews:   ${PREVIEW}/`);

    if (errors.length > 0) {
        console.error('\nValidation warnings (post-flatten may have fixed alpha):');
        errors.forEach((e) => console.error(`  - ${e}`));
    }

    // Re-validate alpha after flatten
    for (const path of [iosLight, iosDark, playStore]) {
        if (pngHasAlpha(path)) {
            throw new Error(`${path} still has alpha after flatten`);
        }
    }

    console.log('\nValidation OK.');
}

exportIcons();
