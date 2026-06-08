# Accel App Icon — «Диалог»

Production-пакет иконки мобильного приложения Accel (`com.accel.mobile`).

**Концепция:** два симметричных chevron навстречу — диалог, коммуникация (не «нейронка»).

## Палитра

| Token | Hex | Где |
|-------|-----|-----|
| `accent` | `#01B964` | chevrons на Light |
| `accent-bright` | `#25D366` | chevrons на Dark (+ лёгкий glow) |
| `bg-light` | `#F2F4F6` | iOS Light / Android bg (не чистый белый) |
| `bg-dark` | `#0B141A` | iOS Dark / Android bg-night |
| `overlap` | `#4ADE80` @ 40% | центр пересечения chevrons |

## Структура

```
brand/app-icon/
├── source/           # SVG — source of truth (108×108 viewBox)
├── ios/              # 3 × PNG 1024 для Xcode 16+
├── android/          # VectorDrawable + Play Store 512
├── preview/          # QA-превью 60 px / 1024 px (генерируются)
└── scripts/
    └── export-app-icon.mjs
```

## iOS 18+ (Xcode 16)

Загрузить в `AppIcon.appiconset` три слота **1024×1024**:

| Файл | Слот | Alpha |
|------|------|-------|
| `ios/AppIcon-light@1024.png` | Light (Any) | Нет |
| `ios/AppIcon-dark@1024.png` | Dark | Нет |
| `ios/AppIcon-tinted@1024.png` | Tinted | **Да** (grayscale-маска на прозрачном) |

**Xcode:** Assets → AppIcon → iOS → включить «All Appearances» → перетащить Light / Dark / Tinted.

**Tinted:** система iOS подкладывает фон и красит силуэт в цвет пользователя. Маска использует градиент opacity (100% в центре → 65% по краям) для объёма.

## Android

### Google Play

| Файл | Размер | Примечание |
|------|--------|------------|
| `android/play-store-512.png` | 512×512 | Плоский квадрат, без скруглений |

### Adaptive Icon (в приложении)

| Файл | Куда в Flutter/Android project |
|------|--------------------------------|
| `android/ic_launcher_foreground.xml` | `android/app/src/main/res/drawable/ic_launcher_foreground.xml` |
| `android/ic_launcher_background.xml` | `res/drawable/ic_launcher_background.xml` (light) |
| `android/ic_launcher_background_night.xml` | `res/drawable-night/ic_launcher_background.xml` (dark) |
| `android/ic_launcher_monochrome.xml` | `res/drawable/ic_launcher_monochrome.xml` |

**Safe zone:** логотип вписан в круг диаметром **66 dp** (viewport 108×108, отступ 21 dp).

**`AndroidManifest.xml` / adaptive icon:**

```xml
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@drawable/ic_launcher_background"/>
    <foreground android:drawable="@drawable/ic_launcher_foreground"/>
    <monochrome android:drawable="@drawable/ic_launcher_monochrome"/>
</adaptive-icon>
```

### Flutter (`flutter_launcher_icons`)

Пример в `pubspec.yaml`:

```yaml
flutter_launcher_icons:
  android: true
  ios: true
  image_path: "brand/app-icon/ios/AppIcon-light@1024.png"
  adaptive_icon_background: "#F2F4F6"
  adaptive_icon_foreground: "brand/app-icon/android/ic_launcher_foreground.xml"
  # iOS 18 appearances — загрузить Dark/Tinted вручную в Xcode после генерации
```

Для iOS Dark/Tinted и Android night background — ручная настройка или отдельный CI-шаг.

## Пересборка PNG из SVG

Требуется: `rsvg-convert` (librsvg), ImageMagick (`convert`, `identify`).

```bash
node brand/app-icon/scripts/export-app-icon.mjs
```

Скрипт генерирует iOS PNG, Play Store 512 и превью в `preview/`.

## QA чеклист

- [ ] **60 px:** chevrons читаются на home screen (`preview/preview-light-60.png`)
- [ ] **Light:** фон `#F2F4F6`, не сливается со светлыми обоями iOS
- [ ] **Dark:** chevrons `#25D366` видны на `#0B141A` и чёрных обоях
- [ ] **Tinted iOS:** маска с прозрачностью; проверить с зелёным и синим tint в Settings → Appearance
- [ ] **Android safe zone:** при parallax обрезки chevrons не обрезаются
- [ ] **Monochrome Android:** Material You themed icon — силуэт без артефактов
- [ ] **Play Store:** 512×512, без скруглений, без alpha

## Редактирование

Менять геометрию только в `source/*.svg` (координаты path), затем:

1. Обновить pathData в `android/ic_launcher_*.xml` (если менялась форма)
2. Запустить `node brand/app-icon/scripts/export-app-icon.mjs`

Исходные path chevrons (viewport 108):

```
Left:  M32,28 L51,54 L32,80 L38,80 L55,54 L38,28 Z
Right: M76,28 L57,54 L76,80 L70,80 L53,54 L70,28 Z
```
