# Hero-мокап: ноутбук + телефон

Документация по реализации hero-блока с мокапами устройств на лендинге [chatorda.10k.kz/new](https://chatorda.10k.kz/new). Паттерн позволяет показывать интерфейс приложения внутри PNG-рамок ноутбука и iPhone — как через HTML/CSS, так и через реальные скриншоты.

## Структура папки

```
mockup/
├── README.md                 # эта документация
├── frames/
│   ├── macbook-frame.png     # 656×380 px, рамка MacBook
│   └── iphone-frame.png      # 1200×1200 px, рамка iPhone
└── examples/
    └── screenshot-slot.html  # standalone-пример со слотами под скриншоты
```

## Исходники в chatorda

| Файл | Назначение |
|------|------------|
| `chatorda.10k.kz/resources/js/Pages/LandingNew.vue` | Hero-секция, сетка, зелёный декор |
| `chatorda.10k.kz/resources/js/Components/Landing/LandingHeroMockup.vue` | Вся логика мокапа (~1200 строк) |
| `chatorda.10k.kz/resources/js/landing/landingTranslationsNew.ts` | Тексты анимации чата (`heroMockupCopy`) |
| `chatorda.10k.kz/public/png_frames/macbook-frame.png` | Оригинал рамки ноутбука |
| `chatorda.10k.kz/public/png_frames/iphone-frame.png` | Оригинал рамки телефона |

Дубликат компонента для `/final`: `LandingHeroMockupFinal.vue`.

---

## Архитектура: frame-overlay

Каждое устройство — контейнер `.hero-device__shell` с тремя слоями:

```
┌─────────────────────────────────────┐
│  z-index 2: <img class="hero-device__frame">  ← PNG-рамка поверх
├─────────────────────────────────────┤
│  z-index 1: .hero-device__screen    ← контент в прозрачном вырезе
│             └── .hero-device__ui-scale (масштабируемый канвас)
├─────────────────────────────────────┤
│  z-index 1: .hero-device__macbook-base-fill (только MacBook)
│             серая заливка под trackpad hole
└─────────────────────────────────────┘
```

**Ключевая идея:** PNG-рамка идёт в DOM *после* контента, но лежит поверх (`z-index: 2`). Прозрачный вырез в PNG показывает «экран» снизу. Контент позиционируется процентными inset'ами относительно размеров PNG.

---

## Рамки устройств

### MacBook — `frames/macbook-frame.png`

| Параметр | Значение |
|----------|----------|
| Размер PNG | **656 × 380** px |
| Формат | RGBA, прозрачный вырез экрана + trackpad |
| Оригинальный референс | 4608 × 2675 px (экран: 3680 × 2387 px) |
| Inset экрана (CSS) | `top: 0.87%`, `left: 10.27%`, `right: 10.29%`, `bottom: 10.33%` |
| Скругление экрана | `border-radius: 0.82%` |
| Trackpad fill | `width: clamp(48px, 15.5%, 104px)`, `height: clamp(14px, 6.8%, 30px)`, цвет `#b4b9c0` |

Координаты экрана на полном кадре 4608×2675: колонки 464–4143, строки 18–2404 → область **3680 × 2387** px.

### iPhone — `frames/iphone-frame.png`

| Параметр | Значение |
|----------|----------|
| Размер PNG | **1200 × 1200** px |
| Формат | Grayscale + alpha |
| Прозрачный вырез | (386, 164) – (813, 1035) ≈ **427 × 871** px |
| Inset экрана (CSS) | `top: 13.67%`, `bottom: 13.67%`, `left: 32.17%`, `right: 32.17%` |
| Скругление экрана | `border-radius: 12.85% / 6.31%` |

---

## Масштабирование контента

Экран-контейнер: `container-type: size` + `overflow: hidden`.

Внутри — фиксированный «дизайн-канвас» `.hero-device__ui-scale`, который масштабируется через CSS container queries:

```css
.hero-device__ui-scale--macbook {
  width: 860px;
  height: calc(860px * 2387 / 3680); /* ≈ 559px */
  transform-origin: 0 0;
  transform: scale(calc(100cqw / 860px));
}

.hero-device__ui-scale--iphone {
  width: 248px;
  height: calc(248px * 872 / 428); /* ≈ 506px */
  transform-origin: 0 0;
  transform: scale(calc(100cqw / 248px));
}
```

| Устройство | Базовая ширина канваса | Соотношение сторон экрана |
|------------|------------------------|---------------------------|
| MacBook | 860 px | 3680 : 2387 |
| iPhone | 248 px | 428 : 872 |

---

## Три режима отображения

### 1. Узкий мобильный (< 700px или viewport height < 480px)

- Показывается только `.hero-device--mobile` (один iPhone)
- Desktop-блок `.hero-mockup-devices--desktop` скрыт
- Ширина телефона: `max-width: min(300px, 88vw)`; ниже 359px: `min(252px, 92vw)`

### 2. Планшет / широкий мобильный (≥ 700px width AND ≥ 480px height)

- MacBook + companion phone (телефон поверх правого нижнего угла ноута)
- Корневой блок масштабируется: `transform: scale(0.84)` на 700–1023px
- Companion phone:
  - `position: absolute`, `z-index: 5`
  - `right: clamp(-6.5rem, -14%, -0.5rem)`
  - `bottom: clamp(-4.95rem, -3.7vw, -0.56rem)`
  - `width: clamp(232px, 25.5vw, 338px)`
  - `max-width: min(56%, 352px)`
  - `filter: drop-shadow(0 14px 30px rgb(0 0 0 / 0.28))`

### 3. Десктоп (≥ 1024px)

- Тот же MacBook + companion, полный масштаб
- `.hero-mockup-root`: `max-width: min(2200px, 99vw)`

---

## Обёртка hero на странице

Из `LandingNew.vue`:

```css
.hero-section {
  min-height: 100svh;
  background: var(--brand-green-section);
  overflow: hidden;
}

.hero-layout {
  display: grid;
  gap: 2.5rem;
}

/* ≥ 1024px: 2 колонки */
@media (min-width: 1024px) {
  .hero-layout {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: center;
  }
}

/* ≥ 1280px: текст уже, визуал шире */
@media (min-width: 1280px) {
  .hero-layout {
    grid-template-columns: minmax(0, 0.78fr) minmax(0, 1.52fr);
  }
}

.hero-green-shape {
  position: absolute;
  inset: 2rem -1.5rem -1.25rem 4rem;
  border-radius: 36px;
  transform: rotate(-4deg);
}

.hero-mockup-root {
  transform: rotate(0.9deg);
  max-width: min(1720px, 99vw);
}

.hero-mockup-root:hover {
  transform: rotate(0.35deg) translateY(-3px);
}
```

---

## Как сделать то же с реальными скриншотами

### Шаг 1: Подготовить скриншоты

| Устройство | Рекомендуемое соотношение | Пример размера |
|------------|---------------------------|----------------|
| MacBook (desktop) | **3680 : 2387** | 1840 × 1194 px (2×) или 3680 × 2387 px |
| iPhone (mobile) | **428 : 872** | 428 × 872 px или 856 × 1744 px (2×) |

Для iPhone учтите Dynamic Island: в HTML-версии сверху есть `.mockup-phone-isle-gap` (~13.5% высоты). В скриншоте либо оставьте отступ сверху, либо обрежьте статус-бар.

### Шаг 2: Заменить HTML-mockup на `<img>`

Вместо `.mockup-browser` с разметкой чата:

```html
<div class="hero-device__screen hero-device__screen--macbook">
  <div class="hero-device__ui-scale hero-device__ui-scale--macbook">
    <img
      src="/screenshots/desktop-app.png"
      alt="Интерфейс приложения"
      width="3680"
      height="2387"
      style="display:block; width:100%; height:100%; object-fit:cover; object-position:top left"
    />
  </div>
</div>
```

Для iPhone:

```html
<div class="hero-device__screen hero-device__screen--iphone">
  <div class="hero-device__ui-scale hero-device__ui-scale--iphone">
    <img
      src="/screenshots/mobile-app.png"
      alt="Мобильный интерфейс"
      width="428"
      height="872"
      style="display:block; width:100%; height:100%; object-fit:cover; object-position:top left"
    />
  </div>
</div>
```

### Шаг 3: Настроить базовую ширину канваса

Если скриншот в полном разрешении, обновите CSS:

```css
/* Для скриншота 3680×2387 */
.hero-device__ui-scale--macbook {
  width: 3680px;
  height: 2387px;
  transform: scale(calc(100cqw / 3680px));
}

/* Для скриншота 428×872 */
.hero-device__ui-scale--iphone {
  width: 428px;
  height: 872px;
  transform: scale(calc(100cqw / 428px));
}
```

### Упрощённая альтернатива (без container queries)

Уберите `.hero-device__ui-scale` и положите картинку прямо в `.hero-device__screen`:

```html
<div class="hero-device__screen hero-device__screen--macbook">
  <img src="/screenshots/desktop-app.png" alt="" style="width:100%; height:100%; object-fit:cover" />
</div>
```

Проще в реализации, но менее гибко при разных DPI и анимациях.

---

## Чеклист переноса в другой проект

1. Скопировать `frames/macbook-frame.png` и `frames/iphone-frame.png` в `public/` целевого проекта
2. Перенести CSS-классы:
   - `.hero-device__shell`
   - `.hero-device__screen`, `.hero-device__screen--macbook`, `.hero-device__screen--iphone`
   - `.hero-device__frame`
   - `.hero-device__ui-scale--macbook`, `.hero-device__ui-scale--iphone`
   - `.hero-device__macbook-base-fill`
   - `.hero-device--companion-overlap` (позиционирование телефона)
3. Заменить HTML-mockup на `<img>` со скриншотами (или оставить HTML для анимации)
4. Настроить breakpoints: 700px (desktop mockup), 1024px (полный масштаб)
5. Проверить мобильный layout: только phone, без ноутбука
6. Открыть `examples/screenshot-slot.html` в браузере для быстрой проверки рамок

---

## Минимальный HTML DOM

```html
<div class="hero-mockup-root">
  <!-- Desktop: MacBook + companion phone -->
  <div class="hero-mockup-devices hero-mockup-devices--desktop">
    <div class="hero-device hero-device--desktop">
      <div class="hero-device__shell hero-device__shell--macbook">
        <div class="hero-device__screen hero-device__screen--macbook">
          <div class="hero-device__ui-scale hero-device__ui-scale--macbook">
            <!-- контент или <img> -->
          </div>
        </div>
        <div class="hero-device__macbook-base-fill" aria-hidden="true"></div>
        <img class="hero-device__frame" src="/frames/macbook-frame.png" width="656" height="380" alt="" />
      </div>

      <div class="hero-device hero-device--companion hero-device--companion-overlap">
        <div class="hero-device__shell hero-device__shell--companion">
          <div class="hero-device__screen hero-device__screen--iphone">
            <div class="hero-device__ui-scale hero-device__ui-scale--iphone">
              <!-- контент или <img> -->
            </div>
          </div>
          <img class="hero-device__frame" src="/frames/iphone-frame.png" width="1200" height="1200" alt="" />
        </div>
      </div>
    </div>
  </div>

  <!-- Mobile: только iPhone -->
  <div class="hero-device hero-device--mobile">
    <div class="hero-device__shell hero-device__shell--phone">
      <div class="hero-device__screen hero-device__screen--iphone">
        <div class="hero-device__ui-scale hero-device__ui-scale--iphone">
          <!-- контент или <img> -->
        </div>
      </div>
      <img class="hero-device__frame" src="/frames/iphone-frame.png" width="1200" height="1200" alt="" />
    </div>
  </div>
</div>
```

---

## Быстрый тест

Откройте в браузере:

```
mockup/examples/screenshot-slot.html
```

Файл содержит рабочий пример с placeholder-скриншотами и всеми CSS inset'ами. Замените пути к картинкам на свои скриншоты.
