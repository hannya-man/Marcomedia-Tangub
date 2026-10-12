<?php
namespace App\Services\Inventory;

use Illuminate\Support\Collection;

/**
 * Fixed yields for boards and rolls. Every size here is in inches.
 *
 *   perSheet()  How many w x h pieces one W x H board gives, cut in a grid, whichever way round
 *               fits more: one 48 x 96 in board gives 35 pieces of 8.5 x 13 in.
 *   options()   The standard pieces a w x h job can be charged as, best first. On a board it takes
 *               one piece it fits in; on a roll, whole feet of a roll it fits across. Best = the
 *               least board or roll used, so a custom size is charged as the nearest piece up.
 */
final class SheetYield
{
    private const EPSILON = 0.001;

    public static function perSheet(float $sheetW, float $sheetH, float $w, float $h): int
    {
        if ($w <= 0 || $h <= 0) {
            return 0;
        }
        $fit = fn (float $length, float $piece) => (int) floor($length / $piece + 1e-9);

        return max($fit($sheetW, $w) * $fit($sheetH, $h), $fit($sheetW, $h) * $fit($sheetH, $w));
    }

    /**
     * @param Collection $pieces cut-size materials, each with its board or roll loaded (cutFrom)
     * @return array<int, array{piece: \App\Models\Material, blocks: int, area: float}>
     */
    public static function options(Collection $pieces, float $w, float $h): array
    {
        $options = [];
        foreach ($pieces as $piece) {
            $bw = (float) $piece->cut_width_in;
            $bh = (float) $piece->cut_height_in;
            $blocks = $piece->cutFrom && $piece->cutFrom->isRoll()
                ? self::rollBlocks($bw, $bh, $w, $h)
                : (self::fits($w, $h, $bw, $bh) ? 1 : null);
            if ($blocks !== null) {
                $options[] = ['piece' => $piece, 'blocks' => $blocks, 'area' => $blocks * $bw * $bh];
            }
        }

        // Least area used; then fewer pieces; then the order they were listed in.
        usort($options, fn ($a, $b) => [round($a['area'], 3), $a['blocks'], $a['piece']->id] <=> [round($b['area'], 3), $b['blocks'], $b['piece']->id]);

        return $options;
    }

    /**
     * Of options() (best first), the first the store can supply for $units items, else the best
     * one at all. $available: piece id => pieces the store can supply (BatchInventoryService::cutAvailable).
     *
     * @return array{piece: \App\Models\Material, blocks: int, area: float, available: int}|null
     */
    public static function best(array $options, array $available, int $units = 1): ?array
    {
        foreach ($options as $i => $option) {
            $options[$i]['available'] = (int) ($available[$option['piece']->id] ?? 0);
            if ($options[$i]['available'] >= $option['blocks'] * max(1, $units)) {
                return $options[$i];
            }
        }

        return $options[0] ?? null;
    }

    // "10 x 12 in", or "4.5 x 7 ft" for sizes of 2 ft and up in half feet.
    public static function label(float $w, float $h): string
    {
        $halfFeet = fn ($in) => $in >= 24 - self::EPSILON && abs($in / 6 - round($in / 6)) < self::EPSILON;
        $num = fn ($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

        return $halfFeet($w) && $halfFeet($h)
            ? $num($w / 12) . ' x ' . $num($h / 12) . ' ft'
            : $num($w) . ' x ' . $num($h) . ' in';
    }

    public static function fits(float $w, float $h, float $bw, float $bh): bool
    {
        return ($w <= $bw + self::EPSILON && $h <= $bh + self::EPSILON)
            || ($w <= $bh + self::EPSILON && $h <= $bw + self::EPSILON);
    }

    // Feet of a roll a w x h job takes. Its long side goes across the roll if it fits, so it uses
    // the least length. Null if the job is wider than the roll both ways round.
    private static function rollBlocks(float $across, float $blockLength, float $w, float $h): ?int
    {
        $long = max($w, $h);
        $short = min($w, $h);
        if ($long <= $across + self::EPSILON) {
            $along = $short;
        } elseif ($short <= $across + self::EPSILON) {
            $along = $long;
        } else {
            return null;
        }

        return max(1, (int) ceil($along / $blockLength - self::EPSILON));
    }
}
