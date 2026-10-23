<?php

namespace Database\Seeders;

use App\Enums\EquipmentAudience;
use App\Enums\EquipmentKind;
use App\Models\EquipmentItem;
use GdImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sample items for the equipment shop, so it can be seen (the owner asked, 2026-10-23, for the
 * pilot). Every name starts with "SAMPLE " so they're easy to spot and to remove; the prices are
 * mine, for looks only (Accounting sets the real ones). Each gets a plain drawn picture.
 *
 * Safe to run again: an item already there (by name) is left alone, except that its drawn sample
 * picture is redrawn (a photo Admin uploaded instead is never touched).
 *   php artisan db:seed --class=EquipmentSampleSeeder
 */
class EquipmentSampleSeeder extends Seeder
{
    public const PREFIX = 'SAMPLE ';

    /** [name, kind, audience, sizes, price, [size => own price], picture colour] */
    private const ITEMS = [
        ['Guard polo (Type A)', EquipmentKind::Uniform, EquipmentAudience::Guards, ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'], 450.0, ['3XL' => 520.0], [30, 58, 138]],
        ['Guard trousers', EquipmentKind::Uniform, EquipmentAudience::Guards, ['28', '30', '32', '34', '36', '38', '40'], 550.0, ['38' => 600.0, '40' => 600.0], [31, 41, 55]],
        ['Combat boots', EquipmentKind::Uniform, EquipmentAudience::Guards, ['38', '39', '40', '41', '42', '43', '44', '45', '46'], 1500.0, ['44' => 1650.0, '45' => 1650.0, '46' => 1650.0], [68, 44, 30]],
        ['Ball cap with patch', EquipmentKind::Uniform, EquipmentAudience::Guards, ['Free size'], 180.0, [], [23, 37, 84]],
        ['Raincoat', EquipmentKind::Gear, EquipmentAudience::Guards, ['M', 'L', 'XL'], 650.0, [], [161, 98, 7]],
        ['Flashlight', EquipmentKind::Gear, EquipmentAudience::Guards, [], 800.0, [], [55, 65, 81]],
        ['Nightstick', EquipmentKind::Gear, EquipmentAudience::Guards, [], 350.0, [], [17, 24, 39]],
        ['Whistle and lanyard', EquipmentKind::Gear, EquipmentAudience::All, [], 60.0, [], [180, 83, 9]],
        ['Office polo', EquipmentKind::Uniform, EquipmentAudience::Office, ['XS', 'S', 'M', 'L', 'XL', '2XL'], 500.0, [], [37, 99, 235]],
        ['Company ID lace', EquipmentKind::Gear, EquipmentAudience::Office, [], 45.0, [], [79, 70, 229]],
    ];

    public function run(): void
    {
        $disk = Storage::disk((string) config('attendance.photo_disk'));
        $added = 0;
        $redrawn = 0;

        foreach (self::ITEMS as [$name, $kind, $audience, $sizes, $price, $sizePrices, $colour]) {
            $label = $name;
            $name = self::PREFIX.$name;
            $existing = EquipmentItem::query()->where('name', $name)->first();

            if ($existing !== null) {
                // Already there: only its drawn sample picture is redrawn (never a photo Admin uploaded).
                // A new file name, so the photo URL (versioned by its path) changes and browsers fetch it.
                if ($existing->photo_path !== null && str_starts_with($existing->photo_path, "equipment/{$existing->id}/sample-")) {
                    $old = $existing->photo_path;
                    $path = 'equipment/'.$existing->id.'/sample-'.Str::random(12).'.png';
                    $disk->put($path, self::picture($label, $colour));
                    $existing->forceFill(['photo_path' => $path])->save();
                    $disk->delete($old);
                    $redrawn++;
                }

                continue;
            }

            $item = new EquipmentItem;
            $item->forceFill([
                'name' => $name,
                'kind' => $kind,
                'audience' => $audience,
                'unit_price' => number_format($price, 2, '.', ''),
                'sizes' => $sizes === [] ? null : array_map(fn (string $size) => ['size' => $size, 'price' => $sizePrices[$size] ?? null], $sizes),
                'is_active' => true,
            ])->save();

            // A random name: the dev database and the pilot share this folder, and their ids overlap.
            $path = 'equipment/'.$item->id.'/sample-'.Str::random(12).'.png';
            $disk->put($path, self::picture(substr($name, strlen(self::PREFIX)), $colour));
            $item->forceFill(['photo_path' => $path])->save();
            $added++;
        }

        $this->command->info("Added {$added} sample equipment item(s); redrew {$redrawn} sample picture(s).");
    }

    /**
     * A plain 800 × 600 card: the item's colour, its name in large letters, and a "SAMPLE" tag.
     *
     * @param  array{int, int, int}  $colour
     */
    private static function picture(string $label, array $colour): string
    {
        $image = imagecreatetruecolor(800, 600);
        [$r, $g, $b] = $colour;

        // A soft two-tone background: the colour, lighter towards the bottom.
        for ($y = 0; $y < 600; $y++) {
            $mix = $y / 600 * 0.35;
            $shade = (int) imagecolorallocate($image, self::channel($r + (255 - $r) * $mix), self::channel($g + (255 - $g) * $mix), self::channel($b + (255 - $b) * $mix));
            imageline($image, 0, $y, 799, $y, $shade);
        }

        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $font = self::font();

        if ($font !== null) {
            self::centred($image, $font, 46, $label, 290, $white);
            imagettftext($image, 20, 0, 32, 560, $white, $font, 'SAMPLE');
        } else {
            // No TrueType font on this machine: GD's built-in font, drawn small and scaled up.
            $small = imagecreatetruecolor(200, 30);
            imagefill($small, 0, 0, (int) imagecolorallocatealpha($small, 0, 0, 0, 127));
            imagesavealpha($small, true);
            imagestring($small, 5, max(0, intdiv(200 - strlen($label) * 9, 2)), 7, $label, (int) imagecolorallocate($small, 255, 255, 255));
            imagecopyresampled($image, $small, 0, 240, 0, 0, 800, 120, 200, 30);
            imagestring($image, 5, 32, 550, 'SAMPLE', $white);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * The label centred on the card. One line when it fits; otherwise two, split between words where
     * the lines come out most even ("Guard polo" / "(Type A)", never "(Type" / "A)"). The size
     * shrinks if a line is still too wide. The block is centred on $y.
     */
    private static function centred(GdImage $image, string $font, int $size, string $text, int $y, int $colour): void
    {
        $maxWidth = 680;
        $lines = self::width($font, $size, $text) <= $maxWidth ? [$text] : self::balancedLines($font, $size, $text);

        while ($size > 26 && max([0, ...array_map(fn (string $line) => self::width($font, $size, $line), $lines)]) > $maxWidth) {
            $size -= 2;
        }

        $lineHeight = (int) round($size * 1.35);
        $baseline = (int) round($y - (count($lines) * $lineHeight) / 2 + $size); // the first line's baseline

        foreach ($lines as $index => $line) {
            imagettftext($image, $size, 0, intdiv(800 - self::width($font, $size, $line), 2), $baseline + $index * $lineHeight, $colour, $font, $line);
        }
    }

    /**
     * Two lines, split at the word break that makes the wider line as narrow as possible.
     *
     * @return list<string>
     */
    private static function balancedLines(string $font, int $size, string $text): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [$text];

        if (count($words) < 2) {
            return [$text];
        }

        $best = [$text];
        $bestWidth = PHP_INT_MAX;

        for ($split = 1; $split < count($words); $split++) {
            $lines = [implode(' ', array_slice($words, 0, $split)), implode(' ', array_slice($words, $split))];
            $widest = max(self::width($font, $size, $lines[0]), self::width($font, $size, $lines[1]));

            if ($widest < $bestWidth) {
                [$best, $bestWidth] = [$lines, $widest];
            }
        }

        return $best;
    }

    private static function width(string $font, int $size, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : $box[2] - $box[0];
    }

    /** @return int<0, 255> */
    private static function channel(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    /** A bold system font, if this machine has one (Windows, or common Linux paths). */
    private static function font(): ?string
    {
        foreach (['C:/Windows/Fonts/segoeuib.ttf', 'C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
