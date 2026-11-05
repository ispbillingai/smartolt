<?php
/** Counter deltas, with resets and ambiguous 32-bit wraps left as gaps. */
class SnmpRates
{
    public static function calculate(array $current, array $previous, float $elapsed, bool $restarted): array
    {
        $rates = ['rx_bps' => null, 'tx_bps' => null];
        if ($restarted || $elapsed <= 0 || $elapsed > 900 || !$previous
            || ($current['name'] ?? null) !== ($previous['name'] ?? null)
            || $current['counter_bits'] !== $previous['counter_bits']
            || ($current['counter_discontinuity'] ?? null) !== ($previous['counter_discontinuity'] ?? null)) return $rates;
        foreach (['rx_bps' => 'in_octets', 'tx_bps' => 'out_octets'] as $rate => $counter) {
            $new = $current[$counter] ?? null; $old = $previous[$counter] ?? null;
            if ($new === null || $old === null || !ctype_digit((string)$new) || !ctype_digit((string)$old)) continue;
            // Decimal subtraction avoids precision loss for large Counter64 values without BCMath.
            $a = str_pad((string)$new, 20, '0', STR_PAD_LEFT);
            $b = str_pad((string)$old, 20, '0', STR_PAD_LEFT);
            if (strcmp($a, $b) < 0) continue;
            $borrow = 0; $digits = '';
            for ($i = 19; $i >= 0; $i--) {
                $d = (int)$a[$i] - (int)$b[$i] - $borrow;
                $borrow = $d < 0 ? 1 : 0;
                $digits = (string)($d < 0 ? $d + 10 : $d) . $digits;
            }
            $speed = (float)($current['speed_bps'] ?? 0);
            if ((int)$current['counter_bits'] === 32 && ($speed <= 0 || $speed * $elapsed / 8 >= 4294967296)) continue;
            $bps = (float)$digits * 8 / $elapsed;
            if ($speed > 0 && $bps > $speed * 1.05) continue;
            $rates[$rate] = $bps;
        }
        return $rates;
    }
}
