---
name: frontend_skill
description: Create distinctive, production-grade frontend interfaces with high design quality. Use when building web components, pages, or applications in this project. Generates creative, polished code that avoids generic AI aesthetics.
---

This skill guides creation of distinctive, production-grade frontend interfaces that avoid generic "AI slop" aesthetics. Implement real working code with exceptional attention to aesthetic details and creative choices.

The user provides frontend requirements: a component, page, application, or interface to build. They may include context about the purpose, audience, or technical constraints.

## Design Thinking

Before coding, understand the context and commit to a BOLD aesthetic direction:
- **Purpose**: What problem does this interface solve? Who uses it?
- **Tone**: Pick an extreme: brutally minimal, maximalist chaos, retro-futuristic, organic/natural, luxury/refined, playful/toy-like, editorial/magazine, brutalist/raw, art deco/geometric, soft/pastel, industrial/utilitarian, etc. There are so many flavors to choose from. Use these for inspiration but design one that is true to the aesthetic direction.
- **Constraints**: Technical requirements (framework, performance, accessibility).
- **Differentiation**: What makes this UNFORGETTABLE? What's the one thing someone will remember?

**CRITICAL**: Choose a clear conceptual direction and execute it with precision. Bold maximalism and refined minimalism both work - the key is intentionality, not intensity.

Then implement working code (HTML/CSS/JS, React, Vue, etc.) that is:
- Production-grade and functional
- Visually striking and memorable
- Cohesive with a clear aesthetic point-of-view
- Meticulously refined in every detail

## Frontend Aesthetics Guidelines

Focus on:
- **Typography**: Choose fonts that are beautiful, unique, and interesting. Avoid generic fonts like Arial and Inter; opt instead for distinctive choices that elevate the frontend's aesthetics; unexpected, characterful font choices. Pair a distinctive display font with a refined body font.
- **Color & Theme**: Commit to a cohesive aesthetic. Use CSS variables for consistency. Dominant colors with sharp accents outperform timid, evenly-distributed palettes.
- **Motion**: Use animations for effects and micro-interactions. Prioritize CSS-only solutions for HTML. Use Motion library for React when available. Focus on high-impact moments: one well-orchestrated page load with staggered reveals (animation-delay) creates more delight than scattered micro-interactions. Use scroll-triggering and hover states that surprise.
- **Spatial Composition**: Unexpected layouts. Asymmetry. Overlap. Diagonal flow. Grid-breaking elements. Generous negative space OR controlled density.
- **Backgrounds & Visual Details**: Create atmosphere and depth rather than defaulting to solid colors. Add contextual effects and textures that match the overall aesthetic. Apply creative forms like gradient meshes, noise textures, geometric patterns, layered transparencies, dramatic shadows, decorative borders, custom cursors, and grain overlays.

NEVER use generic AI-generated aesthetics like overused font families (Inter, Roboto, Arial, system fonts), cliched color schemes (particularly purple gradients on white backgrounds), predictable layouts and component patterns, and cookie-cutter design that lacks context-specific character.

Interpret creatively and make unexpected choices that feel genuinely designed for the context. No design should be the same. Vary between light and dark themes, different fonts, different aesthetics. NEVER converge on common choices (Space Grotesk, for example) across generations.

**IMPORTANT**: Match implementation complexity to the aesthetic vision. Maximalist designs need elaborate code with extensive animations and effects. Minimalist or refined designs need restraint, precision, and careful attention to spacing, typography, and subtle details. Elegance comes from executing the vision well.

Remember: Claude is capable of extraordinary creative work. Don't hold back, show what can truly be created when thinking outside the box and committing fully to a distinctive vision.

## ChatSwitch conventions

When working in this repo, also follow existing product UI patterns unless the user explicitly asks for a redesign:

- Vue 3 + Inertia; use `--wa-*` CSS variables and WhatsApp-like panel layouts where appropriate.
- Prefer `useToastStore` from `@/stores/toast` over `alert()`.
- Resizable side panels: `useResizablePanelWidth` + `PanelResizeHandle` (see `ChatLayout.vue`, `OrganizationLayout.vue`).
- Run `npm run build` after substantive Vue/TS changes.

### Light vs dark chroma

Colored badges, pills, and tinted surfaces must stay **theme-aware**:

- **Dark theme:** pale tints are correct — use `--wa-chroma-*` tokens (blend base = `transparent`).
- **Light theme:** do **not** reuse the same `color-mix(..., transparent)` — tints disappear on white. Use `--wa-chroma-*` from `resources/css/app.css` (blend base = `var(--wa-panel)`) or `--wa-accent-soft` / `--wa-chroma-accent-fg` for readable text.
- Never hardcode `#ef4444 18%, transparent` for status pills; use `--wa-chroma-critical-bg` / `--wa-chroma-critical-fg`, etc.

### Unified UI primitives (`resources/css/ui-primitives.css`)

One visual language for chat sidebars and settings. **Do not** invent new tab/chip/button styles per page.

| Pattern | Classes | Use in |
|--------|---------|--------|
| Top tabs | `.ui-section-tabs`, `.ui-section-tab`, `.is-active` | `SidebarSectionTabs` |
| Underline segments | `.ui-segment-row`, `.ui-segment`, `.ui-segment--danger` | `ChatSidebar` (Активные/Архив, Все/Мои) |
| Segmented control | `.ui-pill-nav`, `.ui-pill-nav__item`, `.is-active` | Org: Задачи/Чат, Беседы/Сотрудники |
| Filter chips | `.ui-chip-row`, `.ui-chip`, `.is-active` | Team chat filters, KB |
| Search | `.ui-search-shell`, `.ui-search-input`, `--boxed` variant | Sidebars |
| Settings filter | `.ui-filter-panel`, `.ui-input` / `.settings-input` | Users, Departments |
| Buttons | `.ui-btn`, `--primary`, `--secondary`, `--ghost`, `--danger`, `--danger-ghost`, `--sm` | Settings actions |
| Badges | `.ui-badge`, `--admin`, `--manager`, `--employee`, `--neutral` | Tables, lists |
| Cards/tables | `.ui-panel`, `.ui-table-panel` | Settings tables |
| Settings sections | `.ui-settings-section`, `--narrow` | System, KB forms, Tone, Onboarding |
| Alerts / empty | `.ui-alert`, `--warn`, `--danger`, `.ui-empty-state`, `--dashed` | Connections, AiQuality |
| Toggles | `.ui-toggle-group`, `.ui-toggle-btn`, `.is-active` | Funnel AI wizard |
| Tool pages | `.ui-tool-page`, `.ui-result-card` | ИИ чат, calendar shell |
| Calendar view | `.ui-pill-nav` on toolbar | `Calendar/Index` Месяц/Неделя |
| Tool list pages | `.ui-tool-list-page`, `__header`, `__search-input` | `Contacts/Index` |
| Checkbox rows | `.ui-check-row` | KB form «Публикация» |
| Pill buttons | `.ui-btn--pill`, `--accent-soft` | Channels, Status empty states |

Tokens: `--primitive-radius-md` (10px), `--primitive-control-h` (40px), `--primitive-gap-md` (12px). Match these before adding custom padding/radius.

### Page backgrounds

- **Chat canvas** (`--wa-bg`): parchment in light theme — only inside chat message areas (`.chat-bg`).
- **App shell / tools** (`--wa-page-bg`): neutral gray `#F0F2F5` in light — use for `AuthenticatedLayout`, settings, broadcasts, calendar, analytics. Wrap tool pages in `.app-page` > `.app-page__scroll` > `.app-page__content`.
- Never use `--wa-bg` for full-page tool UIs; it reads as accidental beige.
