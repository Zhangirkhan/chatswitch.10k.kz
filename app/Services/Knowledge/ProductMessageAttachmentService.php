<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Chat;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ProductMessageAttachmentService
{
    private const MARKER_PATTERN = '/(?:\r?\n)?PRODUCT_ATTACH:(\d+)\s*$/u';

    public function findForChat(Chat $chat, int $productId): ?Product
    {
        $companyId = $chat->company_id;
        if ($companyId === null) {
            return null;
        }

        return Product::query()
            ->whereKey($productId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForChat(Chat $chat, ?string $search = null, int $limit = 80): array
    {
        $companyId = $chat->company_id;
        if ($companyId === null) {
            return [];
        }

        $query = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        $term = trim((string) $search);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        return $query
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->map(fn (Product $product): array => $this->pickerItem($product))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'price' => $product->price !== null ? (string) $product->price : null,
            'price_formatted' => $this->formatPrice($product->price),
            'attributes' => $product->attributes,
            'image_path' => $product->image_path,
            'image_url' => $product->image_path
                ? Storage::disk('public')->url($product->image_path)
                : null,
        ];
    }

    /**
     * @return array{reply: string, product_id: int|null}
     */
    public function stripAttachMarker(string $reply): array
    {
        if (! preg_match(self::MARKER_PATTERN, $reply, $matches)) {
            return ['reply' => trim($reply), 'product_id' => null];
        }

        $productId = (int) ($matches[1] ?? 0);
        $clean = trim((string) preg_replace(self::MARKER_PATTERN, '', $reply));

        return [
            'reply' => $clean,
            'product_id' => $productId > 0 ? $productId : null,
        ];
    }

    public function promptInstruction(): string
    {
        return <<<'TEXT'
14. Если в ответе клиенту вы рекомендуете один конкретный товар из каталога (строки с [id=N]), добавьте в самом конце ответа отдельной строкой: PRODUCT_ATTACH:N (только эту строку, без пояснений). Если товар не рекомендуете или данных нет — не добавляйте эту строку.
TEXT;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public function whatsappCaptionFallback(array $product): string
    {
        $name = trim((string) ($product['name'] ?? ''));
        $price = trim((string) ($product['price_formatted'] ?? ''));

        if ($name === '' && $price === '') {
            return '';
        }

        if ($price !== '') {
            return $name !== '' ? "{$name} — {$price}" : $price;
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public function appendToWhatsappBody(string $body, array $product): string
    {
        $footer = $this->whatsappCaptionFallback($product);
        if ($footer === '') {
            return $body;
        }

        $body = trim($body);
        if ($body === '') {
            return $footer;
        }

        if (str_contains(mb_strtolower($body), mb_strtolower($footer))) {
            return $body;
        }

        return $body."\n\n".$footer;
    }

    public function publicImageMimeType(string $imagePath): string
    {
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function pickerItem(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description !== null
                ? Str::limit((string) $product->description, 160)
                : null,
            'price' => $product->price !== null ? (string) $product->price : null,
            'price_formatted' => $this->formatPrice($product->price),
            'image_url' => $product->image_path
                ? Storage::disk('public')->url($product->image_path)
                : null,
        ];
    }

    private function formatPrice(mixed $price): ?string
    {
        if ($price === null || $price === '') {
            return null;
        }

        $amount = is_numeric($price) ? (float) $price : 0.0;
        $formatted = number_format($amount, (float) $amount === floor($amount) ? 0 : 2, ',', ' ');

        return "{$formatted} ₸";
    }
}
